<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DispositionRecord;
use App\Models\Document;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use App\Models\Party;
use App\Models\Signer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LetterLinkService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Task 5 (fase 1b) — API /api/v1/incoming-letters + /api/v1/dispositions:
 * registrasi scan (PDF tunggal / foto multi), list + filter, detail dengan
 * embed thread & disposisi, PATCH + kunci `selesai` (409 INCOMING_LOCKED),
 * stream scan bytes identik + filename tersanitasi, alur disposisi
 * (penerima wajib signer aktif; surat otomatis `diproses`; complete →
 * acted_at; complete ulang → 409).
 */
class IncomingApiTest extends TestCase
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

    private function signInAsSigner(): void
    {
        Sanctum::actingAs($this->user);
        Signer::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Isian registrasi lengkap (tanpa scan — ditambah per kasus).
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'sender_party_id' => Party::factory()->create(['name' => 'PT Nusantara Sejahtera'])->id,
            'number_external' => '123/EXT/2026',
            'letter_date' => '2026-10-20',
            'received_at' => '2026-10-25',
            'subject' => 'Undangan rapat kerja sama',
            'sifat' => 'biasa',
            'attachments_note' => 'Lampiran fisik 2 berkas',
        ], $overrides);
    }

    #[Test]
    public function guest_without_token_gets_401(): void
    {
        $this->getJson('/api/v1/incoming-letters')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function store_registers_pdf_scan_with_agenda_row_and_audit(): void
    {
        $this->signInAsSigner();

        $bytes = '%PDF-1.4 scan tunggal untuk surat masuk';

        $response = $this->post('/api/v1/incoming-letters', $this->payload() + [
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', $bytes),
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'terdaftar')
            ->assertJsonPath('data.sifat', 'biasa')
            ->assertJsonPath('data.classification', 'umum')
            ->assertJsonPath('data.subject', 'Undangan rapat kerja sama')
            ->assertJsonPath('data.number_external', '123/EXT/2026')
            ->assertJsonPath('data.sender_party.name', 'PT Nusantara Sejahtera');

        // Agenda number di-reserve (format SM-seq/bulan-roman/tahun) dan
        // baris surat lengkap dengan integritas scan.
        $agenda = $response->json('data.agenda_number');
        $this->assertMatchesRegularExpression('/^SM-\d{3}\/[IVXLCDM]+\/2026$/', $agenda);

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()->where('agenda_number', $agenda)->firstOrFail();
        $this->assertSame(hash('sha256', $bytes), $letter->scan_sha256);
        $this->assertSame(strlen($bytes), $letter->scan_size);
        $this->assertSame($this->user->id, $letter->created_by);
        $this->assertSame(2026, $letter->year);

        // Scan tersimpan di disk documents pada path {year}/{id}/scan.pdf,
        // isinya identik byte-per-byte dengan yang diunggah.
        $this->assertSame(sprintf('incoming/2026/%s/scan.pdf', $letter->id), $letter->scan_path);
        Storage::disk('documents')->assertExists($letter->scan_path);
        $this->assertSame($bytes, (string) Storage::disk('documents')->get($letter->scan_path));

        // Jejak audit incoming_created oleh user login.
        $log = AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_INCOMING_CREATED)
            ->firstOrFail();
        $this->assertSame($this->user->id, $log->actor_user_id);
    }

    #[Test]
    public function store_composes_three_photos_into_single_three_page_pdf(): void
    {
        $this->signInAsSigner();

        $response = $this->post('/api/v1/incoming-letters', $this->payload() + [
            'scan_images' => [
                UploadedFile::fake()->image('foto-1.png', 600, 800), // portrait
                UploadedFile::fake()->image('foto-2.png', 800, 600), // landscape
                UploadedFile::fake()->image('foto-3.png', 600, 800), // portrait
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'terdaftar');

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()->where('subject', 'Undangan rapat kerja sama')->firstOrFail();

        $stored = (string) Storage::disk('documents')->get($letter->scan_path);
        $this->assertSame(3, preg_match_all('#/Type\s*/Page(?!s)#', $stored), '3 foto harus menjadi 3 halaman');
        $this->assertSame($letter->scan_sha256, hash('sha256', $stored));
        $this->assertSame(3, $letter->scan_pages);
    }

    #[Test]
    public function store_with_in_reply_to_document_creates_balasan_link_row(): void
    {
        $this->signInAsSigner();

        $parent = Document::factory()->create([
            'number' => '010/ST/DIR/X/2026',
            'date' => '2026-10-20',
        ]);

        $response = $this->post('/api/v1/incoming-letters', $this->payload() + [
            'in_reply_to' => [
                'type' => 'document',
                'id' => $parent->id,
                'relation' => 'balasan',
            ],
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 balasan eksternal'),
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        // Edge tercatat: surat keluar → surat masuk, relasi balasan, aktor
        // user login (pola assert DatabaseHas ThreadApiTest Task 6).
        $this->assertDatabaseHas('letter_links', [
            'parent_type' => 'document',
            'parent_id' => $parent->id,
            'child_type' => 'incoming_letter',
            'child_id' => $response->json('data.id'),
            'relation' => 'balasan',
            'created_by' => $this->user->id,
        ], 'mysql_document');

        // Thread dari surat keluar kini memuat 2 node — surat masuk
        // menjadi child balasan.
        $this->getJson("/api/v1/letters/thread/document/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    #[Test]
    public function store_with_ghost_in_reply_to_returns_422_without_incoming_row(): void
    {
        $this->signInAsSigner();

        $response = $this->post('/api/v1/incoming-letters', $this->payload() + [
            'in_reply_to' => [
                'type' => 'document',
                'id' => (string) Str::uuid(),
                'relation' => 'balasan',
            ],
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 ghost'),
        ]);

        // Preflight induk gagal → 422 LINK_TARGET_NOT_FOUND, TANPA surat
        // yatim (agenda tidak terpakai, tanpa edge, tanpa scan di disk).
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'LINK_TARGET_NOT_FOUND');
        $this->assertSame(0, IncomingLetter::query()->count());
        $this->assertSame(0, LetterLink::query()->count());
    }

    #[Test]
    public function store_without_scan_or_with_both_forms_returns_422_scan_invalid(): void
    {
        $this->signInAsSigner();

        // Tanpa scan sama sekali.
        $this->post('/api/v1/incoming-letters', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SCAN_INVALID');

        // Keduanya dikirim bersamaan.
        $this->post('/api/v1/incoming-letters', $this->payload() + [
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 x'),
            'scan_images' => [UploadedFile::fake()->image('foto.png', 100, 100)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SCAN_INVALID');

        // Tidak ada baris surat yang lahir dari dua percobaan itu.
        $this->assertSame(0, IncomingLetter::query()->count());
    }

    #[Test]
    public function store_rejects_non_pdf_non_image_mime(): void
    {
        $this->signInAsSigner();

        $this->post('/api/v1/incoming-letters', $this->payload() + [
            'scan' => UploadedFile::fake()->create('scan.txt', 20, 'text/plain'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->post('/api/v1/incoming-letters', $this->payload() + [
            'scan_images' => [UploadedFile::fake()->create('data.csv', 20, 'text/csv')],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function store_validates_required_fields(): void
    {
        $this->signInAsSigner();

        $response = $this->post('/api/v1/incoming-letters', [
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 x'),
            'received_at' => '2026-10-25',
            'sifat' => 'antonim',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('subject', $response->json('error.details'));
        $this->assertArrayHasKey('sender_party_id', $response->json('error.details'));
        $this->assertArrayHasKey('sifat', $response->json('error.details'));
    }

    #[Test]
    public function index_filters_searches_and_keeps_rahasia_metadata(): void
    {
        $this->signInAsSigner();

        IncomingLetter::factory()->create([
            'subject' => 'Undangan rapat kerja sama',
            'number_external' => '001/EXT/2026',
            'sifat' => 'biasa',
            'classification' => 'umum',
            'status' => 'terdaftar',
            'received_at' => '2026-10-25',
        ]);
        IncomingLetter::factory()->sifat('rahasia')->create([
            'subject' => 'Laporan keuangan internal',
            'status' => 'terdaftar',
            'received_at' => '2026-10-26',
        ]);
        IncomingLetter::factory()->status('diproses')->create([
            'subject' => 'Permohonan audiensi',
            'received_at' => '2026-10-24',
        ]);
        IncomingLetter::factory()->create([
            'subject' => 'Arsip tahun lalu',
            'year' => 2025,
            'received_at' => '2025-06-01',
        ]);

        // Urut received_at desc.
        $response = $this->getJson('/api/v1/incoming-letters');
        $response->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('data.0.subject', 'Laporan keuangan internal')
            ->assertJsonPath('data.1.subject', 'Undangan rapat kerja sama')
            ->assertJsonPath('data.2.subject', 'Permohonan audiensi')
            ->assertJsonPath('data.3.subject', 'Arsip tahun lalu');
        // senderParty di-eager (tanpa N+1 metadata pihak tetap tersedia).
        $this->assertNotNull($response->json('data.0.sender_party.name'));

        // Filter q pada subject.
        $this->getJson('/api/v1/incoming-letters?q=Undangan')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Undangan rapat kerja sama');

        // Filter q pada number_external.
        $this->getJson('/api/v1/incoming-letters?q=001%2FEXT')
            ->assertOk()->assertJsonPath('meta.total', 1);

        // Filter status.
        $this->getJson('/api/v1/incoming-letters?status=diproses')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Permohonan audiensi');

        // Filter sifat: surat rahasia TETAP tampil beserta metadatanya
        // (konsisten fase 1 — isi scan hanya via endpoint stream).
        $this->getJson('/api/v1/incoming-letters?sifat=rahasia')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Laporan keuangan internal')
            ->assertJsonPath('data.0.classification', 'rahasia');

        // Filter year.
        $this->getJson('/api/v1/incoming-letters?year=2025')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Arsip tahun lalu');
    }

    #[Test]
    public function show_embeds_dispositions_thread_and_links_count(): void
    {
        $this->signInAsSigner();

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::factory()->create([
            'letter_date' => '2026-10-01',
            'received_at' => '2026-10-02',
            'subject' => 'Undangan kerja sama',
        ]);
        DispositionRecord::factory()->create([
            'incoming_letter_id' => $letter->id,
            'to_user_id' => 77,
            'created_at' => now()->subMinutes(10),
        ]);
        DispositionRecord::factory()->create([
            'incoming_letter_id' => $letter->id,
            'to_user_id' => 88,
            'created_at' => now(),
        ]);
        $reply = Document::factory()->create([
            'number' => '001/ST/DIR/X/2026',
            'date' => '2026-10-03',
            'subject' => 'Balasan resmi kerja sama',
        ]);
        (new LetterLinkService)->link('incoming_letter', $letter->id, 'document', $reply->id, 'balasan', 77);

        $response = $this->getJson("/api/v1/incoming-letters/{$letter->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $letter->id)
            ->assertJsonPath('data.agenda_number', $letter->agenda_number);

        // Disposisi urut created_at dengan label pengguna lintas-DB.
        $response->assertJsonPath('data.dispositions.0.to_user_label', 'Pengguna #77')
            ->assertJsonPath('data.dispositions.1.to_user_label', 'Pengguna #88');

        // Thread embed: akar surat masuk + balasan document, urut tanggal.
        $response->assertJsonPath('data.thread.total', 2)
            ->assertJsonPath('data.thread.root.type', 'incoming_letter')
            ->assertJsonPath('data.thread.nodes.0.type', 'incoming_letter')
            ->assertJsonPath('data.thread.nodes.1.type', 'document')
            ->assertJsonPath('data.thread.nodes.1.title', 'Balasan resmi kerja sama')
            ->assertJsonPath('data.thread.nodes.1.relation_to_parent', 'balasan');

        // Jumlah edge link kedua arah.
        $response->assertJsonPath('data.links_count', 1);

        // Id tak dikenal → 404 shape konsisten.
        $this->getJson('/api/v1/incoming-letters/'.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function patch_updates_fields_then_locks_after_selesai(): void
    {
        $this->signInAsSigner();

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::factory()->create(['subject' => 'Perihal lama', 'sifat' => 'biasa']);

        $this->patchJson("/api/v1/incoming-letters/{$letter->id}", [
            'subject' => 'Perihal baru',
            'sifat' => 'segera',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subject', 'Perihal baru')
            ->assertJsonPath('data.sifat', 'segera');

        $this->assertSame('Perihal baru', $letter->fresh()->subject);
        AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_INCOMING_UPDATED)
            ->firstOrFail();

        // Setelah selesai semua field beku → 409 INCOMING_LOCKED.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/status", ['status' => 'diproses'])->assertOk();
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/status", ['status' => 'selesai'])->assertOk();

        $this->patchJson("/api/v1/incoming-letters/{$letter->id}", ['subject' => 'dicoba ubah'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INCOMING_LOCKED');
        $this->assertSame('Perihal baru', $letter->fresh()->subject);
    }

    #[Test]
    public function status_only_moves_forward(): void
    {
        $this->signInAsSigner();

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::factory()->create(['status' => 'terdaftar']);

        // Maju: terdaftar → diproses.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/status", ['status' => 'diproses'])
            ->assertOk()
            ->assertJsonPath('data.status', 'diproses');
        AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_INCOMING_STATUS)
            ->firstOrFail();

        // Mundur / mengulang status sama → 409.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/status", ['status' => 'terdaftar'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INCOMING_LOCKED');
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/status", ['status' => 'diproses'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INCOMING_LOCKED');

        // Status tak dikenal → 422.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/status", ['status' => 'hilang'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Maju terakhir: diproses → selesai.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/status", ['status' => 'selesai'])
            ->assertOk()
            ->assertJsonPath('data.status', 'selesai');
    }

    #[Test]
    public function scan_streams_identical_bytes_with_sanitized_filename(): void
    {
        $this->signInAsSigner();

        $bytes = '%PDF-1.4 isi scan asli untuk uji stream';

        $response = $this->post('/api/v1/incoming-letters', $this->payload() + [
            'scan' => UploadedFile::fake()->createWithContent('scan.pdf', $bytes),
        ]);
        $response->assertCreated();

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()->where('subject', 'Undangan rapat kerja sama')->firstOrFail();

        $download = $this->get("/api/v1/incoming-letters/{$letter->id}/scan");
        $download->assertOk();

        // Bytes identik dengan yang terdaftar (sha256 sama) + tipe PDF.
        $this->assertSame($bytes, $download->streamedContent());
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $download->streamedContent()));
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));

        // Filename = agenda number tersanitasi (karakter di luar
        // A-Za-z0-9._- diganti '-', pola fase 1 VerifyController).
        $expected = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $letter->agenda_number.'.pdf');
        $this->assertStringContainsString($expected, (string) $download->headers->get('Content-Disposition'));
    }

    #[Test]
    public function disposition_requires_active_signer_and_marks_letter_processed(): void
    {
        $this->signInAsSigner();

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::factory()->create(['status' => 'terdaftar']);
        $signerAktif = Signer::factory()->create(['user_id' => 4242]);
        Signer::factory()->create(['user_id' => 5555, 'is_active' => false]);

        // to_user_id tanpa baris signer → 422 pesan Indonesia.
        $rejected = $this->postJson("/api/v1/incoming-letters/{$letter->id}/dispositions", [
            'to_user_id' => 999999,
            'instruction' => 'Mohon ditindaklanjuti',
        ]);
        $rejected->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertStringContainsString('signer aktif', (string) $rejected->json('error.details.to_user_id.0'));

        // Signer non-aktif → 422 juga.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/dispositions", [
            'to_user_id' => 5555,
            'instruction' => 'Mohon ditindaklanjuti',
        ])->assertStatus(422);

        // instruction wajib.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/dispositions", [
            'to_user_id' => 4242,
        ])->assertStatus(422);

        // Sukses: disposisi tercatat + surat otomatis `diproses`.
        $response = $this->postJson("/api/v1/incoming-letters/{$letter->id}/dispositions", [
            'to_user_id' => 4242,
            'instruction' => 'Mohon ditindaklanjuti pekan ini',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.to_user_label', 'Pengguna #4242')
            ->assertJsonPath('data.instruction', 'Mohon ditindaklanjuti pekan ini');

        $this->assertSame('diproses', $letter->fresh()->status);

        $dispositionId = $response->json('data.id');
        $this->assertDatabaseHas('disposition_records', [
            'id' => $dispositionId,
            'incoming_letter_id' => $letter->id,
            'to_user_id' => 4242,
            'status' => 'open',
            'created_by' => $this->user->id,
        ], 'mysql_document');

        $log = AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_DISPOSITION_ADDED)
            ->firstOrFail();
        $this->assertSame($this->user->id, $log->actor_user_id);

        // Surat yang sudah diproses (atau selesai) tetap boleh tambah disposisi.
        $this->postJson("/api/v1/incoming-letters/{$letter->id}/dispositions", [
            'to_user_id' => $signerAktif->user_id,
            'instruction' => 'Disposisi tambahan',
        ])->assertCreated();
    }

    #[Test]
    public function disposition_complete_sets_acted_at_and_rejects_repeat(): void
    {
        $this->signInAsSigner();

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::factory()->create(['status' => 'diproses']);
        /** @var DispositionRecord $disposition */
        $disposition = DispositionRecord::factory()->create([
            'incoming_letter_id' => $letter->id,
            'to_user_id' => 77,
            'status' => 'open',
        ]);

        $response = $this->postJson("/api/v1/dispositions/{$disposition->id}/complete");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'selesai');

        $fresh = $disposition->fresh();
        $this->assertNotNull($fresh->acted_at);
        $this->assertSame('selesai', $fresh->status);

        AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_DISPOSITION_COMPLETED)
            ->firstOrFail();

        // Complete ulang → 409 INCOMING_LOCKED (dipilih idempoten-friendly
        // dengan code yang sama dengan lock surat masuk — didokumentasikan).
        $this->postJson("/api/v1/dispositions/{$disposition->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INCOMING_LOCKED');
    }
}
