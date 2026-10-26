<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Signer;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Support\Collection;

/**
 * Renderer surat menjadi PDF A4 (Mode A — tanda tangan manual/TTC)
 * via barryvdh/laravel-dompdf.
 *
 * Layout ada di resources/views/letters/base.blade.php; kelas ini
 * bertugas merakit data view (penandatangan, QR, jumlah lampiran)
 * dan menulis footer nomor halaman.
 */
class PdfRenderer
{
    public function __construct(private readonly QrBuilder $qrBuilder = new QrBuilder)
    {
    }

    /**
     * Render surat menjadi binary PDF (diawali %PDF).
     */
    public function render(Document $document): string
    {
        $pdf = Pdf::loadView('letters.base', [
            'document' => $document,
            'signers' => $this->signersFor($document),
            'qrPng' => base64_encode($this->qrBuilder->build($document->id)),
            'attachmentsCount' => $document->attachments()->count(),
        ])->setPaper('a4');

        $pdf->setCallbacks($this->pageFooterCallbacks());

        return $pdf->output();
    }

    /**
     * Penandatangan blok TTD:
     * 1. Signer milik user pembuat surat (documents.created_by) yang
     *    aktif — preferensi role direktur.
     * 2. Signer dari signing_requests surat ini berstatus disetujui.
     *
     * Dedup by signer id (pembuat yang juga menyetujui cukup sekali).
     * Bila pembuat tidak punya baris signers aktif, dipakai label
     * generik "Direktur" — sengaja TANPA query lintas database ke
     * sagansa_user (users bukan milik DB ini).
     *
     * @return Collection<int, Signer>
     */
    private function signersFor(Document $document): Collection
    {
        $creatorSigners = Signer::query()
            ->where('user_id', $document->created_by)
            ->where('is_active', true)
            ->get();

        $creator = $creatorSigners->firstWhere('role', 'direktur')
            ?? $creatorSigners->first()
            ?? $this->genericDirektur();

        $approved = Signer::query()
            ->whereHas('signingRequests', function ($query) use ($document): void {
                $query->where('document_id', $document->id)
                    ->where('status', 'disetujui');
            })
            ->orderBy('id')
            ->get();

        return $approved->prepend($creator)->unique('id')->values();
    }

    /**
     * Model Signer TIDAK dipersist — hanya value placeholder generik
     * untuk blok TTD supaya koleksi tetap homogen di blade.
     */
    private function genericDirektur(): Signer
    {
        return (new Signer)->forceFill([
            'role' => 'direktur',
            'display_name' => 'Direktur',
            'title' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Footer "Halaman N dari M" di tiap halaman.
     *
     * Memakai callback end_document dompdf (setCallbacks) alih-alih
     * <script type="text/php"> inline di blade: script inline butuh
     * opsi isPhpEnabled yang mengeval PHP apa pun di HTML — termasuk
     * {!! body_html !!} yang berasal dari editor (vektor injeksi).
     * Callback closure dipanggil dompdf per halaman dengan nomor
     * halaman aktual, tanpa mengeval isi template.
     *
     * Koordinat CPDF: titik 0,0 kiri-atas halaman; A4 = 595x842 pt,
     * jadi y=818 menempatkan footer ~8 mm dari tepi bawah.
     *
     * @return list<array{event: string, f: Closure}>
     */
    private function pageFooterCallbacks(): array
    {
        return [
            [
                'event' => 'end_document',
                'f' => function (int $pageNumber, int $pageCount, $canvas, $fontMetrics): void {
                    $font = $fontMetrics->getFont('helvetica', 'normal');
                    $canvas->text(262, 818, "Halaman {$pageNumber} dari {$pageCount}", $font, 8, [0.35, 0.35, 0.35]);
                },
            ],
        ];
    }
}
