<?php

namespace App\Services;

use App\Exceptions\PdfUnsupportedException;
use InvalidArgumentException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use Symfony\Component\Process\Process;

/**
 * Penempel overlay ke PDF existing — Mode B/C pra-final.
 *
 * Dua bentuk overlay (dipilih dari kunci yang ada):
 *
 *   image (PNG, mis. QR tanda tangan):
 *   ['image' => binary PNG, 'page' => int 1-based, 'x_mm' => float,
 *    'y_mm' => float, 'width_mm' => float]
 *   Tinggi tampilan mengikuti rasio piksel PNG.
 *
 *   text (mis. "No. 001/ST/DIR/X/2026" di atas QR default — Fix C1):
 *   ['text' => string, 'page' => int 1-based, 'x_mm' => float,
 *    'y_mm' => float, 'width_mm' => float, 'font_size' => float]
 *   Helvetica core font (tanpa embed tambahan), rata tengah dalam
 *   lebar; font_size dalam POINT (default 8).
 *
 * Koordinat dalam mm, origin KIRI-BAWAH halaman; y_mm adalah jarak tepi
 * BAWAH overlay (tepi bawah gambar / tepi bawah sel teks) dari tepi
 * bawah halaman.
 *
 * PDF sumber >1.4 yang memakai xref stream / object stream tidak terbaca
 * parser gratis FPDI — bila parsing gagal, dokumen dinormalisasi dulu
 * via qpdf (--object-streams=disable → classic xref) lalu stamp diulang.
 * Tanpa qpdf: PdfUnsupportedException.
 */
class PdfStamper
{
    /** Font size default overlay teks (pt). */
    private const DEFAULT_TEXT_FONT_SIZE = 8.0;

    /**
     * @param  string  $pdfBytes  binary PDF sumber
     * @param  list<array<string, mixed>>  $overlays  overlay image/text (lihat docblock kelas)
     * @return string binary PDF hasil (overlay tertanam, jumlah halaman tetap)
     *
     * @throws InvalidArgumentException overlay tidak lengkap / halaman di luar jangkauan / bukan PNG
     * @throws PdfUnsupportedException PDF butuh normalisasi qpdf tapi qpdf tidak tersedia/gagal
     */
    public function stamp(string $pdfBytes, array $overlays): string
    {
        $overlaysByPage = $this->groupOverlaysByPage($overlays);

        try {
            return $this->stampWithFpdi($pdfBytes, $overlaysByPage);
        } catch (FpdiException $e) {
            // PDF 1.5+ (xref stream / object stream) → parser gratis FPDI
            // menolak; normalisasi lalu ulangi sekali.
            return $this->stampWithFpdi($this->normalizeWithQpdf($pdfBytes), $overlaysByPage);
        }
    }

