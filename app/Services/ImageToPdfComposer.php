<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use InvalidArgumentException;
use setasign\Fpdi\Fpdi;

/**
 * Penyusun PDF multi-halaman dari foto/scan (metode 'd') — satu gambar
 * penuh per halaman A4, orientasi halaman mengikuti rasio gambar
 * (getimagesize: portrait default, landscape bila lebar > tinggi).
 *
 * dompdf dipakai untuk me-render TIAP gambar menjadi PDF satu halaman
 * full-bleed (margin 0, gambar selebar halaman); karena satu dokumen
 * dompdf hanya punya SATU orientasi kertas (named pages tidak didukung
 * dompdf), halaman-halaman berorientasi campuran digabung via FPDI —
 * teknik yang sama dengan PdfStamper (replikasi halaman 1:1).
 *
 * Gambar dari kamera bisa berukuran besar — bytes diteruskan ke dompdf
 * lewat data URI base64 (tanpa menulis file perantara).
 */
class ImageToPdfComposer
{
    /**
     * Gabungkan daftar gambar menjadi satu PDF (satu gambar = satu
     * halaman penuh A4, orientasi per gambar).
     *
     * @param  list<string>  $imageBinaries  binary PNG/JPEG/… gambar
     * @return string binary PDF (diawali %PDF)
     *
     * @throws InvalidArgumentException daftar kosong / ada gambar tak terbaca
     */
    public function compose(array $imageBinaries): string
    {
        if ($imageBinaries === []) {
            throw new InvalidArgumentException('compose() memerlukan minimal satu gambar.');
        }

        $singlePagePdfs = [];
        foreach ($imageBinaries as $binary) {
            $singlePagePdfs[] = $this->renderFullPage($binary);
        }

        return $this->merge($singlePagePdfs);
    }

    /**
     * Render satu gambar menjadi PDF satu halaman A4 full-bleed via
     * dompdf: margin @page 0 dan <img> berukuran persis halaman
     * (portrait 210x297 mm / landscape 297x210 mm — setara
     * width/height 100% namun eksplisit agar deterministik di dompdf).
     */
    private function renderFullPage(string $binary): string
    {
        $size = getimagesizefromstring($binary);
        if ($size === false || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            throw new InvalidArgumentException('Berkas gambar tidak valid (tidak terbaca sebagai gambar).');
        }

        $landscape = $size[0] > $size[1];
        [$widthMm, $heightMm] = $landscape ? [297, 210] : [210, 297];

        $html = sprintf(
            '<html><head><meta charset="utf-8"><style>'
            .'@page { size: A4 %s; margin: 0; }'
            .'html, body { margin: 0; padding: 0; }'
            .'img { display: block; width: %dmm; height: %dmm; }'
            .'</style></head><body><img src="data:%s;base64,%s"/></body></html>',
            $landscape ? 'landscape' : 'portrait',
            $widthMm,
            $heightMm,
            $size['mime'] ?? 'image/png',
            base64_encode($binary),
        );

        return (string) Pdf::loadHtml($html)->output();
    }

    /**
     * Gabungkan PDF satu-halaman menjadi satu dokumen via FPDI: tiap
     * halaman sumber direplikasi 1:1 (ukuran & orientasi template),
     * urutan input = urutan halaman output.
     *
     * Output dompdf memakai classic xref table sehingga selalu bisa
     * dibaca parser gratis FPDI (tanpa normalisasi qpdf).
     *
     * @param  list<string>  $pdfs
     */
    private function merge(array $pdfs): string
    {
        $tempPaths = [];

        try {
            $fpdi = new Fpdi('P', 'mm');

            foreach ($pdfs as $bytes) {
                $path = (string) tempnam(sys_get_temp_dir(), 'sagansa_compose_');
                file_put_contents($path, $bytes);
                $tempPaths[] = $path;

                $pageCount = $fpdi->setSourceFile($path);
                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $fpdi->importPage($pageNo);
                    $size = $fpdi->getTemplateSize($templateId);

                    $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $fpdi->useTemplate($templateId);
                }
            }

            return (string) $fpdi->Output('S');
        } finally {
            foreach ($tempPaths as $path) {
                @unlink($path);
            }
        }
    }
}
