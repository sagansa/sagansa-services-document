<?php

namespace App\Models;

use Database\Factories\SignerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penandatangan internal. `user_id` merujuk `users` di DB `sagansa_user`
 * (pola referensi lintas database — tanpa FK/relasi Eloquent).
 */
class Signer extends Model
{
    /** @use HasFactory<SignerFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

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
     * Bytes PNG aset tanda tangan (fase 1c), null bila kosong/bukan PNG
     * valid. Kolom menyimpan base64 TANPA prefiks data-URI; validasi
     * magic byte mencegah dompdf menerima data korup.
     */
    public function signatureBinary(): ?string
    {
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

    /** Magic byte PNG (8 byte pertama berkas PNG). */
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";
}
