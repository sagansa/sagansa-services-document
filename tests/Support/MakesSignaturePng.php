<?php

namespace Tests\Support;

/**
 * Fixture PNG/WebP tanda tangan untuk test — dibuat via GD agar bytes-nya
 * sah (magic byte + IHDR) tanpa bergantung berkas biner di repo.
 */
trait MakesSignaturePng
{
    /**
     * Bytes PNG transparan berisi coretan garis.
     */
    protected function signaturePngBytes(int $width = 120, int $height = 40): string
    {
        return $this->signatureImageBytes('png', $width, $height);
    }

    /**
     * Base64 PNG transparan berisi coretan garis (tanpa prefiks data-URI,
     * sama seperti yang disimpan di kolom legacy signers.signature_asset).
     */
    protected function signaturePngBase64(int $width = 120, int $height = 40): string
    {
        return base64_encode($this->signaturePngBytes($width, $height));
    }

    /**
     * Bytes WebP dari gambar yang sama — meniru img service yang SELALU
     * re-encode unggahan ke WebP q80 (signature_asset_path menunjuk ke sini).
     */
    protected function signatureWebpBytes(int $width = 120, int $height = 40): string
    {
        return $this->signatureImageBytes('webp', $width, $height);
    }

    private function signatureImageBytes(string $format, int $width, int $height): string
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

        if ($format === 'webp') {
            imagewebp($image);
        } else {
            imagepng($image);
        }

        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
