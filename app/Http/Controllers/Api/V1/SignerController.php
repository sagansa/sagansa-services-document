<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Signer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Daftar penandatangan internal (Task 20, wizard Buat Surat):
 *
 *   GET /signers — semua signer AKTIF perusahaan.
 *
 * Dipakai langkah "Penandatangan" wizard untuk memilih signer komisaris
 * saat surat butuh tanda tangan ganda: /me/signer hanya memuat signer
 * milik user login, sedangkan wizard perlu signer milik orang lain.
 *
 * Gate document.role sudah melindungi endpoint (hanya user berperan
 * signer yang melihat direktori pejabat perusahaan), sehingga tidak ada
 * otorisasi tambahan per surat. Bentuk tiap elemen identik dengan elemen
 * `signers` di /me/signer agar klien memakai parser yang sama.
 */
class SignerController extends Controller
{
    /**
     * Urutan tampilan ramah wizard: pejabat eksekutif dulu.
     *
     * @var array<string, int>
     */
    private const ROLE_ORDER = [
        'direktur' => 0,
        'komisaris' => 1,
        'pemegang_saham' => 2,
    ];

    public function index(): JsonResponse
    {
        // Urut di PHP (bukan orderByRaw FIELD()) agar portabel antara
        // MySQL produksi dan SQLite test. Kolom aset tidak ikut dimuat —
        // hanya flag hasil selectRaw (path img service ATAU base64 legacy).
        $signers = Signer::query()
            ->select(['id', 'role', 'display_name', 'title'])
            ->selectRaw('(signature_asset IS NOT NULL OR signature_asset_path IS NOT NULL) as has_signature')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Signer $signer) => self::ROLE_ORDER[$signer->role] ?? PHP_INT_MAX)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $signers->map(fn (Signer $signer) => [
                'id' => $signer->id,
                'role' => $signer->role,
                'display_name' => $signer->display_name,
                'title' => $signer->title,
                'has_signature' => (bool) $signer->has_signature,
            ])->all(),
        ]);
    }

    /**
     * GET /signers/{id}/signature — bytes PNG aset tanda tangan penandatangan.
     *
     * Sumber baca: img service (`signature_asset_path`, lalu base64 legacy).
     * Gate document.role sudah melindungi grup route. Tanpa aset sama
     * sekali atau id tak dikenal → 404 netral; aset ada tapi tidak dapat
     * dibaca / bukan PNG → fail-loud 500 (data rusak atau img service
     * tidak terjangkau — bukan kesalahan klien).
     */
    public function signature(string $id): Response
    {
        $signer = Signer::query()
            ->select(['id', 'signature_asset_path', 'signature_asset'])
            ->find($id);

        if ($signer === null || ! $signer->hasSignatureAsset()) {
            abort(404);
        }

        $binary = $signer->signatureBinary();

        if ($binary === null) {
            logger()->error('Aset tanda tangan penandatangan tidak valid.', [
                'signer_id' => $signer->id,
                'signature_asset_path' => $signer->signature_asset_path,
            ]);

            throw new RuntimeException('Aset tanda tangan penandatangan tidak valid.');
        }

        return response($binary, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
