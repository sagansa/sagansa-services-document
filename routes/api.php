<?php

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
