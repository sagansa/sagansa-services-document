<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // Rate limiter endpoint verifikasi publik — dipakai middleware
        // `throttle:verify` di routes/web.php. WAJIB didaftarkan di sini,
        // bukan di callback `then:` withRouting bootstrap/app.php: saat
        // route di-cache (`php artisan route:cache` di produksi), callback
        // `then:` tidak pernah berjalan sehingga limiter tidak terdaftar
        // dan /verify gagal 500 "Rate limiter [verify] is not defined".
        // Provider boot selalu berjalan walau route dimuat dari cache, dan
        // facade sudah siap (beda dengan callback withMiddleware yang
        // dieksekusi sebelum facade root terpasang). Limit per menit per
        // IP diambil dari config agar bisa disetel per lingkungan tanpa
        // deploy ulang kode.
        RateLimiter::for('verify', fn (Request $r) => Limit::perMinute(
            (int) config('document.verify_rate_limit'),
        )->by($r->ip()));
    }
}
