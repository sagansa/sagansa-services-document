<?php

use App\Exceptions\AlreadySignedException;
use App\Exceptions\DocumentLockedException;
use App\Exceptions\IncomingLockedException;
use App\Exceptions\LinkCycleException;
use App\Exceptions\LinkTargetNotFoundException;
use App\Exceptions\PdfInvalidException;
use App\Exceptions\PdfUnsupportedException;
use App\Exceptions\ScanInvalidException;
use App\Exceptions\SigningIncompleteException;
use App\Http\Middleware\DocumentRoleGate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Health-check /up didefinisikan manual di routes/web.php (return 'ok').
        then: function (): void {
            // Rate limiter endpoint verifikasi publik (Task 17) — dipakai
            // middleware `throttle:verify` di routes/web.php. Callback
            // `then:` berjalan saat registrasi route (setelah provider
            // ter-register) sehingga facade RateLimiter sudah bisa
            // di-resolve; limit per menit per IP diambil dari config agar
            // bisa disetel per lingkungan tanpa deploy ulang kode.
            RateLimiterFacade::for('verify', fn (Request $r) => Limit::perMinute(
                (int) config('document.verify_rate_limit'),
            )->by($r->ip()));
        },
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

        // 409 — dokumen sudah ditandatangan pihak luar; jalur unggah
        // metode 'c' tidak boleh menyertakan koordinat overlay (Task 16).
        $exceptions->render(function (AlreadySignedException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(409, 'ALREADY_SIGNED', $e->getMessage() !== '' ? $e->getMessage() : 'Dokumen sudah ditandatangani.');
        });

        // 422 — masih ada signing_request 'diminta' menggantung; finalize/
        // reissue ditolak sampai semua penandatangan memutuskan (Task 16).
        $exceptions->render(function (SigningIncompleteException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(422, 'SIGNING_INCOMPLETE', $e->getMessage() !== '' ? $e->getMessage() : 'Persetujuan tanda tangan belum lengkap.');
        });

        // 422 — berkas PDF yang dikirim bukan PDF valid (magic %PDF).
        $exceptions->render(function (PdfInvalidException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(422, 'PDF_INVALID', $e->getMessage() !== '' ? $e->getMessage() : 'Berkas PDF tidak valid.');
        });

        // 422 — PDF tidak dapat diproses (butuh qpdf, tidak tersedia).
        $exceptions->render(function (PdfUnsupportedException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(422, 'PDF_UNSUPPORTED', $e->getMessage() !== '' ? $e->getMessage() : 'Berkas PDF memakai fitur yang tidak didukung.');
        });

        // 409 — surat masuk terkunci: status hanya boleh MAJU satu arah,
        // `selesai` membekukan seluruh field, dan disposisi tidak boleh
        // diselesaikan dua kali (fase 1b).
        $exceptions->render(function (IncomingLockedException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(409, 'INCOMING_LOCKED', $e->getMessage() !== '' ? $e->getMessage() : 'Surat masuk terkunci untuk operasi ini.');
        });

        // 422 — target link runutan tidak ditemukan (defensif untuk
        // endpoint thread fase 1b; controller umumnya findOrFail lebih
        // dulu sehingga terpetakan 404).
        $exceptions->render(function (LinkTargetNotFoundException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(422, 'LINK_TARGET_NOT_FOUND', $e->getMessage() !== '' ? $e->getMessage() : 'Target link surat tidak ditemukan.');
        });

        // 422 — penambahan edge letter_links akan membentuk siklus.
        $exceptions->render(function (LinkCycleException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(422, 'LINK_CYCLE', $e->getMessage() !== '' ? $e->getMessage() : 'Link ditolak karena membentuk siklus runutan.');
        });

        // 422 — payload scan surat masuk tidak sah secara semantik: tidak
        // ada scan sama sekali, atau scan + scan_images dikirim bersamaan
        // (kesalahan per-field mime/ukuran tetap VALIDATION_ERROR).
        $exceptions->render(function (ScanInvalidException $e, Request $request) use ($isApi, $error) {
            if (! $isApi($request)) {
                return null;
            }

            return $error(422, 'SCAN_INVALID', $e->getMessage() !== '' ? $e->getMessage() : 'Scan surat masuk tidak valid.');
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
