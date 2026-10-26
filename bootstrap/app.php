<?php

use App\Exceptions\DocumentLockedException;
use App\Http\Middleware\DocumentRoleGate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Health-check /up didefinisikan manual di routes/web.php (return 'ok').
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            HandleCors::class,
        ]);

        // Gate peran signer untuk group /api/v1 (dipakai routes/api.php
        // setelah auth:sanctum).
        $middleware->alias([
            'document.role' => DocumentRoleGate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*');
        });

        // Shape error API konsisten {error:{code,message}} untuk seluruh
        // request /api/* (non-API memakai render default Laravel). Mapping
        // exception → (code, status) dipusatkan di sini; controller cukup
        // melempar exception domain (mis. DocumentLockedException) atau
        // abort() — idiomatic Laravel 11+ (withExceptions di bootstrap).
        $isApi = fn (Request $request): bool => $request->is('api/*');

        $error = function (int $status, string $code, string $message, array $extra = []) {
            return response()->json([
                'error' => ['code' => $code, 'message' => $message, ...$extra],
            ], $status);
        };

        // 401 — token tidak disertakan / tidak valid.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(401, 'UNAUTHENTICATED', 'Autentikasi diperlukan: token tidak disertakan atau tidak valid.');
        });

        // 422 — payload tidak lolos validasi (rincian field di `details`).
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(422, 'VALIDATION_ERROR', 'Data yang dikirim tidak valid.', [
                'details' => $e->errors(),
            ]);
        });

        // 409 — status surat tidak mengizinkan operasi (finalize/PATCH/reserve).
        $exceptions->render(function (DocumentLockedException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(409, 'DOCUMENT_LOCKED', $e->getMessage() !== '' ? $e->getMessage() : 'Surat terkunci untuk operasi ini.');
        });

        // HttpException dipetakan per-status: 403 gate peran/otorisasi,
        // 404 resource tidak ada (termasuk ModelNotFoundException yang
        // sudah dikonversi handler ke NotFoundHttpException). Status lain
        // jatuh ke render default (null).
        $exceptions->render(function (HttpException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return match ($e->getStatusCode()) {
                403 => $error(403, 'FORBIDDEN', $e->getMessage() !== '' ? $e->getMessage() : 'Akses ditolak.'),
                404 => $error(404, 'NOT_FOUND', 'Resource tidak ditemukan.'),
                default => null,
            };
        });

        // 500 — fallback TERAKHIR untuk exception /api/* yang tidak
        // terpetakan handler di atas (mis. QueryException/RuntimeException
        // tak terduga) agar tidak pernah bocor HTML/stack ke klien. WAJIB
        // didaftarkan paling akhir: callback render dicocokkan berurutan,
        // exception spesifik (401/422/409/403/404) harus menang duluan.
        // Pesan generik — detail internal tidak ikut; exception tetap
        // dilaporkan ke log oleh pipeline normal (renderable tidak
        // mematikan reporting). HttpException berstatus lain (400/405/429/
        // 500) memakai status aslinya, bukan dipaksa 500.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

            return $error($status, 'SERVER_ERROR', 'Terjadi kesalahan server internal.');
        });
    })->create();
