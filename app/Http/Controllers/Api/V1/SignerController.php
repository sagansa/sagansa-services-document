<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Signer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

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
        // MySQL produksi dan SQLite test.
        $signers = Signer::query()
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
                'signature_ready' => $signer->signature_asset_path !== null
                    && Storage::disk('documents')->exists($signer->signature_asset_path),
            ])->all(),
        ]);
    }
}
