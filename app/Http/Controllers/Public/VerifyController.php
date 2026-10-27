<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Issuance;
use App\Services\AuditLogger;
use App\Services\VerificationHitRecorder;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Halaman verifikasi + unduhan publik (Task 17) — target pemindaian QR
 * "{domain}/verify/{uuid}". TANPA auth: uuid surat yang acak adalah
 * otorisasinya; seluruh output dirancang netral & jujur:
 *
 *   GET /verify/{uuid}                 halaman status (kartu badge + info
 *                                      + riwayat terbitan + lampiran).
 *   GET /verify/{uuid}/file            stream issuance AKTIF (hanya status
 *                                      terbit; selain itu 404 netral;
 *                                      rahasia → 403).
 *   GET /verify/{uuid}/attachments/x   stream lampiran (dokumen terbit +
 *                                      bukan rahasia; selain itu 404/403).
 *
 * Setiap hit halaman dicatat: verification_hits (via job) + audit
 * verified_public (payload path) — halaman juga berfungsi sebagai sensor
 * pemakaian QR di lapangan.
 */
class VerifyController extends Controller
{
    /**
     * Label metode penerbitan yang jujur & ramah (pemetaan dari
     * issuances.method_label) — pengunjung publik tidak mengenal kode
     * a/b/c/d internal.
     *
     * @var array<string, string>
     */
    private const METHOD_TEXT = [
        'elektronik' => 'Elektronik — ditandatangani elektronik (QR)',
        'ttd_elektronik_resmi' => 'Tanda tangan elektronik resmi + e-materai',
        'manual' => 'Manual — dokumen fisik, tanda tangan basah + meterai tempel',
    ];

    /** Label jabatan signer per role (signers.title bisa kosong). */
    private const ROLE_LABEL = [
        'direktur' => 'Direktur',
        'komisaris' => 'Komisaris',
        'pemegang_saham' => 'Pemegang Saham',
    ];

