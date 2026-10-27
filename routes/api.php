<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\DispositionController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\IncomingLetterController;
use App\Http\Controllers\Api\V1\IssuanceController;
use App\Http\Controllers\Api\V1\LetterThreadController;
use App\Http\Controllers\Api\V1\LetterTypeController;
use App\Http\Controllers\Api\V1\PartyController;
use App\Http\Controllers\Api\V1\SignerController;
use App\Http\Controllers\Api\V1\SigningController;
use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Semua endpoint dilindungi Sanctum. Token yang sama dengan api-ops (login)
// langsung valid di sini karena membaca personal_access_tokens di sagansa_user.
Route::middleware('auth:sanctum')->group(function () {
    // Endpoint debug — memastikan token lintas service tervalidasi benar.
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

// API domain surat v1 (Task 13): semua endpoint WAJIB token Sanctum DAN
// pengguna memiliki peran signer aktif (gate document.role).
Route::prefix('v1')->middleware(['auth:sanctum', 'document.role'])->group(function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{id}', [DocumentController::class, 'show'])->whereUuid('id');
    Route::patch('/documents/{id}', [DocumentController::class, 'update'])->whereUuid('id');
    Route::post('/documents/{id}/numbering/reserve', [DocumentController::class, 'reserveNumber'])->whereUuid('id');

    // Lampiran surat (Task 14) — hanya draft|menunggu_ttd di controller.
    Route::post('/documents/{id}/attachments', [AttachmentController::class, 'store'])->whereUuid('id');
    Route::delete('/documents/{id}/attachments/{attachmentId}', [AttachmentController::class, 'destroy'])
        ->whereUuid('id', 'attachmentId');

    // Alur tanda tangan (Task 15): ajukan penandatangan per surat,
    // putuskan (approve/reject) oleh signer pemilik, daftar tugas sendiri.
    Route::post('/documents/{id}/signers', [SigningController::class, 'requestSign'])->whereUuid('id');
    Route::get('/signing-requests/mine', [SigningController::class, 'mine']);
    Route::post('/signing-requests/{id}/approve', [SigningController::class, 'approve'])->whereUuid('id');
    Route::post('/signing-requests/{id}/reject', [SigningController::class, 'reject'])->whereUuid('id');

    // Buku pihak eksternal (Task 14).
    Route::get('/parties', [PartyController::class, 'index']);
    Route::post('/parties', [PartyController::class, 'store']);
    Route::patch('/parties/{id}', [PartyController::class, 'update'])->whereNumber('id');

    // Wizard (Task 14): jenis surat aktif + template per jenis.
    Route::get('/letter-types', [LetterTypeController::class, 'index']);
    Route::get('/templates', [TemplateController::class, 'index']);

    Route::get('/me/signer', [DocumentController::class, 'meSigner']);

    // Direktori signer aktif (Task 20): wizard Buat Surat memilih signer
    // komisaris milik user lain — /me/signer tidak memuatnya.
    Route::get('/signers', [SignerController::class, 'index']);

    // Penerbitan inti (Task 16): pra-final, finalize a-d, reissue,
    // revoke, void.
    Route::post('/documents/{id}/pre-final', [IssuanceController::class, 'preFinal'])->whereUuid('id');
    Route::post('/documents/{id}/finalize', [IssuanceController::class, 'finalize'])->whereUuid('id');
    Route::post('/documents/{id}/reissue', [IssuanceController::class, 'reissue'])->whereUuid('id');
    Route::post('/documents/{id}/revoke', [IssuanceController::class, 'revoke'])->whereUuid('id');
    Route::post('/documents/{id}/void', [IssuanceController::class, 'void'])->whereUuid('id');

    // Surat masuk (fase 1b): registrasi scan, buku agenda, disposisi.
    // Scan di-stream dari endpoint terproteksi — bukan URL publik.
    Route::get('/incoming-letters', [IncomingLetterController::class, 'index']);
    Route::post('/incoming-letters', [IncomingLetterController::class, 'store']);
    Route::get('/incoming-letters/{id}', [IncomingLetterController::class, 'show'])->whereUuid('id');
    Route::patch('/incoming-letters/{id}', [IncomingLetterController::class, 'update'])->whereUuid('id');
    Route::post('/incoming-letters/{id}/status', [IncomingLetterController::class, 'status'])->whereUuid('id');
    Route::get('/incoming-letters/{id}/scan', [IncomingLetterController::class, 'scan'])->whereUuid('id');
    Route::post('/incoming-letters/{id}/dispositions', [DispositionController::class, 'store'])->whereUuid('id');
    Route::post('/dispositions/{id}/complete', [DispositionController::class, 'complete'])->whereUuid('id');

    // Runutan surat lintas jenis (fase 1b): thread dari node mana pun.
    // Tipe tak sah ditolak routing (whereIn → 404 netral, sama seperti
    // penolakan di controller — belt & suspenders).
    Route::get('/letters/thread/{type}/{id}', [LetterThreadController::class, 'show'])
        ->whereIn('type', ['document', 'incoming_letter'])
        ->whereUuid('id');
});

// Unduhan hasil pra-final (Task 16) — DI LUAR grup auth: URL signed-nya
// sendiri yang menjadi otorisasi (link rahasia berumur 15 menit), jadi
// bisa diunduh langsung dari browser tanpa token; tanda tangan hilang/
// salah/kedaluwarsa ditolak 403 oleh middleware `signed`.
Route::prefix('v1')
    ->name('documents.')
    ->middleware('signed')
    ->group(function () {
        Route::get('/documents/{id}/pre-final/download', [IssuanceController::class, 'downloadPreFinal'])
            ->whereUuid('id')
            ->name('pre-final.download');
    });
