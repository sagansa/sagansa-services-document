<?php

namespace Tests\Feature;

use App\Models\Signer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\Support\MakesSignaturePng;
use Tests\TestCase;

/**
 * Fase 1c — aset tanda tangan tersimpan:
 *
 *   GET /api/v1/signers/{id}/signature — bytes PNG untuk overlay Mode B.
 *
 * Gate document.role melindungi grup route (bukan signer → 403). Aset
 * kosong atau id tak dikenal → 404 netral; aset korup → 500 (fail-loud:
 * data rusak adalah kesalahan server, bukan klien).
 */
class SignerSignatureTest extends TestCase
{
    use CreatesAuthSchema, CreatesDocumentSchema, MakesSignaturePng;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthSchema();
        $this->createDocumentSchema();
        $this->user = User::factory()->create();
        // Baris gate: user login harus punya signer aktif.
        Signer::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user);
    }

    #[Test]
    public function it_returns_png_bytes_for_stored_signature(): void
    {
        $base64 = $this->signaturePngBase64();
        $signer = Signer::factory()->create(['signature_asset' => $base64]);

        $response = $this->get("/api/v1/signers/{$signer->id}/signature");

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $body = $response->getContent();
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($body, 0, 8), 'response harus bytes PNG asli');
        $this->assertSame(base64_decode($base64, true), $body);
    }

    #[Test]
    public function it_returns_404_when_signer_has_no_signature_asset(): void
    {
        $signer = Signer::factory()->create(['signature_asset' => null]);

        $this->getJson("/api/v1/signers/{$signer->id}/signature")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function it_returns_404_for_unknown_signer_id(): void
    {
        $this->getJson('/api/v1/signers/999999/signature')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function it_returns_404_for_non_numeric_signer_id(): void
    {
        $this->getJson('/api/v1/signers/abc/signature')->assertNotFound();
    }

    #[Test]
    public function it_is_forbidden_without_active_signer_role(): void
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $signer = Signer::factory()->create(['signature_asset' => $this->signaturePngBase64()]);

        $this->getJson("/api/v1/signers/{$signer->id}/signature")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function it_fails_loudly_for_corrupt_signature_asset(): void
    {
        $signer = Signer::factory()->create([
            'signature_asset' => base64_encode('bukan-png'),
        ]);

        $this->getJson("/api/v1/signers/{$signer->id}/signature")
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'SERVER_ERROR');
    }

    #[Test]
    public function it_fails_loudly_for_non_base64_signature_asset(): void
    {
        $signer = Signer::factory()->create([
            'signature_asset' => '%%% bukan base64 %%%',
        ]);

        $this->getJson("/api/v1/signers/{$signer->id}/signature")
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'SERVER_ERROR');
    }
}
