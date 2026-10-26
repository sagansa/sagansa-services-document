<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\LetterType;
use App\Models\NumberSequence;
use App\Models\Signer;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finalizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Task 13 — API /api/v1/documents + /me/signer: gate peran signer,
 * draft (Mode A + duplikasi), daftar/filter/paginasi, detail, PATCH,
 * reserve nomor — beserta shape error konsisten {error:{code,message}}.
 */
class DocumentApiTest extends TestCase
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
    public function guest_without_token_gets_401(): void
    {
        $this->getJson('/api/v1/documents')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function user_without_active_signer_role_gets_403(): void
    {
        // Token valid, tapi tanpa baris signer sama sekali.
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/documents')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // Ada baris signer tapi TIDAK aktif — tetap ditolak.
        Signer::factory()->create(['user_id' => $this->user->id, 'is_active' => false]);

        $this->getJson('/api/v1/documents')->assertForbidden();
    }

    #[Test]
    public function store_creates_draft_and_records_audit(): void
    {
        $this->signInAsSigner();
        $type = LetterType::factory()->create(['code' => 'ST', 'name' => 'Surat Tugas']);

        $response = $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'subject' => 'Surat Tugas Audit Internal',
            'date' => '2026-10-26',
            'body_html' => '<p>Dengan hormat,</p>',
            'metadata' => ['kepada' => 'Tim Audit'],
            'classification' => 'rahasia',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.classification', 'rahasia')
            ->assertJsonPath('data.letter_type.code', 'ST')
            ->assertJsonPath('data.letter_type.name', 'Surat Tugas');

        // Draft tersimpan: nomor null (pra-reserve), jenis ter-resolve,
        // year dari date, created_by user login.
        $document = Document::query()->where('subject', 'Surat Tugas Audit Internal')->firstOrFail();
        $this->assertNull($document->number);
        $this->assertSame($type->id, $document->letter_type_id);
        $this->assertSame(2026, $document->year);
        $this->assertSame($this->user->id, $document->created_by);
        $this->assertSame(['kepada' => 'Tim Audit'], $document->metadata);

        $log = AuditLog::query()
            ->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_CREATED)
            ->firstOrFail();
        $this->assertSame($this->user->id, $log->actor_user_id);
    }

    #[Test]
    public function two_drafts_can_coexist_before_numbering(): void
    {
        // Defect review: dulu pra-nomor = '' pada kolom NOT NULL + UNIQUE,
        // sehingga draft kedua gagal duplicate key → 500. Kini pra-nomor =
        // NULL yang bebas dari constraint unique → banyak draft sah hidup
        // bersama sampai reserve/finalize mengisi nomornya.
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST']);

        $first = $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'subject' => 'Draft pertama',
            'date' => '2026-10-26',
        ])->assertCreated();

        $second = $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'subject' => 'Draft kedua',
            'date' => '2026-10-26',
        ])->assertCreated();

        $this->assertNull($first->json('data.number'));
        $this->assertNull($second->json('data.number'));
        $this->assertSame(2, Document::query()->whereNull('number')->count());
        $this->assertSame(0, NumberSequence::query()->where('letter_type_code', 'ST')->value('last_seq') ?? 0);

        // Reserve pada draft pertama: dia mendapat 001/... , draft kedua
        // tetap pra-nomor (null) — bukan ikut ter-reserve.
        $reserved = $this->postJson('/api/v1/documents/'.$first->json('data.id').'/numbering/reserve')
            ->assertOk()
            ->assertJsonPath('data.seq', 1);

        $this->assertMatchesRegularExpression('/^001\/ST\/DIR\/[IVX]+\/2026$/', $reserved->json('data.number'));
        $this->assertSame($reserved->json('data.number'), Document::query()->findOrFail($first->json('data.id'))->number);
        $this->assertNull(Document::query()->findOrFail($second->json('data.id'))->number);
        $this->assertSame(1, NumberSequence::query()->where('letter_type_code', 'ST')->value('last_seq'));
    }

    #[Test]
    public function store_validation_error_uses_consistent_error_shape(): void
    {
        $this->signInAsSigner();

        $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'XX',
            'date' => 'bukan-tanggal',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'Data yang dikirim tidak valid.')
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);

        $this->assertDatabaseCount('documents', 0, 'mysql_document');
    }

    #[Test]
    public function store_duplicates_content_and_attachments_from_published_source(): void
    {
        $this->signInAsSigner();
        $type = LetterType::factory()->create(['code' => 'ST']);

        $source = Document::factory()->status('terbit')->create([
            'letter_type_id' => $type->id,
            'unit' => 'DIR',
            'subject' => 'Surat Kuasa Pengangkatan',
            'body_html' => '<p>Isi surat kuasa</p>',
            'metadata' => ['pihak' => 'Kantor Notaris'],
            'classification' => 'rahasia',
            'year' => 2026,
        ]);

        // Dua lampiran dengan berkas fisik di disk documents.
        $sourceAttachments = [];
        foreach ([0 => 'KTP', 1 => 'NPWP'] as $sort => $label) {
            $contents = 'PDF-BYTES-'.$label;
            $path = '2026/'.$source->id.'/attachment-'.Str::uuid().'.pdf';
            Storage::disk('documents')->put($path, $contents);

            $sourceAttachments[] = DocumentAttachment::factory()->create([
                'document_id' => $source->id,
                'label' => $label,
                'sort' => $sort,
                'path' => $path,
                'sha256' => hash('sha256', $contents),
                'size' => strlen($contents),
            ]);
        }

        $response = $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'ST',
            'subject' => 'Salinan Surat Kuasa Pengangkatan', // editable
            'date' => '2026-10-26',
            'duplicate_from_id' => $source->id,
            // unit/body_html/metadata/classification sengaja tidak dikirim →
            // disalin dari sumber.
        ]);

        $response->assertCreated();
        $documentId = $response->json('data.id');

        /** @var Document $copy */
        $copy = Document::query()->findOrFail($documentId);
        $this->assertSame('draft', $copy->status);
        $this->assertSame('Salinan Surat Kuasa Pengangkatan', $copy->subject);
        $this->assertSame('DIR', $copy->unit);                    // disalin
        $this->assertSame('<p>Isi surat kuasa</p>', $copy->body_html); // disalin
        $this->assertSame(['pihak' => 'Kantor Notaris'], $copy->metadata);
        $this->assertSame('rahasia', $copy->classification);      // disalin

        // Audit created memuat duplicate_from.
        $log = AuditLog::query()->where('document_id', $documentId)
            ->where('event', AuditLogger::EVENT_CREATED)->firstOrFail();
        $this->assertSame($source->id, $log->payload['duplicate_from']);

        // Dua lampiran baru: row baru milik dokumen baru, sha256 & size sama,
        // berkas benar-benar ada di disk dengan konten identik.
        $copies = DocumentAttachment::query()->where('document_id', $documentId)
            ->orderBy('sort')->get();
        $this->assertCount(2, $copies);

        foreach ($copies as $index => $attachmentCopy) {
            $original = $sourceAttachments[$index];

            $this->assertNotSame($original->id, $attachmentCopy->id);
            $this->assertNotSame($original->path, $attachmentCopy->path);
            $this->assertSame($original->label, $attachmentCopy->label);
            $this->assertSame($original->sort, $attachmentCopy->sort);
            $this->assertSame($original->mime, $attachmentCopy->mime);
            $this->assertSame($original->sha256, $attachmentCopy->sha256);
            $this->assertSame($original->size, $attachmentCopy->size);

            Storage::disk('documents')->assertExists($attachmentCopy->path);
            $this->assertSame(
                $original->sha256,
                hash('sha256', (string) Storage::disk('documents')->get($attachmentCopy->path)),
            );
        }

        // Berkas sumber tetap utuh.
        Storage::disk('documents')->assertExists($sourceAttachments[0]->path);
        Storage::disk('documents')->assertExists($sourceAttachments[1]->path);
    }

    #[Test]
    public function store_rejects_duplicate_source_that_is_not_published(): void
    {
        $this->signInAsSigner();
        $type = LetterType::factory()->create(['code' => 'ST']);
        $source = Document::factory()->status('draft')->create(['letter_type_id' => $type->id]);

        $response = $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'subject' => 'Salinan prematur',
            'date' => '2026-10-26',
            'duplicate_from_id' => $source->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertIsArray($response->json('error.details.duplicate_from_id'));

        $this->assertDatabaseCount('documents', 1, 'mysql_document');
    }

    #[Test]
    public function reserve_number_stores_number_and_is_idempotent(): void
    {
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST']);

        $documentId = $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'subject' => 'Surat pesan nomor',
            'date' => '2026-10-26',
        ])->assertCreated()->json('data.id');

        $first = $this->postJson("/api/v1/documents/{$documentId}/numbering/reserve");

        $first->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.seq', 1);
        $this->assertMatchesRegularExpression(
            '/^\d{3}\/ST\/DIR\/[IVX]+\/2026$/',
            $first->json('data.number'),
        );

        $number = $first->json('data.number');
        $document = Document::query()->findOrFail($documentId);
        $this->assertSame($number, $document->number);
        $this->assertSame(1, $document->seq);
        $this->assertSame('draft', $document->status);

        $log = AuditLog::query()->where('document_id', $documentId)
            ->where('event', AuditLogger::EVENT_UPDATED)->firstOrFail();
        $this->assertSame('reserve_number', $log->payload['action']);

        // Reserve ulang idempoten: nomor sama dikembalikan, counter TIDAK
        // maju — tidak ada seq dobel.
        $second = $this->postJson("/api/v1/documents/{$documentId}/numbering/reserve");

        $second->assertOk()
            ->assertJsonPath('data.number', $number)
            ->assertJsonPath('data.seq', 1);
        $this->assertSame(1, NumberSequence::query()->where('letter_type_code', 'ST')->value('last_seq'));

        // Reserve surat yang sudah terbit → 409 DOCUMENT_LOCKED.
        $published = Document::factory()->status('terbit')->create([
            'letter_type_id' => LetterType::factory()->create(['code' => 'SK'])->id,
        ]);

        $this->postJson("/api/v1/documents/{$published->id}/numbering/reserve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');
    }

    #[Test]
    public function update_draft_changes_fields_and_records_audit(): void
    {
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST']);

        $documentId = $this->postJson('/api/v1/documents', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'subject' => 'Perihal awal',
            'date' => '2026-10-26',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/documents/{$documentId}", [
            'subject' => 'Perihal revisi',
            'classification' => 'rahasia',
            'date' => '2026-11-05',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subject', 'Perihal revisi')
            ->assertJsonPath('data.classification', 'rahasia');

        $document = Document::query()->findOrFail($documentId);
        $this->assertSame('Perihal revisi', $document->subject);
        $this->assertSame('rahasia', $document->classification);
        $this->assertSame(2026, $document->year); // year ikut date baru
        $this->assertSame('2026-11-05', $document->date->format('Y-m-d'));
        $this->assertSame('draft', $document->status);

        $this->assertSame(
            1,
            AuditLog::query()->where('document_id', $documentId)
                ->where('event', AuditLogger::EVENT_UPDATED)->count(),
        );
    }

    #[Test]
    public function update_published_document_returns_409(): void
    {
        $this->signInAsSigner();
        $document = Document::factory()->status('terbit')->create([
            'subject' => 'Sudah terbit',
            'letter_type_id' => LetterType::factory()->create(['code' => 'ST'])->id,
        ]);

        $this->patchJson("/api/v1/documents/{$document->id}", [
            'subject' => 'Coba ubah',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');

        $this->assertSame('Sudah terbit', $document->fresh()->subject);
    }

    #[Test]
    public function show_unknown_document_returns_404_error_shape(): void
    {
        $this->signInAsSigner();

        $this->getJson('/api/v1/documents/'.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function show_returns_full_detail_shape_for_finalized_document(): void
    {
        $this->signInAsSigner();
        $type = LetterType::factory()->create(['code' => 'ST', 'name' => 'Surat Tugas']);

        $signer = Signer::factory()->create([
            'display_name' => 'Budi Direktur',
            'role' => 'direktur',
        ]);

        // Fixture paling jujur: dokumen benar-benar difinalisasi lewat
        // Finalizer (issuance aktif asli + status terbit + audit).
        $document = Document::factory()->create([
            'letter_type_id' => $type->id,
            'unit' => 'DIR',
            'year' => now()->year,
            'subject' => 'Surat Tugas Audit Internal',
            'body_html' => '<p>Isi surat</p>',
            'status' => 'draft',
        ]);

        $attachmentPath = $document->year.'/'.$document->id.'/attachment-'.Str::uuid().'.pdf';
        Storage::disk('documents')->put($attachmentPath, 'PDF-LAMPIRAN');
        $attachment = DocumentAttachment::factory()->create([
            'document_id' => $document->id,
            'label' => 'Lampiran Audit',
            'sort' => 0,
            'path' => $attachmentPath,
            'sha256' => hash('sha256', 'PDF-LAMPIRAN'),
            'size' => strlen('PDF-LAMPIRAN'),
        ]);

        // Signing request sudah diputuskan (tidak menghalangi finalize).
        SigningRequest::factory()->status('disetujui')->create([
            'document_id' => $document->id,
            'signer_id' => $signer->id,
            'acted_at' => now(),
        ]);

        $issuance = (new Finalizer)->finalizeFromContent($document, (int) $this->user->id);

        $response = $this->getJson("/api/v1/documents/{$document->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.status', 'terbit')
            ->assertJsonPath('data.number', $document->number)
            ->assertJsonPath('data.letter_type.code', 'ST')
            ->assertJsonPath('data.active_issuance.id', $issuance->id)
            ->assertJsonPath('data.active_issuance.method', 'a')
            ->assertJsonPath('data.active_issuance.method_label', 'elektronik')
            ->assertJsonPath('data.active_issuance.file_sha256', $issuance->file_sha256);

        $data = $response->json('data');
        $this->assertNotNull($data['active_issuance']['finalized_at']);
        $this->assertCount(1, $data['issuances']);
        $this->assertSame($issuance->id, $data['issuances'][0]['id']);

        $this->assertCount(1, $data['attachments']);
        $this->assertSame($attachment->id, $data['attachments'][0]['id']);
        $this->assertSame('Lampiran Audit', $data['attachments'][0]['label']);
        $this->assertSame(0, $data['attachments'][0]['sort']);
        $this->assertSame($attachment->mime, $data['attachments'][0]['mime']);
        $this->assertSame($attachment->size, $data['attachments'][0]['size']);
        $this->assertSame($attachment->sha256, $data['attachments'][0]['sha256']);

        $this->assertCount(1, $data['signing_requests']);
        $this->assertSame('disetujui', $data['signing_requests'][0]['status']);
        $this->assertNotNull($data['signing_requests'][0]['acted_at']);
        $this->assertSame('Budi Direktur', $data['signing_requests'][0]['signer']['display_name']);
        $this->assertSame('direktur', $data['signing_requests'][0]['signer']['role']);
    }

    #[Test]
    public function me_signer_lists_active_roles_for_current_user(): void
    {
        Sanctum::actingAs($this->user);

        $readyPath = 'signatures/ttd-001.png';
        Storage::disk('documents')->put($readyPath, 'PNG-BYTES');

        Signer::factory()->create([
            'user_id' => $this->user->id,
            'role' => 'direktur',
            'display_name' => 'Budi',
            'title' => 'Direktur Utama',
            'signature_asset_path' => $readyPath,
        ]);
        Signer::factory()->create([
            'user_id' => $this->user->id,
            'role' => 'komisaris',
            'display_name' => 'Sari',
            'title' => null,
            'signature_asset_path' => null,
        ]);
        // Milik user lain & yang non-aktif tidak boleh ikut.
        Signer::factory()->create(['user_id' => 999999, 'role' => 'direktur']);
        Signer::factory()->create(['user_id' => $this->user->id, 'role' => 'pemegang_saham', 'is_active' => false]);

        $response = $this->getJson('/api/v1/me/signer');

        $response->assertOk()->assertJsonPath('success', true);

        $signers = $response->json('data.signers');
        $this->assertCount(2, $signers);

        $this->assertSame('direktur', $signers[0]['role']);
        $this->assertSame('Budi', $signers[0]['display_name']);
        $this->assertSame('Direktur Utama', $signers[0]['title']);
        $this->assertTrue($signers[0]['signature_ready']);

        $this->assertSame('komisaris', $signers[1]['role']);
        $this->assertFalse($signers[1]['signature_ready']);
    }

    #[Test]
    public function index_paginates_and_filters(): void
    {
        $this->signInAsSigner();
        $type = LetterType::factory()->create(['code' => 'ST']);
        $other = LetterType::factory()->create(['code' => 'SK']);

        Document::factory()->count(20)->create(['letter_type_id' => $type->id]);
        Document::factory()->count(2)->create([
            'letter_type_id' => $other->id,
            'subject' => 'Surat Khusus Klien',
            'status' => 'terbit',
        ]);

        // Halaman 1: envelope + meta paginasi 15.
        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 22)
            ->assertJsonPath('meta.per_page', 15)
            ->tap(function ($response) {
                $this->assertCount(15, $response->json('data'));
                $this->assertSame('ST', $response->json('data.0.letter_type.code'));
            });

        // Halaman 2: sisa 7.
        $this->getJson('/api/v1/documents?page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->tap(fn ($response) => $this->assertCount(7, $response->json('data')));

        // Filter kode jenis surat.
        $this->getJson('/api/v1/documents?letter_type=SK')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->tap(fn ($response) => $this->assertSame('SK', $response->json('data.0.letter_type.code')));

        // Filter status.
        $this->getJson('/api/v1/documents?status=terbit')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // Filter tahun.
        $this->getJson('/api/v1/documents?year='.now()->year)
            ->assertOk()
            ->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/documents?year=1999')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // Pencarian nomor/perihal (scopeSearch).
        $this->getJson('/api/v1/documents?q=Khusus Klien')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
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
