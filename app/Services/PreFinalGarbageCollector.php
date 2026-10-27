<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sapu berkas pra-final kedaluwarsa di disk local (MUST-FIX Task 23,
 * utang Task 16): URL unduh pra-final hanya berumur 15 menit dan berkas
 * dihapus saat diunduh, TAPI URL yang tidak pernah diunduh meninggalkan
 * berkas temp selamanya (pembersihan lama bersifat lazy). Setiap penulisan
 * pra-final BARU (IssuanceController::preFinal) memicu sapuan berumur >
 * TTL — berkas yang baru ditulis selamat karena mtime-nya masih segar.
 */
class PreFinalGarbageCollector
{
    /** Umur berkas sebelum disapu (detik) — sinkron TTL URL unduh pra-final. */
    public const MAX_AGE_SECONDS = 900;

    public function __construct(
        private readonly string $disk = 'local',
    ) {}

    /**
     * Hapus semua berkas di `pre-final/` yang lastModified lebih tua dari
     * $maxAge detik. Best-effort & diam: kegagalan baca/hapus satu berkas
     * dibekuk dan berkas itu dibiarkan untuk sapuan berikutnya — jangan
     * pernah menggagalkan permintaan pra-final.
     *
     * @return int jumlah berkas yang berhasil dihapus
     */
    public function sweep(?int $maxAge = null): int
    {
        $maxAge ??= self::MAX_AGE_SECONDS;
        $threshold = now()->getTimestamp() - $maxAge;
        $deleted = 0;

        $disk = Storage::disk($this->disk);

        try {
            $files = $disk->files('pre-final');
        } catch (Throwable) {
            // Diam-diam sesengaja (tanpa log): housekeeping internal disk
            // temp, kegagalan tidak actionable oleh pemanggil.
            return 0;
        }

        foreach ($files as $path) {
            try {
                if ($disk->lastModified($path) < $threshold && $disk->delete($path)) {
                    $deleted++;
                }
            } catch (Throwable) {
                // Lewati berkas bermasalah; sapuan berikutnya mencoba lagi.
            }
        }

        return $deleted;
    }
}
