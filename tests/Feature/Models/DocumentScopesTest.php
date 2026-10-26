<?php

namespace Tests\Feature\Models;

use App\Models\Document;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Scope query Document: search (number/subject) dan filter status.
 */
class DocumentScopesTest extends TestCase
{
    use CreatesDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
    }

    public function test_search_matches_number(): void
    {
        $needle = Document::factory()->create(['number' => '042/ST/DIR/X/2026']);
        Document::factory()->create(['number' => '043/SK/DIR/X/2026']);

        $found = Document::search('042/ST')->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($needle));
    }

    public function test_search_matches_subject(): void
    {
        $needle = Document::factory()->create(['subject' => 'Surat Tugas Audit Internal']);
        Document::factory()->create(['subject' => 'Surat Keterangan Kerja']);

        $found = Document::search('Audit Internal')->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($needle));
    }

    public function test_search_without_term_returns_everything(): void
    {
        Document::factory()->count(3)->create();

        $this->assertCount(3, Document::search(null)->get());
        $this->assertCount(3, Document::search('')->get());
    }

    public function test_status_scope_filters_by_status(): void
    {
        Document::factory()->status('draft')->create();
        $published = Document::factory()->status('terbit')->create();
        Document::factory()->status('menunggu_ttd')->create();

        $found = Document::status('terbit')->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($published));
    }

    public function test_scopes_chain(): void
    {
        Document::factory()->count(2)->status('draft')->create(['subject' => 'Tugas Rutin']);
        Document::factory()->status('terbit')->create(['subject' => 'Surat Tugas Audit']);

        $found = Document::query()->status('terbit')->search('Tugas')->get();

        $this->assertCount(1, $found);
    }
}
