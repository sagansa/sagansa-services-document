<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain publik service document
    |--------------------------------------------------------------------------
    | Basis URL QR tanda tangan / verifikasi dokumen; payload QR adalah
    | "{domain}/verify/{uuid}".
    |
    */

    'domain' => env('DOCUMENT_DOMAIN', 'https://document.sagansa.id'),

    /*
    |--------------------------------------------------------------------------
    | Disk penyimpanan file dokumen
    |--------------------------------------------------------------------------
    */

    'disk' => env('DOCUMENT_DISK', 'documents'),

    /*
    |--------------------------------------------------------------------------
    | Rate limit endpoint verifikasi publik
    |--------------------------------------------------------------------------
    | Request per menit per IP.
    |
    */

    'verify_rate_limit' => (int) env('VERIFY_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Binary qpdf untuk normalisasi PDF
    |--------------------------------------------------------------------------
    | PdfStamper memakai qpdf (--object-streams=disable) sebagai fallback
    | bila PDF sumber memakai xref stream / object stream (PDF 1.5+) yang
    | tidak didukung parser gratis FPDI. Bisa berupa nama di PATH atau
    | path absolut; binary tidak ada → PdfUnsupportedException.
    |
    */

    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),

];
