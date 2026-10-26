<?php

namespace App\Services;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * Pembangun QR PNG untuk verifikasi dokumen. Payload QR adalah URL
 * verifikasi publik {domain}/verify/{uuid}; gambar dirender 300x300 px
 * dengan margin 2 modul.
 *
 * Catatan: renderer `BaconQrCode\Renderer\Image\Png` sudah dihapus di
 * bacon/bacon-qr-code 3.x; PNG di 3.1 dihasilkan GDLibRenderer (GD,
 * imageFormat default 'png') — ekivalen Png size+margin versi 2.x.
 */
class QrBuilder
{
    public const int SIZE = 300;

    public const int MARGIN = 2;

    /**
     * Render QR verifikasi untuk uuid surat sebagai binary PNG.
     */
    public function build(string $uuid): string
    {
        $renderer = new GDLibRenderer(self::SIZE, self::MARGIN);

        return (new Writer($renderer))->writeString($this->payload($uuid));
    }

    /**
     * URL verifikasi publik yang di-encode ke dalam QR — method publik
     * terpisah agar payload dapat diuji tanpa decode gambar.
     */
    public function payload(string $uuid): string
    {
        return config('document.domain')."/verify/{$uuid}";
    }
}
