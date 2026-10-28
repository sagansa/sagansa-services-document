<?php

namespace Tests\Feature;

use App\Exceptions\DocumentLockedException;
use App\Exceptions\PdfInvalidException;
use App\Exceptions\SigningIncompleteException;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Issuance;
use App\Models\LetterType;
use App\Models\SigningRequest;
use App\Services\AuditLogger;
use App\Services\Finalizer;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesDocumentSchema;
use Tests\Support\DecodesPdfStreams;
use Tests\TestCase;

/**
 * Finalizer — jantung integritas penerbitan surat.
 *
 * Empat jalur publik (finalizeFromContent/Stamps/Upload + reissue)
 * bermuara ke satu method privat persist yang menjalankan SEMUA mutasi
 * dalam satu transaksi pada koneksi mysql_document dengan baris surat
 * dikunci (SELECT ... FOR UPDATE): guard status + signing request,
 * auto-reserve nomor, insert issuance, nonaktifkan issuance aktif lama,
 * tulis berkas ke disk documents, status terbit, jejak audit.
 */
class FinalizerTest extends TestCase
{
    use CreatesDocumentSchema, DecodesPdfStreams;

    private Finalizer $finalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
        Storage::fake('documents');
        $this->finalizer = new Finalizer;
    }

    #[Test]
    public function finalize_from_content_issues_exactly_one_active_issuance(): void
    {
        $document = $this->makeLetter();
        $attachment = DocumentAttachment::factory()->create(['document_id' => $document->id]);

        $issuance = $this->finalizer->finalizeFromContent($document, 77);

        // Tepat satu issuance aktif; baris issuance lengkap.
        $this->assertSame(1, Issuance::query()->where('document_id', $document->id)->where('is_active', true)->count());
        $this->assertSame('a', $issuance->method);
        $this->assertSame('elektronik', $issuance->method_label);
        $this->assertTrue($issuance->is_active);
        $this->assertSame(77, $issuance->finalized_by);
        $this->assertNotNull($issuance->finalized_at);
        $this->assertNull($issuance->replaced_by_id);

        // Hash berkas: 64 karakter hex dan sama dengan sha256 byte tersimpan.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issuance->file_sha256);
        $stored = Storage::disk('documents')->get($issuance->file_path);
        $this->assertSame(hash('sha256', $stored), $issuance->file_sha256);
        $this->assertSame(strlen((string) $stored), $issuance->file_size);

        // Hash lampiran: sha256 dari json daftar sha256 lampiran terurut.
        $this->assertSame(
            hash('sha256', json_encode([$attachment->sha256])),
            $issuance->attachments_sha256,
        );

        // Berkas fisik tersimpan di pola {yyyy}/{docId}/issuance-{uuid}.pdf.
        $this->assertMatchesRegularExpression(
            '#^'.$document->year.'/'.$document->id.'/issuance-[0-9a-f-]{36}\.pdf$#',
            $issuance->file_path,
        );
        Storage::disk('documents')->assertExists($issuance->file_path);

        // Status surat naik ke terbit.
        $this->assertSame('terbit', Document::query()->findOrFail($document->id)->status);

        // Jejak audit finalized tercatat dengan actor yang diberikan.
        $log = AuditLog::query()->where('document_id', $document->id)->where('event', AuditLogger::EVENT_FINALIZED)->firstOrFail();
        $this->assertSame(77, $log->actor_user_id);
        $this->assertSame($issuance->id, $log->payload['issuance_id']);
    }

    #[Test]
    public function finalize_rejects_already_published_document(): void
    {
        $document = $this->makeLetter(['status' => 'terbit']);
        Issuance::factory()->create(['document_id' => $document->id]);

        $this->expectException(DocumentLockedException::class);

        $this->finalizer->finalizeFromContent($document, 77);
    }

    #[Test]
    public function finalize_rejects_when_signing_request_still_pending(): void
    {
        $document = $this->makeLetter();
        SigningRequest::factory()->status('diminta')->create(['document_id' => $document->id]);

        $this->expectException(SigningIncompleteException::class);

        try {
            $this->finalizer->finalizeFromContent($document, 77);
        } finally {
            // Transaksi bersih: tidak ada issuance tertulis.
            $this->assertSame(0, Issuance::query()->where('document_id', $document->id)->count());
            $this->assertSame('draft', $document->fresh()->status);
        }
    }

    #[Test]
    public function finalize_from_upload_rejects_non_pdf_bytes(): void
    {
        $document = $this->makeLetter();

        $this->expectException(PdfInvalidException::class);

        $this->finalizer->finalizeFromUpload($document, 'INI BUKAN PDF', 'c', 77);
    }

    #[Test]
    public function finalize_from_upload_rejects_non_cd_method(): void
    {
        $document = $this->makeLetter();

        $this->expectException(InvalidArgumentException::class);

        $this->finalizer->finalizeFromUpload($document, '%PDF-1.4 ...', 'a', 77);
    }

    #[Test]
    public function finalize_from_upload_with_method_d_persists_manual_issuance(): void
    {
        $document = $this->makeLetter();
        $bytes = '%PDF-1.4 dummy manual upload';

        $issuance = $this->finalizer->finalizeFromUpload($document, $bytes, 'd', 77);

        $this->assertSame('d', $issuance->method);
        $this->assertSame('manual', $issuance->method_label);
        $this->assertSame(hash('sha256', $bytes), $issuance->file_sha256);
        $this->assertSame('terbit', $document->fresh()->status);
        Storage::disk('documents')->assertExists($issuance->file_path);
    }

    #[Test]
    public function failure_after_file_write_cleans_up_orphan_file_and_rethrows(): void
    {
        $document = $this->makeLetter();

        // Audit insert dipaksa gagal — terjadi SETELAH Storage::put sukses
        // (urutan persist: insert issuance → put file → status → audit).
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('log')->willThrowException(new RuntimeException('audit insert gagal'));
        $finalizer = new Finalizer(audit: $audit);

        try {
            $finalizer->finalizeFromUpload($document, '%PDF-1.4 akan gagal di audit', 'd', 77);
            $this->fail('Exception dari audit harus terlempar keluar.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit insert gagal', $e->getMessage(), 'exception ASLI yang rethrow, bukan yang lain');
        }

        // Berkas yatim dibersihkan dari disk documents.
        $this->assertSame(
            [],
            Storage::disk('documents')->allFiles(),
            'tidak boleh ada berkas tersisa setelah transaksi gagal',
        );

        // Dan DB tetap konsisten (rollback): tanpa issuance, status draft.
        $this->assertSame(0, Issuance::query()->where('document_id', $document->id)->count());
        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('document_id', $document->id)->count());
    }

    #[Test]
    public function reissue_replaces_active_issuance_and_records_note_in_audit(): void
    {
        $document = $this->makeLetter(['status' => 'terbit']);
        $old = Issuance::factory()->create(['document_id' => $document->id, 'is_active' => true]);
        Storage::disk('documents')->put($old->file_path, '%PDF-1.4 versi lama');
        $bytes = '%PDF-1.4 versi perbaikan';

        $new = $this->finalizer->reissue($document, 'a', fn (): string => $bytes, 'koreksi tanggal surat', 77);

        // Issuance lama dinonaktifkan dan menunjuk pengganti.
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame($new->id, $old->fresh()->replaced_by_id);

        // Tepat satu aktif; issuance baru membawa note.
        $this->assertSame(1, Issuance::query()->where('document_id', $document->id)->where('is_active', true)->count());
        $this->assertTrue($new->is_active);
        $this->assertSame('koreksi tanggal surat', $new->note);
        $this->assertSame(hash('sha256', $bytes), $new->file_sha256);
        Storage::disk('documents')->assertExists($new->file_path);
        Storage::disk('documents')->assertExists($old->file_path);

        // Jejak audit reissued memuat note + method.
        $log = AuditLog::query()->where('document_id', $document->id)->where('event', AuditLogger::EVENT_REISSUED)->firstOrFail();
        $this->assertSame('koreksi tanggal surat', $log->payload['note']);
        $this->assertSame('a', $log->payload['method']);
        $this->assertSame(77, $log->actor_user_id);

        // Status tetap terbit, bukan naik-turun ulang.
        $this->assertSame('terbit', $document->fresh()->status);
    }

    #[Test]
    public function reissue_rejects_document_not_yet_published(): void
    {
        $document = $this->makeLetter(['status' => 'draft']);

        $this->expectException(DocumentLockedException::class);

        $this->finalizer->reissue($document, 'a', fn (): string => '%PDF-1.4', 'koreksi', 77);
    }

    #[Test]
    public function reissue_requires_non_empty_note(): void
    {
        $document = $this->makeLetter(['status' => 'terbit']);

        $this->expectException(InvalidArgumentException::class);

        $this->finalizer->reissue($document, 'a', fn (): string => '%PDF-1.4', '  ', 77);
    }

    #[Test]
    public function finalize_reserves_letter_number_when_document_has_none(): void
    {
        // Skema melarang number NULL — draft pra-nomor memakai string kosong.
        $document = $this->makeLetter(['number' => '', 'seq' => 0]);

        $issuance = $this->finalizer->finalizeFromUpload($document, '%PDF-1.4 auto nomor', 'c', 77);

        $fresh = $document->fresh();
        $this->assertMatchesRegularExpression(
            '/^\d{3}\/'.$document->letterType->code.'\/'.$document->unit.'\/[IVX]+\/'.$document->year.'$/',
            $fresh->number,
        );
        $this->assertSame(1, $fresh->seq);
        $this->assertSame('ttd_elektronik_resmi', $issuance->method_label);

        // Nomor lama (kosong) tertimpa nomor cadangan — counter terpakai.
        $this->assertNotSame('', $fresh->number);
    }

    #[Test]
    public function finalize_keeps_existing_number_when_document_already_numbered(): void
    {
        $document = $this->makeLetter(); // factory mengisi number/seq

        $this->finalizer->finalizeFromUpload($document, '%PDF-1.4 nomor sudah ada', 'c', 77);

        $fresh = $document->fresh();
        $this->assertSame($document->number, $fresh->number);
        $this->assertSame($document->seq, $fresh->seq);
        $this->assertDatabaseCount('number_sequences', 0, 'mysql_document');
    }

    /**
     * Fix C1 — metode a: nomor dicadangkan SEBELUM render sehingga
     * benar-benar tercetak di PDF terbitan (dulu reserve terjadi di
     * persist SETELAH bytes jadi → number null saat render).
     */
    #[Test]
    public function finalize_from_content_prints_reserved_number_inside_pdf_bytes(): void
    {
        // Pra-nomor: NULL (bukan '') — bentuk lahir dari DocumentController.
        $document = $this->makeLetter(['number' => null, 'seq' => 0]);

        $issuance = $this->finalizer->finalizeFromContent($document, 77);

        $fresh = $document->fresh();
        $this->assertMatchesRegularExpression(
            '/^\d{3}\/ST\/DIR\/[IVX]+\/'.$document->year.'$/',
            $fresh->number,
            'surat harus bernomor setelah finalize',
        );

        // Bytes di disk memuat nomor yang sama (content stream di-inflate,
        // pola PdfRendererTest — font base-14 terbaca literal).
        $stored = (string) Storage::disk('documents')->get($issuance->file_path);
        $this->assertStringContainsString(
            $fresh->number,
            $this->decodedStreams($stored),
            'nomor surat harus tercetak di PDF metode a',
        );
    }

    /**
     * Dokumen surat standar: kode jenis 'ST', unit 'DIR', tahun berjalan,
     * body sederhana — nomor dari factory (sudah terisi) kecuali diubah.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeLetter(array $overrides = []): Document
    {
        return Document::factory()->create($overrides + [
            'letter_type_id' => LetterType::factory()->create(['code' => 'ST']),
            'unit' => 'DIR',
            'year' => now()->year,
            'subject' => 'Surat Kuasa Pengangkatan',
            'body_html' => '<p>Dengan hormat,</p>',
        ]);
    }
}
