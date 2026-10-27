<?php

namespace Tests\Feature;

use App\Exceptions\PdfUnsupportedException;
use App\Services\PdfStamper;
use App\Services\QrBuilder;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PdfStamper::stamp() — overlay gambar (PNG QR) ke PDF existing.
 *
 * Fixture:
 * - sample_pdf14.pdf — 2 halaman A4 via FPDF (classic xref, header %PDF-1.4),
 *   dapat dibaca parser gratis FPDI.
 * - sample_pdf17.pdf — 1 halaman valid dengan cross-reference STREAM (fitur
 *   PDF 1.5+): diverifikasi TIDAK bisa dibaca parser gratis FPDI (lempar
 *   CrossReferenceException COMPRESSED_XREF) namun valid bagi renderer lain.
 *
 * Asersi halaman/koordinat mem-parse output FPDF sendiri: objek halaman
 * `/Type /Page ... /Contents N 0 R` di-link ke content stream obj N yang
 * di-compress FPDF (FlateDecode) lalu di-decode — jadi asersi berlaku pada
 * operator PDF asli, bukan substring mentah.
 */
class PdfStamperTest extends TestCase
{
    private PdfStamper $stamper;

    private string $pdf14;

    private string $pdf17;

    private string $qrPng;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stamper = new PdfStamper;
        $this->pdf14 = file_get_contents($this->fixture('sample_pdf14.pdf'));
        $this->pdf17 = file_get_contents($this->fixture('sample_pdf17.pdf'));
        $this->qrPng = (new QrBuilder)->build('550e8400-e29b-41d4-a716-446655440000');
    }

    public function test_stamp_pdf14_returns_valid_bigger_pdf_containing_image(): void
    {
        $out = $this->stamper->stamp($this->pdf14, [$this->overlay(2)]);

        $this->assertStringStartsWith('%PDF', $out, 'output harus PDF valid');
        $this->assertGreaterThan(
            strlen($this->pdf14),
            strlen($out),
            'output harus lebih besar dari input (ada gambar QR tertanam)',
        );
        $this->assertStringContainsString('/Subtype /Image', $out, 'asset gambar overlay harus ada di output');
    }

    public function test_stamp_pdf14_preserves_page_count(): void
    {
        $out = $this->stamper->stamp($this->pdf14, [$this->overlay(2)]);

        // Hitung ulang via parser FPDI sendiri (bukan substring count kasar):
        // output FPDF adalah classic xref sehingga selalu bisa dibaca ulang.
        $tmp = tempnam(sys_get_temp_dir(), 'sagansa_stamped_');
        file_put_contents($tmp, $out);

        $this->assertSame(2, (new Fpdi)->setSourceFile($tmp), 'jumlah halaman harus tetap 2');
        unlink($tmp);
    }

    public function test_stamp_pdf14_draws_overlay_only_on_requested_page(): void
    {
        $out = $this->stamper->stamp($this->pdf14, [$this->overlay(2)]);

        $contents = $this->pageContentStreams($out);
        $this->assertCount(2, $contents, 'harus ada 2 content stream halaman');

        $this->assertMatchesRegularExpression('#/I\d+ Do#', $contents[1], 'operator gambar /I harus ada di halaman 2');
        $this->assertDoesNotMatchRegularExpression(
            '#/I\d+ Do#',
            $contents[0],
            'halaman 1 tidak boleh menggambar overlay yang diminta untuk halaman 2',
        );
    }

    /**
     * Kontrak origin kiri-bawah + satuan mm: FPDF menulis operator
     * `q w_pt 0 0 h_pt x_pt y_pt cm /I1 Do` pada ruang koordinat PDF yang
     * berorigin KIRI-BAWAH dalam point — jadi angka x_pt/y_pt di content
     * stream persis sama dengan x_mm/y_mm overlay dikonversi mm→pt
     * (1mm = 2.8346pt), tanpa perlu tahu tinggi halaman.
     */
    public function test_stamp_pdf14_places_overlay_at_bottom_left_coordinates(): void
    {
        $out = $this->stamper->stamp($this->pdf14, [
            $this->overlay(2, xMm: 20.0, yMm: 10.0, widthMm: 30.0),
        ]);

        $contents = $this->pageContentStreams($out);
        $this->assertMatchesRegularExpression(
            '#q (\d+\.\d+) 0 0 (\d+\.\d+) (\d+\.\d+) (\d+\.\d+) cm /I\d+ Do#',
            $contents[1],
            'operator cm gambar tidak ditemukan di content halaman 2',
        );
        preg_match('#q (\d+\.\d+) 0 0 (\d+\.\d+) (\d+\.\d+) (\d+\.\d+) cm /I\d+ Do#', $contents[1], $m);

        [$wPt, $xPt, $yPt] = [(float) $m[1], (float) $m[3], (float) $m[4]];
        $this->assertEqualsWithDelta(30.0 * 72 / 25.4, $wPt, 0.2, 'lebar 30mm harus jadi ~85.04pt (QR persegi)');
        $this->assertEqualsWithDelta(20.0 * 72 / 25.4, $xPt, 0.2, 'x 20mm dari kiri');
        $this->assertEqualsWithDelta(10.0 * 72 / 25.4, $yPt, 0.2, 'y 10mm dari BAWAH halaman (origin kiri-bawah)');
    }

    /**
     * Overlay teks (Fix C1): "No. 001/ST/DIR/X/2026" 8pt rata tengah —
     * operator `BT /F1 8.00 Tf ... (No. ...) Tj ET` di content stream.
     */
    public function test_stamp_draws_text_overlay_on_requested_page(): void
    {
        $out = $this->stamper->stamp($this->pdf14, [
            ['text' => 'No. 001/ST/DIR/X/2026', 'page' => 1, 'x_mm' => 165.0, 'y_mm' => 42.0, 'width_mm' => 25.0, 'font_size' => 8],
            $this->overlay(2),
        ]);

        $contents = $this->pageContentStreams($out);

        $this->assertStringContainsString('No. 001/ST/DIR/X/2026', $contents[0], 'teks nomor harus tergambar di halaman 1');
        $this->assertStringContainsString('/F1 8.00 Tf', $contents[0], 'font size 8pt harus dipakai');
        $this->assertStringNotContainsString('No. 001/ST/DIR/X/2026', $contents[1], 'teks hanya di halaman yang diminta');
        $this->assertMatchesRegularExpression('#/I\d+ Do#', $contents[1], 'overlay image di halaman 2 tetap tergambar');
    }

    public function test_stamp_rejects_overlay_without_image_or_text_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stamper->stamp($this->pdf14, [['page' => 1, 'x_mm' => 10, 'y_mm' => 10, 'width_mm' => 20]]);
    }

    public function test_stamp_rejects_blank_text_overlay(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stamper->stamp($this->pdf14, [['text' => '  ', 'page' => 1, 'x_mm' => 10, 'y_mm' => 10, 'width_mm' => 20]]);
    }

    public function test_stamp_rejects_overlay_for_missing_page(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stamper->stamp($this->pdf14, [$this->overlay(3)]);
    }

    public function test_stamp_rejects_overlay_without_required_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->stamper->stamp($this->pdf14, [['image' => $this->qrPng, 'page' => 1, 'x_mm' => 10, 'y_mm' => 10]]);
    }

    public function test_stamp_pdf17_without_qpdf_binary_throws_pdf_unsupported(): void
    {
        config(['document.qpdf_binary' => '/nonexistent/qpdf']);

        $this->expectException(PdfUnsupportedException::class);
        $this->expectExceptionMessageMatches('/qpdf/');

        $this->stamper->stamp($this->pdf17, [$this->overlay(1)]);
    }

    /**
     * Jalur happy fallback: PDF 1.7 (xref stream) dinormalisasi qpdf
     * (--object-streams=disable → classic xref) lalu di-stamp ulang.
     * qpdf sengaja tidak diinstal di mesin dev — test skip bila tidak ada.
     */
    public function test_stamp_pdf17_with_qpdf_normalizes_and_stamps(): void
    {
        $which = new Process(['which', 'qpdf']);
        $which->run();
        $qpdf = trim($which->getOutput());
        if (! $which->isSuccessful() || $qpdf === '') {
            $this->markTestSkipped('qpdf tidak terpasang di mesin ini — jalur fallback tidak bisa diuji end-to-end.');
        }
        config(['document.qpdf_binary' => $qpdf]);

        $out = $this->stamper->stamp($this->pdf17, [$this->overlay(1)]);

        $this->assertStringStartsWith('%PDF', $out);
        $this->assertGreaterThan(strlen($this->pdf17), strlen($out));
        $this->assertStringContainsString('/Subtype /Image', $out);
    }

    public function test_document_config_declares_qpdf_binary_default(): void
    {
        // QPDF_BINARY tidak di-set di phpunit.xml → default 'qpdf' berlaku.
        $this->assertSame('qpdf', config('document.qpdf_binary'));
    }

    /**
     * Overlay QR di halaman tertentu; koordinat mm origin kiri-bawah.
     *
     * @return array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}
     */
    private function overlay(int $page, float $xMm = 15.0, float $yMm = 15.0, float $widthMm = 30.0): array
    {
        return [
            'image' => $this->qrPng,
            'page' => $page,
            'x_mm' => $xMm,
            'y_mm' => $yMm,
            'width_mm' => $widthMm,
        ];
    }

    private function fixture(string $file): string
    {
        return dirname(__DIR__).'/fixtures/'.$file;
    }

    /**
     * Content stream tiap halaman (urut) dari output FPDF: objek halaman
     * `<</Type /Page\n.../Contents N 0 R>>` diikuti objek content N yang
     * di-compress FlateDecode — di-decode agar asersi membaca operator PDF
     * asli. Format ini deterministik untuk output PdfStamper sendiri.
     *
     * @return list<string>
     */
    private function pageContentStreams(string $pdfBytes): array
    {
        preg_match_all(
            '#(\d+) 0 obj\n<</Type /Page\n.*?/Contents (\d+) 0 R>>\nendobj#s',
            $pdfBytes,
            $pages,
            PREG_SET_ORDER,
        );

        $streams = [];
        foreach ($pages as $page) {
            $pattern = sprintf('#%d 0 obj\n<<(.*?)>>\nstream\n(.*?)\nendstream#s', $page[2]);
            $this->assertMatchesRegularExpression(
                $pattern,
                $pdfBytes,
                "content stream obj {$page[2]} (halaman ".(count($streams) + 1).') tidak ditemukan',
            );
            preg_match($pattern, $pdfBytes, $stream);

            $decoded = zlib_decode($stream[2]);
            $this->assertNotFalse($decoded, 'content stream halaman harus ter-decode FlateDecode');

            $streams[] = $decoded;
        }

        return $streams;
    }
}
