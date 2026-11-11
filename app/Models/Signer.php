<?php

namespace App\Models;

use Database\Factories\SignerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;

/**
 * Penandatangan internal. `user_id` merujuk `users` di DB `sagansa_user`
 * (pola referensi lintas database — tanpa FK/relasi Eloquent).
 *
 * Aset tanda tangan dirujuk lewat `signature_asset_path` (path relatif di
 * image service, mis. `images/Signer/<uuid>.webp` — lihat doc/prd/design.md
 * pola services/img). Kolom `signature_asset` (base64 PNG) dipertahankan
 * HANYA sebagai fallback baca untuk baris lama; tidak lagi ditulis.
 */
class Signer extends Model
{
    /** @use HasFactory<SignerFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /**
     * Memo fetch aset: satu permintaan HTTP per baris per request
     * (PdfRenderer memanggil signaturePreview() dua kali — saat warning
     * fail-soft dan saat render blade).
     */
    private bool $signatureResolved = false;

    private ?string $signatureBinaryCache = null;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function signingRequests(): HasMany
    {
        return $this->hasMany(SigningRequest::class);
    }

    /**
     * Penanda aset tersedia: path img service (jalur utama) atau base64
     * legacy. Dipakai endpoint bytes (404 vs 500) dan flag has_signature.
     */
    public function hasSignatureAsset(): bool
    {
        return filled($this->signature_asset_path) || filled($this->signature_asset);
    }

    /**
     * Bytes PNG aset tanda tangan, null bila kosong/tidak terbaca.
     *
     * Urutan baca:
     * 1. `signature_asset_path` → img service (read publik, tanpa token).
     * 2. fallback `signature_asset` base64 (baris lama, fase 1c).
     *
     * img service menyimpan ulang SEMUA gambar sebagai WebP, sedangkan
     * konsumen mensyaratkan PNG — overlay mobile (`OverlayImageData.fromPngBytes`
     * memeriksa magic byte PNG), data URI blade, dan endpoint bytes.
     * Jadi WebP dikonversi dulu. Validasi magic byte mencegah dompdf
     * menerima data korup.
     */
    public function signatureBinary(): ?string
    {
        if (! $this->signatureResolved) {
            $this->signatureResolved = true;
            $this->signatureBinaryCache = $this->resolveSignatureBinary();
        }

        return $this->signatureBinaryCache;
    }

    /**
     * Data URI PNG siap pakai di blade (null bila aset kosong/korup).
     */
    public function signatureDataUri(): ?string
    {
        $binary = $this->signatureBinary();

        return $binary === null ? null : 'data:image/png;base64,'.base64_encode($binary);
    }

    /**
     * Ukuran gambar untuk slot 25×25mm blok TTD: rasio asli dipertahankan
     * (setara object-fit: contain — dompdf tidak mendukung object-fit).
     *
     * @return array{uri: string, width: float, height: float}|null
     */
    public function signaturePreview(float $maxMm = 23.0): ?array
    {
        $binary = $this->signatureBinary();

        if ($binary === null || strlen($binary) < 24
            || substr($binary, 12, 4) !== 'IHDR') {
            return null;
        }

        /** @var array{width: int, height: int}|false $size */
        $size = unpack('Nwidth/Nheight', substr($binary, 16, 8));

        if ($size === false || $size['width'] < 1 || $size['height'] < 1) {
            return null;
        }

        $ratio = $size['width'] / $size['height'];

        $width = $ratio >= 1 ? $maxMm : $maxMm * $ratio;
        $height = $ratio >= 1 ? $maxMm / $ratio : $maxMm;

        return [
            'uri' => 'data:image/png;base64,'.base64_encode($binary),
            'width' => round($width, 2),
            'height' => round($height, 2),
        ];
    }

    /**
     * Ambil bytes dari img service lalu samakan ke PNG.
     */
    private function resolveSignatureBinary(): ?string
    {
        $path = filled($this->signature_asset_path)
            ? trim((string) $this->signature_asset_path)
            : null;

        if ($path !== null) {
            $binary = $this->fetchFromImageService($path);

            if ($binary !== null) {
                return $binary;
            }

            // img service tidak terbaca → jatuh ke base64 legacy bila ada.
        }

        $asset = $this->signature_asset;

        if (! is_string($asset) || trim($asset) === '') {
            return null;
        }

        $binary = base64_decode($asset, true);

        if ($binary === false || ! str_starts_with($binary, self::PNG_MAGIC)) {
            return null;
        }

        return $binary;
    }

    /**
     * GET publik aset di img service (tanpa token — read memang publik).
     * Gagal jaringan/404 dicatat lalu dikembalikan null (fail-soft untuk
     * render PDF; endpoint bytes memutuskan sendiri 404 vs 500).
     */
    private function fetchFromImageService(string $path): ?string
    {
        $baseUrl = rtrim((string) config('services.image.service_url', 'https://img.sagansa.id'), '/');
        $url = $baseUrl.'/storage/'.ltrim($path, '/');

        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Throwable $exception) {
            logger()->warning('Aset tanda tangan gagal diambil dari img service.', [
                'signer_id' => $this->id,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            logger()->warning('Aset tanda tangan tidak ditemukan di img service.', [
                'signer_id' => $this->id,
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        }

        $binary = $response->body();

        if (str_starts_with($binary, self::PNG_MAGIC)) {
            return $binary;
        }

        return $this->webpToPng($binary, $path);
    }

    /**
     * img service selalu re-encode ke WebP q80; konversi balik ke PNG
     * agar seluruh konsumen (mobile, blade, endpoint bytes) tetap PNG.
     */
    private function webpToPng(string $binary, string $path): ?string
    {
        if (! str_starts_with($binary, 'RIFF') || substr($binary, 8, 4) !== 'WEBP') {
            logger()->warning('Aset tanda tangan di img service bukan PNG/WebP sah.', [
                'signer_id' => $this->id,
                'path' => $path,
            ]);

            return null;
        }

        if (! function_exists('imagecreatefromstring')) {
            logger()->warning('Ekstensi GD tidak tersedia — WebP tanda tangan tidak dapat dikonversi.', [
                'signer_id' => $this->id,
                'path' => $path,
            ]);

            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            logger()->warning('Aset tanda tangan WebP gagal didekode.', [
                'signer_id' => $this->id,
                'path' => $path,
            ]);

            return null;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        if (! str_starts_with($png, self::PNG_MAGIC)) {
            return null;
        }

        return $png;
    }

    /** Magic byte PNG (8 byte pertama berkas PNG). */
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";
}
