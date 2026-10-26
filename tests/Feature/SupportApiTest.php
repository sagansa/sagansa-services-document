<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\LetterType;
use App\Models\Party;
use App\Models\Signer;
use App\Models\Template;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Task 14 — API pendukung wizard: upload/hapus lampiran surat, buku pihak
 * (parties), daftar jenis surat aktif, dan template per jenis surat.
 */
class SupportApiTest extends TestCase
{
    use CreatesAuthSchema, CreatesDocumentSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthSchema();
        $this->createDocumentSchema();
        Storage::fake('documents');
        $this->user = User::factory()->create();
    }

    #[Test]
    public function upload_attachment_stores_file_row_and_audit(): void
    {
        $this->signInAsSigner();
        $type = LetterType::factory()->create(['code' => 'ST']);
        $document = Document::factory()->create([
            'letter_type_id' => $type->id,
            'status' => 'draft',
            'year' => 2026,
            'date' => '2026-10-26',
        ]);

        // Konten berisi magic %PDF- agar deteksi mime berbasis isi (mimes)
        // benar-benar mengenali pdf — bukan sekadar nama file.
        $contents = "%PDF-1.4\n%lampiran-uji\n".str_repeat('A', 256);
        $file = UploadedFile::fake()->createWithContent('lampiran.pdf', $contents);

        $response = $this->post("/api/v1/documents/{$document->id}/attachments", [
            'file' => $file,
            'label' => 'KTP Penerima',
            'sort' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.label', 'KTP Penerima')
            ->assertJsonPath('data.sort', 2)
            ->assertJsonPath('data.mime', 'application/pdf')
            ->assertJsonPath('data.size', strlen($contents))
            ->assertJsonPath('data.sha256', hash('sha256', $contents));

        // Path internal TIDAK ikut dibocorkan ke response.
        $this->assertArrayNotHasKey('path', $response->json('data'));

        // Row tersimpan dengan path pola {yyyy}/{docId}/att-{uuid}.pdf.
        $attachment = DocumentAttachment::query()
            ->where('document_id', $document->id)->firstOrFail();
        $this->assertSame('KTP Penerima', $attachment->label);
        $this->assertSame(2, $attachment->sort);
        $this->assertMatchesRegularExpression(
            '#^2026/'.$document->id.'/att-[0-9a-f-]{36}\.pdf$#',
            $attachment->path,
        );

        // Berkas fisik ada di disk documents dengan isi identik.
        Storage::disk('documents')->assertExists($attachment->path);
        $this->assertSame($contents, Storage::disk('documents')->get($attachment->path));

        // Audit EVENT_UPDATED action upload_attachment.
        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_UPDATED)->firstOrFail();
        $this->assertSame('upload_attachment', $log->payload['action']);
        $this->assertSame($this->user->id, $log->actor_user_id);
    }

    #[Test]
    public function upload_attachment_sort_defaults_to_zero(): void
    {
        $this->signInAsSigner();
        $document = Document::factory()->create(['status' => 'menunggu_ttd']);
        $contents = "%PDF-1.4\nsort-default";
        $file = UploadedFile::fake()->createWithContent('lampiran.pdf', $contents);

        $this->post("/api/v1/documents/{$document->id}/attachments", [
            'file' => $file,
            'label' => 'Tanpa urutan',
        ])
            ->assertCreated()
            ->assertJsonPath('data.sort', 0);

        $this->assertSame(0, DocumentAttachment::query()
            ->where('document_id', $document->id)->value('sort'));
    }

    #[Test]
    public function upload_attachment_rejects_disallowed_mime(): void
    {
        $this->signInAsSigner();
        $document = Document::factory()->create(['status' => 'draft']);
        $file = UploadedFile::fake()->createWithContent('catatan.txt', 'hanya teks biasa');

        $this->post("/api/v1/documents/{$document->id}/attachments", [
            'file' => $file,
            'label' => 'Catatan',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'Data yang dikirim tidak valid.');

        $this->assertDatabaseCount('document_attachments', 0, 'mysql_document');
    }

    #[Test]
    public function upload_attachment_requires_label(): void
    {
        $this->signInAsSigner();
        $document = Document::factory()->create(['status' => 'draft']);
        $file = UploadedFile::fake()->createWithContent('lampiran.pdf', "%PDF-1.4\nx");

        $this->post("/api/v1/documents/{$document->id}/attachments", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('document_attachments', 0, 'mysql_document');
    }

    #[Test]
    public function upload_attachment_on_published_document_returns_409(): void
    {
        $this->signInAsSigner();
        $document = Document::factory()->status('terbit')->create();
        $file = UploadedFile::fake()->createWithContent('lampiran.pdf', "%PDF-1.4\nx");

        $this->post("/api/v1/documents/{$document->id}/attachments", [
            'file' => $file,
            'label' => 'Terlambat',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');

        $this->assertDatabaseCount('document_attachments', 0, 'mysql_document');
    }

    #[Test]
    public function delete_attachment_removes_row_and_file(): void
    {
        $this->signInAsSigner();
        $document = Document::factory()->create(['status' => 'draft']);

        $contents = 'PDF-LAMPIRAN-LAMA';
        $path = $document->year.'/'.$document->id.'/att-'.Str::uuid().'.pdf';
        Storage::disk('documents')->put($path, $contents);
        $attachment = DocumentAttachment::factory()->create([
            'document_id' => $document->id,
            'path' => $path,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
        ]);

        $this->delete("/api/v1/documents/{$document->id}/attachments/{$attachment->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id], 'mysql_document');
        Storage::disk('documents')->assertMissing($path);

        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_UPDATED)->firstOrFail();
        $this->assertSame('delete_attachment', $log->payload['action']);
    }

    #[Test]
    public function delete_attachment_of_other_document_returns_404(): void
    {
        $this->signInAsSigner();
        $documentA = Document::factory()->create(['status' => 'draft']);
        $documentB = Document::factory()->create(['status' => 'draft']);

        $path = $documentB->year.'/'.$documentB->id.'/att-'.Str::uuid().'.pdf';
        Storage::disk('documents')->put($path, 'PDF-B');
        $attachmentB = DocumentAttachment::factory()->create([
            'document_id' => $documentB->id,
            'path' => $path,
        ]);

        // Lampiran milik surat B dipanggil lewat surat A → 404.
        $this->delete("/api/v1/documents/{$documentA->id}/attachments/{$attachmentB->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        // Row + berkas B tetap utuh.
        $this->assertDatabaseHas('document_attachments', ['id' => $attachmentB->id], 'mysql_document');
        Storage::disk('documents')->assertExists($path);
    }

    #[Test]
    public function delete_attachment_on_published_document_returns_409(): void
    {
        $this->signInAsSigner();
        $document = Document::factory()->status('terbit')->create();
        $path = $document->year.'/'.$document->id.'/att-'.Str::uuid().'.pdf';
        Storage::disk('documents')->put($path, 'PDF-TERBIT');
        $attachment = DocumentAttachment::factory()->create([
            'document_id' => $document->id,
            'path' => $path,
        ]);

        $this->delete("/api/v1/documents/{$document->id}/attachments/{$attachment->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');

        $this->assertDatabaseHas('document_attachments', ['id' => $attachment->id], 'mysql_document');
        Storage::disk('documents')->assertExists($path);
    }

    #[Test]
    public function parties_create_search_and_update(): void
    {
        $this->signInAsSigner();

        // Buat pihak baru.
        $created = $this->postJson('/api/v1/parties', [
            'name' => 'Andi Saputra',
            'identity_no' => '3271234567890001',
            'address' => 'Jl. Merdeka 12, Bandung',
            'occupation' => 'Notaris',
            'notes' => 'Klien tetap',
        ]);

        $created->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Andi Saputra')
            ->assertJsonPath('data.identity_no', '3271234567890001')
            ->assertJsonPath('data.occupation', 'Notaris');

        $partyId = $created->json('data.id');
        $this->assertDatabaseHas('parties', ['id' => $partyId, 'name' => 'Andi Saputra'], 'mysql_document');

        // Fixture pencarian: nama lain + NIK unik.
        Party::factory()->create(['name' => 'Siti Rahayu', 'identity_no' => '3279998887770002']);
        Party::factory()->create(['name' => 'Budi Santoso', 'identity_no' => null]);

        // Cari berdasarkan nama (LIKE).
        $this->getJson('/api/v1/parties?q=Siti')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->tap(fn ($r) => $this->assertSame('Siti Rahayu', $r->json('data.0.name')));

        // Cari berdasarkan identity_no.
        $this->getJson('/api/v1/parties?q=3279998887770002')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Tanpa q → semua (envelope meta).
        $this->getJson('/api/v1/parties')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        // PATCH ubah pekerjaan & catatan.
        $this->patchJson("/api/v1/parties/{$partyId}", [
            'occupation' => 'Notaris Muda',
            'notes' => null,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.occupation', 'Notaris Muda')
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.name', 'Andi Saputra'); // tidak berubah

        $this->assertDatabaseHas('parties', [
            'id' => $partyId,
            'occupation' => 'Notaris Muda',
        ], 'mysql_document');
    }

    #[Test]
    public function parties_create_requires_name(): void
    {
        $this->signInAsSigner();

        $this->postJson('/api/v1/parties', ['address' => 'tanpa nama'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('parties', 0, 'mysql_document');
    }

    #[Test]
    public function letter_types_lists_active_only_ordered_by_code(): void
    {
        $this->signInAsSigner();

        $kua = LetterType::factory()->withSpecificFields([
            ['key' => 'nama_ahli_waris', 'label' => 'Nama Ahli Waris'],
        ])->create(['code' => 'KUA', 'name' => 'Keterangan Ahli Waris']);
        LetterType::factory()->create(['code' => 'SK', 'name' => 'Surat Kuasa']);
        LetterType::factory()->inactive()->create(['code' => 'ZZ']);

        $response = $this->getJson('/api/v1/letter-types');

        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('KUA', $data[0]['code']); // urut kode menaik
        $this->assertSame('SK', $data[1]['code']);
        $this->assertSame('Keterangan Ahli Waris', $data[0]['name']);
        $this->assertSame(
            [['key' => 'nama_ahli_waris', 'label' => 'Nama Ahli Waris']],
            $data[0]['specific_fields'],
        );
        $this->assertNull($data[1]['specific_fields']);
    }

    #[Test]
    public function templates_filter_by_letter_type_with_default_first(): void
    {
        $this->signInAsSigner();

        $st = LetterType::factory()->create(['code' => 'ST']);
        $sk = LetterType::factory()->create(['code' => 'SK']);

        Template::factory()->create([
            'letter_type_id' => $st->id,
            'name' => 'ST Biasa',
            'is_default' => false,
        ]);
        Template::factory()->default()->create([
            'letter_type_id' => $st->id,
            'name' => 'ST Default',
            'body_html' => '<p>Isi template ST</p>',
            'placeholders' => ['number', 'date', 'subject'],
        ]);
        // Template non-aktif & template jenis lain tidak boleh ikut.
        Template::factory()->create(['letter_type_id' => $st->id, 'is_active' => false]);
        Template::factory()->default()->create(['letter_type_id' => $sk->id]);

        $response = $this->getJson('/api/v1/templates?letter_type_code=ST');

        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Default tampil duluan.
        $this->assertSame('ST Default', $data[0]['name']);
        $this->assertTrue($data[0]['is_default']);
        $this->assertSame('ST', $data[0]['letter_type_code']);
        $this->assertSame('<p>Isi template ST</p>', $data[0]['body_html']);
        $this->assertSame(['number', 'date', 'subject'], $data[0]['placeholders']);

        $this->assertSame('ST Biasa', $data[1]['name']);
        $this->assertFalse($data[1]['is_default']);
    }

    #[Test]
    public function templates_require_letter_type_code(): void
    {
        $this->signInAsSigner();

        $this->getJson('/api/v1/templates')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /**
     * Login sebagai user test DAN beri baris signer aktif (lolos gate).
     */
    private function signInAsSigner(): void
    {
        Sanctum::actingAs($this->user);
        Signer::factory()->create(['user_id' => $this->user->id]);
    }
}
