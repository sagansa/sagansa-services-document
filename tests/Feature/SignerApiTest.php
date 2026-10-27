<?php

namespace Tests\Feature;

use App\Models\Signer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Task 20 — GET /api/v1/signers: direktori signer aktif untuk langkah
 * "Penandatangan" wizard Buat Surat (memilih signer komisaris milik user
 * lain, yang tidak tersedia lewat /me/signer). Dua kasus inti: gate peran
 * signer dan shape/urutan daftar.
 */
class SignerApiTest extends TestCase
{
    use CreatesAuthSchema, CreatesDocumentSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthSchema();
        $this->createDocumentSchema();
        Storage::fake('documents');
        $this->user = User::factory()->create();
    }

    #[Test]
    public function signers_index_is_forbidden_without_signer_role(): void
    {
        // Token valid tapi tanpa baris signer aktif → gate 403 FORBIDDEN.
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/signers')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function signers_index_lists_active_only_ordered_by_role(): void
    {
        Sanctum::actingAs($this->user);
        // Baris gate milik user login — ikut terdaftar (role deterministik
        // agar urutan assertabel; dua pemegang_saham urut id).
        Signer::factory()->role('pemegang_saham')->create([
            'user_id' => $this->user->id,
            'display_name' => 'User Sendiri',
        ]);

        $komisaris = Signer::factory()->role('komisaris')->create([
            'display_name' => 'Rina Komisaris',
            'title' => 'Komisaris Utama',
        ]);
        $pemegang = Signer::factory()->role('pemegang_saham')->create([
            'display_name' => 'Boni Pemegang',
            'title' => null,
        ]);
        $direktur = Signer::factory()->role('direktur')->create([
            'display_name' => 'Dira Direktur',
            'title' => null,
        ]);
        // Non-aktif tidak boleh ikut walau barisnya ada.
        Signer::factory()->role('komisaris')->create(['is_active' => false]);

        // signature_ready hanya true bila aset tanda tangan benar-benar ada
        // di disk documents.
        Storage::disk('documents')->put('signatures/rina.png', 'PNG');
        $komisaris->forceFill(['signature_asset_path' => 'signatures/rina.png'])->save();

        $response = $this->getJson('/api/v1/signers');

        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(4, $data);

        // Urutan: direktur → komisaris → pemegang_saham (urut id).
        $this->assertSame(
            ['direktur', 'komisaris', 'pemegang_saham', 'pemegang_saham'],
            array_column($data, 'role'),
        );

        // Shape identik elemen /me/signer (+ signature_ready cek disk).
        $this->assertSame($direktur->id, $data[0]['id']);
        $this->assertSame('Dira Direktur', $data[0]['display_name']);
        $this->assertNull($data[0]['title']);
        $this->assertFalse($data[0]['signature_ready']);

        $this->assertSame($komisaris->id, $data[1]['id']);
        $this->assertSame('Komisaris Utama', $data[1]['title']);
        $this->assertTrue($data[1]['signature_ready']);

        // Sesama pemegang_saham urut id: gate signer dibuat lebih dulu.
        $this->assertSame('User Sendiri', $data[2]['display_name']);
        $this->assertSame($pemegang->id, $data[3]['id']);
        $this->assertSame('Boni Pemegang', $data[3]['display_name']);

        // Path internal aset tanda tangan tidak dibocorkan.
        $this->assertArrayNotHasKey('signature_asset_path', $data[1]);
    }
}
