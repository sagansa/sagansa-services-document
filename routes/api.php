<?php

use App\Http\Controllers\Api\V1\DocumentController;
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

    Route::get('/me/signer', [DocumentController::class, 'meSigner']);
});
