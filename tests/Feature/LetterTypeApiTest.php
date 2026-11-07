<?php

namespace Tests\Feature;

use App\Models\LetterType;
use App\Models\Signer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * API /api/v1/letter-types — daftar jenis aktif + tambah manual dari
 * wizard (koreksi 2026-11: "jenis surat perlu bisa ditambahkan bila
 * belum ada", mis. SP2DK dari DJP).
 */
class LetterTypeApiTest extends TestCase
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
    public function store_creates_type_with_explicit_code(): void
    {
        Sanctum::actingAs($this->user);
        Signer::factory()->create(['user_id' => $this->user->id]);

        $this->postJson('/api/v1/letter-types', [
            'code' => 'SP2DK',
            'name' => 'Surat Permintaan Penjelasan Data',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'SP2DK')
            ->assertJsonPath('data.name', 'Surat Permintaan Penjelasan Data');

        $this->assertDatabaseHas('letter_types', [
            'code' => 'SP2DK',
            'is_active' => true,
        ], 'mysql_document');
    }

    #[Test]
    public function store_suggests_code_from_name_when_absent(): void
    {
        Sanctum::actingAs($this->user);
        Signer::factory()->create(['user_id' => $this->user->id]);

        $response = $this->postJson('/api/v1/letter-types', [
            'name' => 'Nota Dinas Khusus',
        ])->assertStatus(201);

        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{2,8}$/', $code);
    }

    #[Test]
    public function store_rejects_duplicate_code_and_bad_code(): void
    {
        Sanctum::actingAs($this->user);
        Signer::factory()->create(['user_id' => $this->user->id]);
        LetterType::factory()->create(['code' => 'ST']);

        $this->postJson('/api/v1/letter-types', [
            'code' => 'ST',
            'name' => 'Duplikat',
        ])->assertStatus(422);

        $this->postJson('/api/v1/letter-types', [
            'code' => 'huruf-kecil',
            'name' => 'Kode Tidak Valid',
        ])->assertStatus(422);
    }

    #[Test]
    public function index_lists_only_active_types(): void
    {
        Sanctum::actingAs($this->user);
        Signer::factory()->create(['user_id' => $this->user->id]);
        LetterType::factory()->create(['code' => 'ST', 'is_active' => true]);
        LetterType::factory()->create(['code' => 'OLD', 'is_active' => false]);

        $this->getJson('/api/v1/letter-types')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ST');
    }
}
