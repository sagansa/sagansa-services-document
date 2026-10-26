<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\LetterTypeController;
use App\Http\Controllers\Api\V1\PartyController;
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

    // Buku pihak eksternal (Task 14).
    Route::get('/parties', [PartyController::class, 'index']);
    Route::post('/parties', [PartyController::class, 'store']);
    Route::patch('/parties/{id}', [PartyController::class, 'update'])->whereNumber('id');

    // Wizard (Task 14): jenis surat aktif + template per jenis.
    Route::get('/letter-types', [LetterTypeController::class, 'index']);
    Route::get('/templates', [TemplateController::class, 'index']);

    Route::get('/me/signer', [DocumentController::class, 'meSigner']);
});
