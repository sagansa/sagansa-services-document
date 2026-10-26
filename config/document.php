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

];
