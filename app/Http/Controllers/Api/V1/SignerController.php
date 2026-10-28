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
        // MySQL produksi dan SQLite test. Kolom aset (LONGTEXT) TIDAK
        // ikut dimuat — hanya flag hasil selectRaw.
        $signers = Signer::query()
            ->select(['id', 'role', 'display_name', 'title'])
            ->selectRaw('(signature_asset IS NOT NULL) as has_signature')
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
     * Gate document.role sudah melindungi grup route. Aset kosong atau id
     * tak dikenal → 404 netral; aset tidak dapat didekode / bukan PNG →
     * fail-loud 500 (data korup, bukan kesalahan klien).
     */
    public function signature(string $id): Response
    {
        $signer = Signer::query()
            ->select(['id', 'signature_asset'])
            ->find($id);

        if ($signer === null || blank($signer->signature_asset)) {
            abort(404);
        }

        $binary = base64_decode((string) $signer->signature_asset, true);

        if ($binary === false || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            logger()->error('Aset tanda tangan penandatangan tidak valid.', [
                'signer_id' => $signer->id,
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
