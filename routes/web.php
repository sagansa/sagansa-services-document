<?php

use App\Http\Controllers\Public\VerifyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Health-check service document (dipakai uptime monitor / reverse proxy).
// Endpoint ini sengaja tidak memakai health bawaan Laravel supaya bisa
// diakses tanpa dependensi apa pun dan mengembalikan teks "ok" saja.
Route::get('/up', function () {
    return 'ok';
});

// Halaman verifikasi + unduhan publik (Task 17) — target pemindaian QR
// "{domain}/verify/{uuid}". TANPA auth: uuid surat yang acak adalah
// otorisasinya; dilindungi throttle `verify` (limit per menit per IP dari
// config document.verify_rate_limit, limiter didaftarkan di
// bootstrap/app.php). Halaman & unduhan menyajikan diri sendiri (blade
// + CSS inline, tanpa JS/framework) supaya ringan di ponsel pemindai QR.
Route::get('/verify/{uuid}', [VerifyController::class, 'show'])
    ->name('verify.show')
    ->middleware('throttle:verify')
    ->whereUuid('uuid');

Route::get('/verify/{uuid}/file', [VerifyController::class, 'file'])
    ->name('verify.file')
    ->middleware('throttle:verify')
    ->whereUuid('uuid');

Route::get('/verify/{uuid}/attachments/{attachment}', [VerifyController::class, 'attachment'])
    ->name('verify.attachment')
    ->middleware('throttle:verify')
    // whereUuid menerima SATU argumen array|string — dua argumen string
    // membuat argumen kedua DIABAIKAN diam-diam ({attachment} tak
    // terkonstrain; non-uuid lolos ke whereKey kolom uuid → Postgres
    // 'invalid input syntax' 500). Array form membatasi keduanya.
    ->whereUuid(['uuid', 'attachment']);
