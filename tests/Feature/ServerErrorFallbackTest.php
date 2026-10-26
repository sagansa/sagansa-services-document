<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Fallback 500 di bootstrap/app.php — exception /api/* yang tidak terpetakan
 * handler spesifik (401/422/409/403/404) tetap ter-render sebagai envelope
 * error konsisten {error:{code,message}}, bukan HTML/stack yang bocor.
 *
 * Route peledak didaftarkan ad-hoc di setUp — tidak menyentuh routes/api.php
 * produksi; handler yang diuji hidup di bootstrap/app.php, bukan di route.
 */
class ServerErrorFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/_test/boom', fn (): never => throw new RuntimeException(
            'detail internal yang tidak boleh bocor: password=db-root',
        ));
    }

    #[Test]
    public function unhandled_api_exception_returns_500_server_error_envelope(): void
    {
        $response = $this->getJson('/api/_test/boom');

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'SERVER_ERROR')
            ->assertJsonPath('error.message', 'Terjadi kesalahan server internal.');

        // Pesan exception TIDAK ikut ke response (tidak ada kebocoran
        // detail internal); envelope juga tidak memiliki kunci success.
        $this->assertStringNotContainsString('db-root', $response->getContent());
        $this->assertArrayNotHasKey('success', $response->json());
        $this->assertArrayNotHasKey('exception', $response->json());
    }
}
