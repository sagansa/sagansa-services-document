<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\LetterType;
use App\Models\Signer;
use App\Models\SigningRequest;
use App\Models\VerificationHit;
use App\Services\AuditLogger;
use App\Services\Finalizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Halaman verifikasi + unduhan publik (Task 17) — tanpa auth, throttle
 * `verify` (30/menit per IP dari config document.verify_rate_limit):
 *
 *   GET /verify/{uuid}                halaman status (badge SAH/DIBATALKAN/
 *                                     DICABUT/Belum diterbitkan) + riwayat
 *                                     terbitan + lampiran; rahasia → tanpa
 *                                     perihal & tanpa unduhan; 404 netral
 *                                     untuk uuid tak dikenal.
 *   GET /verify/{uuid}/file           stream issuance aktif (hanya terbit,
 *                                     rahasia → 403) — bytes identik.
 *   GET /verify/{uuid}/attachments/x  stream lampiran (hanya terbit +
 *                                     bukan rahasia).
 *
 * Setiap hit halaman menambah baris verification_hits + audit
 * verified_public (payload path).
 */
class VerifyPublicTest extends TestCase
{
    use CreatesDocumentSchema;

    private Finalizer $finalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
        Storage::fake('documents');
        $this->finalizer = new Finalizer;
    }

    #[Test]
    public function halaman_sah_menampilkan_nomor_badge_metode_hash_dan_lampiran(): void
    {
        $document = $this->makeLetter(['subject' => 'Surat Kuasa Pengangkatan Direktur']);
        $attachment = $this->attachFile($document, 'Lampiran SK', 'ISI-LAMPIRAN-12345');
        $signer = Signer::factory()->role('direktur')->create(['display_name' => 'Rani Kusuma', 'title' => 'Direktur Utama']);
        SigningRequest::factory()->status('disetujui')->create(['document_id' => $document->id, 'signer_id' => $signer->id]);

        $issuance = $this->finalizer->finalizeFromContent($document, 77);

        $response = $this->get("/verify/{$document->id}");

        $response->assertOk();
        $response->assertSee('badge--sah', false);
        $response->assertSee('SAH');
        $response->assertSee($document->number);
        $response->assertSee($document->letterType->name);
        $response->assertSee('Surat Kuasa Pengangkatan Direktur');
        $response->assertSee('Rani Kusuma');
        $response->assertSee('Elektronik — ditandatangani elektronik (QR)', false);
        // Hash pendek 8 karakter pertama + full hash di atribut title.
        $response->assertSee(substr($issuance->file_sha256, 0, 8));
        $this->assertStringContainsString(
            'title="'.$issuance->file_sha256.'"',
            $response->getContent(),
            'full hash tersedia di atribut title untuk pemeriksaan manual',
        );
        // Tautan unduh berkas + lampiran.
        $response->assertSee("/verify/{$document->id}/file", false);
        $response->assertSee("/verify/{$document->id}/attachments/{$attachment->id}", false);
        $response->assertSee('Lampiran SK');
        // Footer kredibilitas.
        $response->assertSee('Diverifikasi otomatis oleh sistem surat PT Sagansa — document.sagansa.id', false);
    }

    #[Test]
    public function unduh_file_mengirim_bytes_identik_sebagai_pdf(): void
    {
        $document = $this->makeLetter();
        $issuance = $this->finalizer->finalizeFromContent($document, 77);

        $response = $this->get("/verify/{$document->id}/file");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame(
            $issuance->file_sha256,
            hash('sha256', $response->streamedContent()),
            'bytes yang dikirim harus identik dengan berkas issuance aktif',
        );
    }

    #[Test]
    public function unduh_lampiran_mengirim_isi_berkas(): void
    {
        $document = $this->makeLetter();
        $attachment = $this->attachFile($document, 'Daftar Saham', 'ISI-DAFTAR-SAHAM');
        $this->finalizer->finalizeFromContent($document, 77);

        $response = $this->get("/verify/{$document->id}/attachments/{$attachment->id}");

        $response->assertOk();
        $this->assertSame('ISI-DAFTAR-SAHAM', $response->streamedContent());
    }

    #[Test]
    public function dokumen_draft_tampil_belum_diterbitkan_tanpa_isi(): void
    {
        $document = $this->makeLetter(['subject' => 'Rahasia Negara Belum Jadi']);

        $response = $this->get("/verify/{$document->id}");

        $response->assertOk();
        $response->assertSee('badge--belum', false);
        $response->assertSee('Belum diterbitkan');
        // Nomor boleh tampil, isi (perihal/metode/unduhan) tidak.
        $response->assertSee($document->number);
        $response->assertDontSee('Rahasia Negara Belum Jadi');
        $response->assertDontSee('Elektronik');
        $response->assertDontSee("/verify/{$document->id}/file", false);
    }

    #[Test]
    public function uuid_acak_mendapat_404_netral(): void
    {
        $response = $this->get('/verify/'.Str::uuid());

        $response->assertNotFound();
        $response->assertSee('Dokumen tidak ditemukan');
        // Netral: tidak ada struktur DB / stack trace / detail teknis.
        $this->assertStringNotContainsStringIgnoringCase('sqlstate', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('exception', $response->getContent());
    }

    #[Test]
    public function dokumen_rahasia_terbit_tanpa_unduhan_dan_file_ditolak(): void
    {
        $document = $this->makeLetter(['classification' => 'rahasia', 'subject' => 'Perihal Sangat Rahasia']);
        $attachment = $this->attachFile($document, 'Lampiran Rahasia', 'ISI-RAHASIA');
        $this->finalizer->finalizeFromContent($document, 77);

        $page = $this->get("/verify/{$document->id}");

        $page->assertOk();
        $page->assertSee('badge--sah', false);
        $page->assertSee('rahasia');
        $page->assertDontSee('Perihal Sangat Rahasia');
        $page->assertDontSee("/verify/{$document->id}/file", false);
        $page->assertDontSee("/verify/{$document->id}/attachments/{$attachment->id}", false);

        $this->get("/verify/{$document->id}/file")->assertForbidden();
        $this->get("/verify/{$document->id}/attachments/{$attachment->id}")->assertForbidden();
    }

    #[Test]
    public function unduhan_file_dokumen_belum_terbit_ditolak_404_netral(): void
    {
        $document = $this->makeLetter(); // masih draft

        $this->get("/verify/{$document->id}/file")
            ->assertNotFound()
            ->assertSee('Dokumen tidak ditemukan');
    }

    #[Test]
    public function reissue_menampilkan_riwayat_dua_terbitan_dengan_alasan(): void
    {
        $document = $this->makeLetter();
        $this->finalizer->finalizeFromContent($document, 77);
        $this->finalizer->reissue($document, 'a', fn (): string => '%PDF-1.4 versi perbaikan', 'koreksi tanggal surat', 77);

        $response = $this->get("/verify/{$document->id}");

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), 'class="riwayat-item"'), 'riwayat memuat dua terbitan');
        $response->assertSee('berlaku');
        $response->assertSee('digantikan');
        $response->assertSee('koreksi tanggal surat');
    }

    #[Test]
    public function dokumen_dicabut_menampilkan_badge_dicabut_dan_alasan(): void
    {
        $document = $this->makeLetter([], 'KUA');
        $this->finalizer->finalizeFromContent($document, 77);

        $document->status = 'dicabut';
        $document->revoked_at = now();
        $document->revocation_reason = 'Kuasa ditarik kembali oleh pemberi kuasa';
        $document->save();

        $response = $this->get("/verify/{$document->id}");

        $response->assertOk();
        $response->assertSee('badge--dicabut', false);
        $response->assertSee('DICABUT');
        $response->assertSee('Kuasa ditarik kembali oleh pemberi kuasa');
        // Sudah dicabut: unduhan ditutup.
        $this->get("/verify/{$document->id}/file")->assertNotFound();
    }

    #[Test]
    public function dokumen_dibatalkan_menampilkan_badge_merah_dan_alasan(): void
    {
        $document = $this->makeLetter();
        $this->finalizer->finalizeFromContent($document, 77);

        $document->status = 'dibatalkan';
        $document->save();
        (new AuditLogger)->log($document, AuditLogger::EVENT_VOIDED, 77, ['reason' => 'salah penerima surat']);

        $response = $this->get("/verify/{$document->id}");

        $response->assertOk();
        $response->assertSee('badge--dibatalkan', false);
        $response->assertSee('DIBATALKAN');
        $response->assertSee('salah penerima surat');
    }

    #[Test]
    public function hit_ke_31_dalam_satu_menit_ditolak_429(): void
    {
        $document = $this->makeLetter();
        $this->finalizer->finalizeFromContent($document, 77);

        for ($i = 1; $i <= 30; $i++) {
            $this->get("/verify/{$document->id}")->assertOk();
        }

        $this->get("/verify/{$document->id}")->assertStatus(429);
    }

    #[Test]
    public function segmen_lampiran_non_uuid_ditolak_route_sebelum_query_db(): void
    {
        $document = $this->makeLetter();
        $this->finalizer->finalizeFromContent($document, 77);

        $response = $this->get("/verify/{$document->id}/attachments/1");

        // Constraint whereUuid harus menolak non-UUID SEBELUM controller
        // menjalankan whereKey() pada kolom uuid PK — di Postgres produksi
        // query itu melempar 'invalid input syntax for type uuid' (500),
        // melanggar mandat 404 netral. Proxy yang terdeteksi di SQLite:
        // request TIDAK boleh sampai ke halaman netral controller (yang
        // menandakan query non-uuid sudah dieksekusi) — harus 404 dari
        // penolakan route.
        $response->assertNotFound();
        $this->assertStringNotContainsStringIgnoringCase('sqlstate', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('exception', $response->getContent());
        $response->assertDontSee('Dokumen tidak ditemukan');
    }

    #[Test]
    public function setiap_hit_halaman_mencatat_verification_hit_dan_audit(): void
    {
        $document = $this->makeLetter();
        $this->finalizer->finalizeFromContent($document, 77);

        $this->get("/verify/{$document->id}")->assertOk();

        $this->assertSame(1, VerificationHit::query()->where('document_id', $document->id)->count());

        $log = AuditLog::query()
            ->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_VERIFIED_PUBLIC)
            ->firstOrFail();
        $this->assertSame("verify/{$document->id}", $log->payload['path']);
    }

    // ——— helper ———

    /**
     * Dokumen surat standar (kode jenis 'ST' atau kustom, unit 'DIR'),
     * nomor terisi dari factory.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeLetter(array $overrides = [], string $code = 'ST'): Document
    {
        return Document::factory()->create($overrides + [
            'letter_type_id' => LetterType::factory()->create(['code' => $code]),
            'unit' => 'DIR',
            'year' => now()->year,
            'subject' => 'Surat Kuasa Pengangkatan',
            'body_html' => '<p>Dengan hormat,</p>',
        ]);
    }

    /**
     * Lampiran dengan berkas fisik di disk documents.
     */
    private function attachFile(Document $document, string $label, string $bytes): DocumentAttachment
    {
        $attachment = DocumentAttachment::factory()->create([
            'document_id' => $document->id,
            'label' => $label,
            'mime' => 'application/pdf',
        ]);
        Storage::disk('documents')->put($attachment->path, $bytes);

        return $attachment;
    }
}
