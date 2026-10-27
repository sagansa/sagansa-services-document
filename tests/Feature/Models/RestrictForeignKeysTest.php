<?php

namespace Tests\Feature\Models;

use App\Models\Document;
use App\Models\LetterType;
use App\Models\SigningRequest;
use App\Models\Template;
use Illuminate\Database\QueryException;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Aturan FK master domain surat = RESTRICT (migration
 * services/migration 2026_10_27_000001, diparalel di
 * CreatesDocumentSchema): jenis surat / signer yang masih direferensikan
 * TIDAK boleh terhapus — melindungi arsip 10 tahun dari penghapusan
 * master yang tidak sengaja (dulu cascade ikut menghapus surat).
 */
class RestrictForeignKeysTest extends TestCase
{
    use CreatesDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
    }

    public function test_letter_type_still_used_by_document_cannot_be_deleted(): void
    {
        $type = LetterType::factory()->create(['code' => 'ST']);
        Document::factory()->create(['letter_type_id' => $type->id]);

        try {
            LetterType::query()->whereKey($type->id)->delete();
            $this->fail('Penghapusan letter_type yang dipakai dokumen harus ditolak FK RESTRICT.');
        } catch (QueryException) {
            $this->assertNotNull(LetterType::query()->find($type->id), 'master harus tetap ada');
        }
    }

    public function test_letter_type_still_used_by_template_cannot_be_deleted(): void
    {
        $type = LetterType::factory()->create(['code' => 'ST']);
        Template::factory()->create(['letter_type_id' => $type->id]);

        $this->expectException(QueryException::class);

        LetterType::query()->whereKey($type->id)->delete();
    }

    public function test_signer_still_used_by_signing_request_cannot_be_deleted(): void
    {
        $document = Document::factory()->create();
        $request = SigningRequest::factory()->create(['document_id' => $document->id]);

        $this->expectException(QueryException::class);

        $request->signer->delete();
    }

    public function test_unused_letter_type_can_still_be_deleted(): void
    {
        $type = LetterType::factory()->create(['code' => 'ZZ']);

        LetterType::query()->whereKey($type->id)->delete();

        $this->assertNull(LetterType::query()->find($type->id));
    }
}