    /**
     * Validasi overlay dan kelompokkan per halaman target.
     *
     * @param  list<array<string, mixed>>  $overlays
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupOverlaysByPage(array $overlays): array
    {
        $byPage = [];
        foreach ($overlays as $overlay) {
            $isImage = array_key_exists('image', $overlay);
            $isText = ! $isImage && array_key_exists('text', $overlay);

            if (! $isImage && ! $isText) {
                throw new InvalidArgumentException(
                    "Overlay wajib memiliki kunci 'image' (PNG) atau 'text' (teks).",
                );
            }

            foreach (['image', 'text', 'page', 'x_mm', 'y_mm', 'width_mm'] as $key) {
                if ($key === 'image' && ! $isImage) {
                    continue;
                }
                if ($key === 'text' && ! $isText) {
                    continue;
                }
                if (! array_key_exists($key, $overlay)) {
                    throw new InvalidArgumentException("Overlay wajib memiliki kunci '{$key}'.");
                }
            }

            if ($isText && (! is_string($overlay['text']) || trim($overlay['text']) === '')) {
                throw new InvalidArgumentException("Overlay 'text' harus berupa teks tidak kosong.");
            }

            $page = (int) $overlay['page'];
            if ($page < 1) {
                throw new InvalidArgumentException("Overlay 'page' harus 1-based (>= 1), didapat: {$page}.");
            }

            $byPage[$page][] = $overlay;
        }

        return $byPage;
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $overlaysByPage
     */
    private function stampWithFpdi(string $pdfBytes, array $overlaysByPage): string
    {
        [$sourcePath, $imagePaths] = $this->writeTempSource($pdfBytes, $overlaysByPage);

        try {
            $fpdi = new Fpdi('P', 'mm');
            $pageCount = $fpdi->setSourceFile($sourcePath);

            foreach (array_keys($overlaysByPage) as $page) {
                if ($page > $pageCount) {
                    throw new InvalidArgumentException(
                        "Overlay menargetkan halaman {$page}, dokumen hanya memiliki {$pageCount} halaman.",
                    );
                }
            }

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $fpdi->importPage($pageNo);
                $size = $fpdi->getTemplateSize($templateId);

                // Halaman direplikasi 1:1 (ukuran & orientasi dari template).
                $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $fpdi->useTemplate($templateId);

                foreach ($overlaysByPage[$pageNo] ?? [] as $overlay) {
                    if (array_key_exists('text', $overlay)) {
                        $this->drawTextOverlay($fpdi, $overlay, (float) $size['height']);
                        continue;
                    }

                    // Kontrak kiri-bawah → FPDF top-left: tepi atas gambar =
                    // tinggi halaman - y_mm - tinggi tampilan gambar.
                    $fpdi->Image(
                        $imagePaths[sha1($overlay['image'])],
                        (float) $overlay['x_mm'],
                        $size['height'] - (float) $overlay['y_mm'] - $this->displayHeightMm($overlay),
                        (float) $overlay['width_mm'],
                        0,
                        'PNG',
                    );
                }
            }

            return $fpdi->Output('S');
        } finally {
            @unlink($sourcePath);
            foreach ($imagePaths as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Gambar overlay teks: Helvetica (core font, tanpa embed tambahan),
     * rata tengah dalam width_mm; font_size pt → tinggi sel mm.
     * Kontrak kiri-bawah: y_mm = jarak tepi bawah sel teks dari tepi
     * bawah halaman (konsisten dengan overlay image).
     *
     * @param  array<string, mixed>  $overlay
     */
    private function drawTextOverlay(Fpdi $fpdi, array $overlay, float $pageHeightMm): void
    {
        $fontSize = (float) ($overlay['font_size'] ?? self::DEFAULT_TEXT_FONT_SIZE);
        $cellHeight = $fontSize * 25.4 / 72;

        $fpdi->SetFont('Helvetica', '', $fontSize);
        $fpdi->SetXY((float) $overlay['x_mm'], $pageHeightMm - (float) $overlay['y_mm'] - $cellHeight);
        $fpdi->Cell((float) $overlay['width_mm'], $cellHeight, (string) $overlay['text'], 0, 0, 'C');
    }

    /**
     * Tulis PDF sumber dan tiap PNG overlay (dedup by sha1) ke temp file —
     * FPDI/FPDF membutuhkan path file, bukan bytes. Overlay teks tidak
     * butuh berkas temp.
     *
     * @param  array<int, list<array<string, mixed>>>  $overlaysByPage
     * @return array{0: string, 1: array<string, string>} path sumber + map sha1→path PNG
     */
    private function writeTempSource(string $pdfBytes, array $overlaysByPage): array
    {
        $sourcePath = $this->writeTempFile($pdfBytes);

        $imagePaths = [];
        foreach ($overlaysByPage as $overlays) {
            foreach ($overlays as $overlay) {
                if (! array_key_exists('image', $overlay)) {
                    continue;
                }

                $hash = sha1($overlay['image']);
                $imagePaths[$hash] ??= $this->writeTempFile($overlay['image']);
            }
        }

        return [$sourcePath, $imagePaths];
    }

    private function writeTempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sagansa_stamp_');
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * Tinggi tampilan overlay (mm) dari rasio piksel PNG — diperlukan untuk
     * konversi origin sebelum FPDF menggambar.
     *
     * @param  array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}  $overlay
     */
    private function displayHeightMm(array $overlay): float
    {
        $size = getimagesizefromstring($overlay['image']);
        if ($size === false || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            throw new InvalidArgumentException("Overlay 'image' harus PNG valid.");
        }

        return (float) $overlay['width_mm'] * $size[1] / $size[0];
    }

    /**
     * Normalisasi PDF via qpdf: --object-streams=disable menulis ulang
     * dokumen dengan classic xref table + tanpa object stream sehingga
     * bisa dibaca parser gratis FPDI. Input via temp file, output stdout.
     *
     * @throws PdfUnsupportedException binary qpdf tidak ada / gagal menjalankan / output invalid
     */
    private function normalizeWithQpdf(string $pdfBytes): string
    {
        $binary = (string) config('document.qpdf_binary', 'qpdf');

        // Deteksi deterministik utk path eksplisit yang tidak ada
        // (mis. QPDF_BINARY salah) — tanpa bergantung perilaku proc_open.
        if (str_contains($binary, DIRECTORY_SEPARATOR) && ! is_executable($binary)) {
            throw new PdfUnsupportedException(
                "qpdf binary tidak tersedia di '{$binary}' — PDF >1.4 (xref stream) tidak dapat diproses.",
            );
        }

        $inputPath = $this->writeTempFile($pdfBytes);

        try {
            $process = new Process([$binary, '--object-streams=disable', $inputPath, '-']);
            $process->setTimeout(60);

            try {
                $process->run();
            } catch (\RuntimeException $e) {
                // Termasuk ProcessStartFailedException: binary tidak bisa
                // di-spawn (tidak ada di PATH).
                throw new PdfUnsupportedException(
                    "qpdf binary tidak dapat dijalankan di '{$binary}'.", 0, $e,
                );
            }

            if (! $process->isSuccessful()) {
                throw new PdfUnsupportedException(
                    'qpdf gagal menormalisasi PDF: '.trim($process->getErrorOutput()),
                );
            }

            $normalized = $process->getOutput();
        } finally {
            @unlink($inputPath);
        }

        if (! str_starts_with($normalized, '%PDF')) {
            throw new PdfUnsupportedException('qpdf tidak menghasilkan output PDF valid.');
        }

        return $normalized;
    }
}
