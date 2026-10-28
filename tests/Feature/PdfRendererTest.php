<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Signer;
use App\Models\SigningRequest;
use App\Services\PdfRenderer;
use Illuminate\Support\Facades\Log;
use Tests\Support\CreatesDocumentSchema;
use Tests\Support\MakesSignaturePng;
use Tests\TestCase;

/**
 * PdfRenderer::render() — surat → PDF A4 (dompdf, Mode A).
 *
 * Binary PDF diverifikasi dari properti binernya: magic byte %PDF,
 * ukuran > 5000 byte, dan konten stream yang didekompres (FlateDecode
 * via gzinflate) untuk memastikan teks surat, kop, footer nomor
 * halaman, nama penandatangan, dan gambar QR benar-benar dirender.
 * Teks di template memakai font base-14 (Helvetica) sehingga string
 * literal tetap terbaca di content stream setelah inflate.
 */
class PdfRendererTest extends TestCase
{
    use CreatesDocumentSchema, MakesSignaturePng;

    private PdfRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
        $this->renderer = new PdfRenderer;
    }

    public function test_render_returns_pdf_binary_larger_than_5000_bytes(): void
    {
        $document = $this->makeLetter();

        $pdf = $this->renderer->render($document);

        $this->assertSame('%PDF', substr($pdf, 0, 4), 'output harus diawali magic byte %PDF');
        $this->assertGreaterThan(5000, strlen($pdf), 'PDF berisi kop + QR 300px harus > 5000 byte');
    }

    public function test_render_contains_letter_number_kop_and_body(): void
    {
        $document = $this->makeLetter();

        $text = $this->decodedStreams($this->renderer->render($document));

        $this->assertStringContainsString('PT SAGANSA', $text, 'kop surat harus dirender');
        $this->assertStringContainsString('001/ST/DIR/X/2026', $text, 'nomor surat dari kolom number maupun body harus muncul');
        $this->assertStringContainsString('Surat Kuasa Pengangkatan', $text, 'perihal (Hal) harus muncul');
        $this->assertStringContainsString('Dengan hormat', $text, 'body_html harus dirender');
    }

    public function test_render_translates_date_to_indonesian_locale(): void
    {
        $document = $this->makeLetter();

        $text = $this->decodedStreams($this->renderer->render($document));

        $this->assertStringContainsString('26 Oktober 2026', $text, 'tanggal harus dalam locale id (Oktober, bukan October)');
        $this->assertStringNotContainsString('October', $text);
    }

    public function test_render_embeds_qr_image_and_verification_label(): void
    {
        $document = $this->makeLetter();

        $pdf = $this->renderer->render($document);

        $this->assertStringContainsString('/Image', $pdf, 'QR PNG harus ter-embed sebagai image XObject');

        // Label QR kecil bisa wrap ke beberapa baris content stream,
        // jadi diassert per kata.
        $text = $this->decodedStreams($pdf);
        foreach (['Pindai', 'verifikasi', 'keaslian', 'surat'] as $word) {
            $this->assertStringContainsString($word, $text, "label verifikasi QR harus memuat kata '{$word}'");
        }
    }

    public function test_render_prints_page_number_footer_on_each_page(): void
    {
        $document = $this->makeLetter();

        $text = $this->decodedStreams($this->renderer->render($document));

        $this->assertStringContainsString('Halaman 1 dari 1', $text, 'footer nomor halaman harus ditulis per halaman');
    }

    public function test_render_shows_attachment_count(): void
    {
        $document = $this->makeLetter();
        DocumentAttachment::factory()->count(2)->create(['document_id' => $document->id]);

        $text = $this->decodedStreams($this->renderer->render($document));

        $this->assertStringContainsString('2 berkas', $text);
    }

    public function test_render_includes_creator_signer_and_approved_signing_request_signers(): void
    {
        $creator = Signer::factory()->role('direktur')->create([
            'display_name' => 'Andi Wijaya',
            'title' => 'Direktur Utama',
        ]);
        $approved = Signer::factory()->create(['display_name' => 'Bunga Lestari']);
        $rejected = Signer::factory()->create(['display_name' => 'Citra Oniel']);

        $document = $this->makeLetter(['created_by' => $creator->user_id]);
        SigningRequest::factory()->status('disetujui')->create([
            'document_id' => $document->id,
            'signer_id' => $approved->id,
        ]);
        SigningRequest::factory()->status('ditolak')->create([
            'document_id' => $document->id,
            'signer_id' => $rejected->id,
        ]);

        $text = $this->decodedStreams($this->renderer->render($document));

        $this->assertStringContainsString('Andi Wijaya', $text, 'signer milik pembuat surat harus tampil');
        $this->assertStringContainsString('Direktur Utama', $text, 'jabatan signer pembuat harus tampil');
        $this->assertStringContainsString('Bunga Lestari', $text, 'signer signing request disetujui harus tampil');
        $this->assertStringNotContainsString('Citra Oniel', $text, 'signer signing request ditolak tidak boleh tampil');
    }

    public function test_render_deduplicates_creator_signer_also_approved(): void
    {
        $creator = Signer::factory()->role('direktur')->create([
            'display_name' => 'Andi Wijaya',
        ]);

        $document = $this->makeLetter(['created_by' => $creator->user_id]);
        SigningRequest::factory()->status('disetujui')->create([
            'document_id' => $document->id,
            'signer_id' => $creator->id,
        ]);

        $text = $this->decodedStreams($this->renderer->render($document));

        $this->assertSame(1, substr_count($text, 'Andi Wijaya'), 'signer yang sama tidak boleh tampil dua kali');
    }

    public function test_render_falls_back_to_generic_direktur_label_when_creator_has_no_signer_row(): void
    {
        // created_by acak tanpa baris signers → fallback label generik,
        // TANPA query lintas DB ke sagansa_user.
        $document = $this->makeLetter();

        $text = $this->decodedStreams($this->renderer->render($document));

        $this->assertStringContainsString('Direktur', $text, 'fallback label Direktur generik harus tampil');
    }

    #[Test]
    public function render_embeds_stored_signature_asset_in_ttd_slot(): void
    {
        // Tanpa aset: hanya QR yang ter-embed sebagai image XObject.
        $plain = $this->makeLetter();
        $baselineImages = substr_count($this->renderer->render($plain), '/Subtype /Image');

        $signer = Signer::factory()->role('direktur')->create([
            'display_name' => 'Andi Wijaya',
            'signature_asset' => $this->signaturePngBase64(),
        ]);
        $document = $this->makeLetter(['created_by' => $signer->user_id]);

        $pdf = $this->renderer->render($document);

        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertGreaterThan(
            $baselineImages,
            substr_count($pdf, '/Subtype /Image'),
            'aset TTD harus menjadi image XObject tambahan di blok penandatangan',
        );

        $text = $this->decodedStreams($pdf);
        $this->assertStringContainsString('Andi Wijaya', $text, 'nama penandatangan tetap tampil di bawah aset');
    }

    #[Test]
    public function render_falls_back_to_placeholder_and_logs_warning_for_corrupt_asset(): void
    {
        Log::spy();

        $plain = $this->makeLetter();
        $baselineImages = substr_count($this->renderer->render($plain), '/Subtype /Image');

        $signer = Signer::factory()->role('direktur')->create([
            'display_name' => 'Andi Wijaya',
            'signature_asset' => base64_encode('bukan-png'),
        ]);
        $document = $this->makeLetter(['created_by' => $signer->user_id]);

        $pdf = $this->renderer->render($document);

        // Fail-soft: PDF tetap terbit, slot kembali jadi kotak placeholder.
        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertSame(
            $baselineImages,
            substr_count($pdf, '/Subtype /Image'),
            'aset korup tidak boleh menambah image XObject',
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'tidak dapat dirender')
                && ($context['signer_id'] ?? null) === $signer->id);
    }

    /**
     * Dokumen surat standar untuk test dengan nomor surat brief
     * 001/ST/DIR/X/2026 dan body yang menyebut nomor tersebut.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeLetter(array $overrides = []): Document
    {
        return Document::factory()->create($overrides + [
            'number' => '001/ST/DIR/X/2026',
            'subject' => 'Surat Kuasa Pengangkatan',
            'date' => '2026-10-26',
            'body_html' => '<p>Dengan hormat,</p><p>Sehubungan dengan surat nomor <strong>001/ST/DIR/X/2026</strong>, dengan ini kami menetapkan hal-hal berikut.</p>',
        ]);
    }

    /**
     * Gabungan seluruh content stream PDF setelah dekompresi
     * FlateDecode — CPDF menulis stream ber-header zlib (0x789c)
     * sehingga didekompres dengan gzuncompress. String literal teks
     * base-14 terbaca di sini.
     */
    private function decodedStreams(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        $decoded = '';
        foreach ($matches[1] as $chunk) {
            $uncompressed = @gzuncompress($chunk);
            $decoded .= $uncompressed === false ? $chunk : $uncompressed;
        }

        return $decoded;
    }
}
