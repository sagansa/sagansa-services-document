<?php

namespace App\Services;

use App\Exceptions\PdfUnsupportedException;
use InvalidArgumentException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use Symfony\Component\Process\Process;

/**
 * Penempel overlay gambar (PNG QR tanda tangan) ke PDF existing —
 * Mode B/C pra-final.
 *
 * Overlay item:
 * ['image' => binary PNG, 'page' => int 1-based, 'x_mm' => float,
 *  'y_mm' => float, 'width_mm' => float]
 * Koordinat dalam mm, origin KIRI-BAWAH halaman; y_mm adalah jarak tepi
 * bawah gambar dari tepi bawah halaman. Tinggi tampilan mengikuti rasio
 * piksel PNG.
 *
 * PDF sumber >1.4 yang memakai xref stream / object stream tidak terbaca
 * parser gratis FPDI — bila parsing gagal, dokumen dinormalisasi dulu
 * via qpdf (--object-streams=disable → classic xref) lalu stamp diulang.
 * Tanpa qpdf: PdfUnsupportedException.
 */
class PdfStamper
{
    /**
     * @param  string  $pdfBytes  binary PDF sumber
     * @param  list<array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}>  $overlays
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
     * @return array<int, list<array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}>>
     */
    private function groupOverlaysByPage(array $overlays): array
    {
        $byPage = [];
        foreach ($overlays as $overlay) {
            foreach (['image', 'page', 'x_mm', 'y_mm', 'width_mm'] as $key) {
                if (! array_key_exists($key, $overlay)) {
                    throw new InvalidArgumentException("Overlay wajib memiliki kunci '{$key}'.");
                }
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
     * @param  array<int, list<array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}>>  $overlaysByPage
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
     * Tulis PDF sumber dan tiap PNG overlay (dedup by sha1) ke temp file —
     * FPDI/FPDF membutuhkan path file, bukan bytes.
     *
     * @param  array<int, list<array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}>>  $overlaysByPage
     * @return array{0: string, 1: array<string, string>} path sumber + map sha1→path PNG
     */
    private function writeTempSource(string $pdfBytes, array $overlaysByPage): array
    {
        $sourcePath = $this->writeTempFile($pdfBytes);

        $imagePaths = [];
        foreach ($overlaysByPage as $overlays) {
            foreach ($overlays as $overlay) {
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
