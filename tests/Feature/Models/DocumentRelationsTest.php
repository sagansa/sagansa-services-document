<?php

namespace Tests\Feature\Models;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Issuance;
use App\Models\LetterType;
use App\Models\Signer;
use App\Models\SigningRequest;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Relasi dasar model domain surat di koneksi mysql_document (sqlite test).
 */
class DocumentRelationsTest extends TestCase
{
    use CreatesDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
    }

    public function test_document_belongs_to_letter_type(): void
    {
        $letterType = LetterType::factory()->create(['code' => 'ST']);
        $document = Document::factory()->create(['letter_type_id' => $letterType->id]);

        $this->assertTrue($document->letterType->is($letterType));
    }

    public function test_letter_type_has_many_templates(): void
    {
        $letterType = LetterType::factory()->create();
        Template::factory()->count(2)->create(['letter_type_id' => $letterType->id]);

        $this->assertCount(2, $letterType->fresh()->templates);
    }

    public function test_document_has_many_issuances(): void
    {
        $document = Document::factory()->create();
        Issuance::factory()->count(2)->create(['document_id' => $document->id]);

        $this->assertCount(2, $document->fresh()->issuances);
    }

    public function test_active_issuance_returns_only_the_active_one(): void
    {
        $document = Document::factory()->create();
        $inactive = Issuance::factory()->inactive()->create(['document_id' => $document->id]);
        $active = Issuance::factory()->create(['document_id' => $document->id]);

        $this->assertTrue($document->activeIssuance->is($active));
        $this->assertFalse($document->activeIssuance->is($inactive));
        $this->assertSame(1, $document->issuances()->where('is_active', true)->count());
    }

    public function test_issuance_belongs_to_document_and_replaced_by_self_relation(): void
    {
        $document = Document::factory()->create();
        $replacement = Issuance::factory()->create(['document_id' => $document->id]);
        $old = Issuance::factory()
            ->inactive()
            ->replacedBy($replacement->id)
            ->create(['document_id' => $document->id]);

        $this->assertTrue($old->document->is($document));
        $this->assertTrue($old->replacedBy->is($replacement));
        $this->assertNull($replacement->fresh()->replacedBy);
    }

    public function test_document_has_many_attachments(): void
    {
        $document = Document::factory()->create();
        DocumentAttachment::factory()->count(2)->create(['document_id' => $document->id]);

        $attachments = $document->fresh()->attachments;
        $this->assertCount(2, $attachments);
        $this->assertTrue($attachments->first()->document->is($document));
    }

    public function test_document_has_many_signing_requests(): void
    {
        $document = Document::factory()->create();
        SigningRequest::factory()->count(2)->create(['document_id' => $document->id]);

        $this->assertCount(2, $document->fresh()->signingRequests);
    }

    public function test_signing_request_belongs_to_signer_and_signer_has_many(): void
    {
        $signer = Signer::factory()->role('direktur')->create();
        SigningRequest::factory()->count(2)->create(['signer_id' => $signer->id]);

        $requests = $signer->fresh()->signingRequests;
        $this->assertCount(2, $requests);
        $this->assertTrue($requests->first()->signer->is($signer));
    }

    public function test_document_uses_uuid_string_primary_key(): void
    {
        $document = Document::factory()->create();

        $this->assertFalse($document->incrementing);
        $this->assertSame('string', $document->getKeyType());
        $this->assertTrue(Str::isUuid($document->id));
        $this->assertTrue(Document::find($document->id)->is($document));
    }

    public function test_document_casts_metadata_and_date(): void
    {
        $document = Document::factory()
            ->withMetadata(['penerima_kuasa' => 'Budi'])
            ->create(['date' => '2026-05-01']);

        $fresh = Document::find($document->id);

        $this->assertIsArray($fresh->metadata);
        $this->assertSame('Budi', $fresh->metadata['penerima_kuasa']);
        $this->assertInstanceOf(Carbon::class, $fresh->date);
        $this->assertSame('2026-05-01', $fresh->date->format('Y-m-d'));
    }
}
