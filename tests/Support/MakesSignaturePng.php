<?php

namespace Tests\Support;

/**
 * Fixture PNG tanda tangan untuk test fase 1c — dibuat via GD agar
 * bytes-nya PNG sah (magic byte + IHDR) tanpa bergantung berkas biner
 * di repo.
 */
trait MakesSignaturePng
{
    /**
     * Base64 PNG transparan berisi coretan garis (tanpa prefiks data-URI,
     * sama seperti yang disimpan di kolom signers.signature_asset).
     */
    protected function signaturePngBase64(int $width = 120, int $height = 40): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, $width, $height, $transparent);

        $ink = imagecolorallocate($image, 15, 23, 42);
        imageline($image, 6, $height - 10, (int) ($width / 2), 8, $ink);
        imageline($image, (int) ($width / 2), 8, $width - 6, $height - 10, $ink);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($bytes);
    }
}