    /** Nama bulan Indonesia untuk format tanggal (tanpa dependensi locale). */
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public function __construct(
        private readonly VerificationHitRecorder $hits = new VerificationHitRecorder,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * GET /verify/{uuid} — halaman verifikasi.
     */
    public function show(Request $request, string $uuid)
    {
        /** @var Document|null $document */
        $document = Document::query()
            ->with(['letterType', 'activeIssuance'])
            ->find($uuid);

        if ($document === null) {
            // Netral: tidak membedakan "tidak pernah ada" vs "dihapus".
            return $this->notFound();
        }

        // Hit publik dicatat untuk dokumen yang BENAR-BENAR ditemukan
        // (uuid tak dikenal tidak punya baris untuk dihitung).
        $this->hits->record($document, $request->ip());
        $this->audit->log(
            $document,
            AuditLogger::EVENT_VERIFIED_PUBLIC,
            null,
            ['path' => $request->path()],
            $request->ip(),
            $this->userAgent($request),
        );

        $published = $document->status === 'terbit';
        $secret = $document->classification === 'rahasia';

        return view('verify', [
            'state' => 'found',
            'document' => $document,
            'badge' => $this->badge($document),
            'number' => $document->number,
            'typeName' => $document->letterType?->name,
            'letterDate' => $this->formatDate($document->date),
            // Rahasia & pra-terbit: perihal (isi surat) tidak diekspos.
            'subject' => ($secret || ! in_array($document->status, ['terbit', 'dicabut', 'dibatalkan'], true)) ? null : $document->subject,
            'signers' => $this->signerNames($document),
            'activeIssuance' => $published && $document->activeIssuance !== null ? $this->issuanceCard($document->activeIssuance) : null,
            'history' => $this->history($document, $secret),
            'attachments' => $this->attachmentCards($document, $published && ! $secret),
            'secret' => $secret,
            'canDownload' => $published && ! $secret,
            'fileUrl' => $published && ! $secret ? "/verify/{$document->id}/file" : null,
        ]);
    }

    /**
     * GET /verify/{uuid}/file — unduh berkas issuance AKTIF sebagai PDF.
     */
    public function file(Request $request, string $uuid)
    {
        /** @var Document|null $document */
        $document = Document::query()->with('activeIssuance')->find($uuid);

        if ($document === null) {
            return $this->notFound();
        }

        if ($document->classification === 'rahasia') {
            return $this->forbidden();
        }

        $issuance = $document->activeIssuance;

        if ($document->status !== 'terbit' || $issuance === null) {
            // Netral: "tidak ada" untuk semua non-terbit (draft/dicabut/
            // dibatalkan) supaya halaman tidak menjadi orakel status via file.
            return $this->notFound();
        }

        if (! Storage::disk('documents')->exists($issuance->file_path)) {
            return $this->notFound();
        }

        $filename = $this->safeFilename(($document->number ?? $document->id).'.pdf');

        return Storage::disk('documents')->download(
            $issuance->file_path,
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * GET /verify/{uuid}/attachments/{attachment} — unduh lampiran.
     */
    public function attachment(Request $request, string $uuid, string $attachment)
    {
        /** @var Document|null $document */
        $document = Document::query()->find($uuid);

        if ($document === null) {
            return $this->notFound();
        }

        if ($document->classification === 'rahasia') {
            return $this->forbidden();
        }

        if ($document->status !== 'terbit') {
            return $this->notFound();
        }

        /** @var DocumentAttachment|null $row */
        $row = $document->attachments()->whereKey($attachment)->first();

        // Netral: lampiran milik surat lain / tidak ada → sama seperti
        // dokumen tidak ditemukan (404 netral).
        if ($row === null || ! Storage::disk('documents')->exists($row->path)) {
            return $this->notFound();
        }

        return Storage::disk('documents')->download(
            $row->path,
            $this->safeFilename($row->label.'.'.$this->extension($row)),
            ['Content-Type' => $row->mime ?: 'application/octet-stream'],
        );
    }

    // ——— penyusun data tampilan ———

    /**
     * Kartu status besar di puncak halaman.
     *
     * @return array{label: string, class: string, note: string}
     */
    private function badge(Document $document): array
    {
        return match ($document->status) {
            'terbit' => [
                'label' => 'SAH',
                'class' => 'sah',
                'note' => 'Dokumen diterbitkan resmi dan masih berlaku.',
            ],
            'dibatalkan' => [
                'label' => 'DIBATALKAN',
                'class' => 'dibatalkan',
                'note' => 'Dokumen ini telah DIBATALKAN dan tidak berlaku. '.$this->voidReason($document),
            ],
            'dicabut' => [
                'label' => 'DICABUT',
                'class' => 'dicabut',
                'note' => trim(
                    'Dokumen ini telah DICABUT pada '.$this->formatDate($document->revoked_at)
                    .($document->revocation_reason ? ' — '.$document->revocation_reason : '')
                    .'.'),
            ],
            default => [
                // draft|menunggu_ttd — tanpa membocorkan isi.
                'label' => 'Belum diterbitkan',
                'class' => 'belum',
                'note' => 'Dokumen masih dalam proses internal dan belum diterbitkan resmi.',
            ],
        };
    }

    /**
     * Alasan pembatalan hanya tersimpan di jejak audit (event voided);
     * ambil yang terakhir bila ada.
     */
    private function voidReason(Document $document): string
    {
        /** @var AuditLog|null $log */
        $log = AuditLog::query()
            ->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_VOIDED)
            ->orderByDesc('id')
            ->first();

        $reason = $log?->payload['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? 'Alasan: '.$reason.'.' : '';
    }

    /**
     * Nama penandatangan blok TTD: signer dari signing_requests disetujui;
     * fallback label generik "Direktur" (tanpa query lintas database ke
     * sagansa_user — pola yang sama dengan PdfRenderer).
     *
     * @return list<string>
     */
    private function signerNames(Document $document): array
    {
        $names = $document->signingRequests()
            ->where('status', 'disetujui')
            ->with('signer')
            ->get()
            ->filter(fn ($request) => $request->signer !== null)
            ->map(function ($request): string {
                $signer = $request->signer;
                $jabatan = $signer->title !== null && $signer->title !== ''
                    ? $signer->title
                    : (self::ROLE_LABEL[$signer->role] ?? 'Penandatangan');

                return $signer->display_name.' — '.$jabatan;
            })
            ->unique()
            ->values()
            ->all();

        return $names === [] ? ['Direktur'] : $names;
    }

    /**
     * Blok terbitan aktif (metode jujur + tanggal + hash pendek).
     *
     * @return array<string, mixed>
     */
    private function issuanceCard(Issuance $issuance): array
    {
        return [
            'methodText' => self::METHOD_TEXT[$issuance->method_label] ?? $issuance->method_label,
            'issuedAt' => $this->formatDate($issuance->finalized_at),
            'hashShort' => substr($issuance->file_sha256, 0, 8),
            'hashFull' => $issuance->file_sha256,
        ];
    }

    /**
     * Riwayat terbitan terbaru dulu: tanggal, metode, berlaku/digantikan,
     * note alasan penggantian bila ada.
     *
     * Klasifikasi rahasia: riwayat tetap tampil (tanggal/metode/status
     * adalah metadata), tapi note DISENSOR (null) — janji halaman
     * verifikasi rahasia adalah "metadata saja" dan note reissue bebas
     * teks user (bisa memuat isi/alasan sensitif).
     *
     * @return list<array<string, mixed>>
     */
    private function history(Document $document, bool $secret): array
    {
        return $document->issuances()
            ->orderByDesc('finalized_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Issuance $issuance): array => [
                'date' => $this->formatDate($issuance->finalized_at),
                'methodText' => self::METHOD_TEXT[$issuance->method_label] ?? $issuance->method_label,
                'state' => $issuance->is_active ? 'berlaku' : 'digantikan',
                'note' => $secret ? null : $issuance->note,
            ])
            ->all();
    }

    /**
     * Kartu lampiran (label + tautan unduh) — hanya bila boleh diunduh.
     *
     * @return list<array<string, mixed>>
     */
    private function attachmentCards(Document $document, bool $downloadable): array
    {
        return $document->attachments()
            ->orderBy('sort')
            ->get()
            ->map(fn (DocumentAttachment $row): array => [
                'label' => $row->label,
                'url' => $downloadable ? "/verify/{$document->id}/attachments/{$row->id}" : null,
            ])
            ->all();
    }

    // ——— helper ———

    /** 404 netral — satu kalimat, tanpa detail teknis apa pun. */
    private function notFound()
    {
        return response()->view('verify', ['state' => 'not_found'], 404);
    }

    /** 403 sederhana untuk unduhan dokumen rahasia. */
    private function forbidden()
    {
        return response()->view('verify', ['state' => 'forbidden'], 403);
    }

    /** Sanitasi nama berkas unduhan (nomor surat memuat '/'). */
    private function safeFilename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'dokumen';

        return substr($clean, 0, 100);
    }

    /** Ekstensi berkas dari path lampiran (fallback pdf). */
    private function extension(DocumentAttachment $row): string
    {
        $ext = pathinfo($row->path, PATHINFO_EXTENSION);

        return $ext !== '' ? $ext : 'pdf';
    }

    /**
     * Format tanggal Indonesia "26 Oktober 2026" — peta bulan sendiri
     * (translator locale id tidak tersedia di service ini).
     */
    private function formatDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->format('j').' '.(self::MONTHS[(int) $date->format('n')]).' '.$date->format('Y');
    }

    /** User agent dipotong agar muat kolom string audit_logs. */
    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}
