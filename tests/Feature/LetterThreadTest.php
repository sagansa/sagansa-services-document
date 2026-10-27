<?php

namespace Tests\Feature;

use App\Exceptions\LinkCycleException;
use App\Exceptions\LinkTargetNotFoundException;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use App\Models\Party;
use App\Services\LetterLinkService;
use App\Services\ThreadBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * LetterLinkService + ThreadBuilder fase 1b: guard link (self/target/
 * duplikat idempoten/cycle) + rekonstruksi thread (root, urutan tanggal
 * flat chronological, depth, relation_to_parent).
 *
 * Graph bercabang yang dipakai berbagai test (4 node):
 *
 *     incoming I (10-01)
 *       ├── balasan → document D1 (10-03)
 *       │              └── tindak_lanjut → document D3 (10-07, tanpa nomor)
 *       └── balasan → document D2 (10-05)
 */
class LetterThreadTest extends TestCase
{
    use CreatesDocumentSchema;

    private LetterLinkService $service;

    private ThreadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
        $this->service = new LetterLinkService;
        $this->builder = new ThreadBuilder;
    }

    /**
     * @return array{incoming: IncomingLetter, d1: Document, d2: Document, d3: Document}
     */
    private function branchedThread(): array
    {
        $incoming = IncomingLetter::factory()->create([
            'letter_date' => '2026-10-01',
            'received_at' => '2026-10-02',
            'subject' => 'Undangan kerja sama',
            'sender_party_id' => Party::factory()->create(['name' => 'PT Nusantara'])->id,
        ]);
        $d1 = Document::factory()->create([
            'number' => '001/ST/DIR/X/2026',
            'date' => '2026-10-03',
            'subject' => 'Balasan resmi kerja sama',
            'metadata' => ['recipient' => ['name' => 'PT Nusantara']],
        ]);
        $d2 = Document::factory()->create([
            'number' => '002/ST/DIR/X/2026',
            'date' => '2026-10-05',
            'subject' => 'Balasan alternatif',
        ]);
        $d3 = Document::factory()->create([
            'number' => null, // pra-final: belum bernomor
            'date' => '2026-10-07',
            'subject' => 'Tindak lanjut kontrak',
        ]);

        $this->service->link('incoming_letter', $incoming->id, 'document', $d1->id, 'balasan', 77);
        $this->service->link('incoming_letter', $incoming->id, 'document', $d2->id, 'balasan', 77);
        $this->service->link('document', $d1->id, 'document', $d3->id, 'tindak_lanjut', 77);

        return compact('incoming', 'd1', 'd2', 'd3');
    }

    #[Test]
    public function link_creates_edge_and_audit_row(): void
    {
        $parent = Document::factory()->create();
        $child = Document::factory()->create();

        $link = $this->service->link('document', $parent->id, 'document', $child->id, 'referensi', 77);

        $this->assertInstanceOf(LetterLink::class, $link);
        $this->assertTrue(Str::isUuid($link->id));
        $this->assertDatabaseHas('letter_links', [
            'id' => $link->id,
            'parent_type' => 'document',
            'parent_id' => $parent->id,
            'child_type' => 'document',
            'child_id' => $child->id,
            'relation' => 'referensi',
            'created_by' => 77,
        ], 'mysql_document');
        $this->assertNotNull($link->created_at);

        $log = AuditLog::query()->where('event', 'letter_linked')->firstOrFail();
        $this->assertSame($child->id, $log->document_id);
        $this->assertSame(77, $log->actor_user_id);
        $this->assertSame(
            ['parent' => ['type' => 'document', 'id' => $parent->id], 'child' => ['type' => 'document', 'id' => $child->id], 'relation' => 'referensi'],
            $log->payload,
        );
    }

    #[Test]
    public function link_rejects_unknown_type_relation_and_self(): void
    {
        $doc = Document::factory()->create();

        try {
            $this->service->link('fax', $doc->id, 'document', $doc->id, 'balasan');
            $this->fail('tipe tidak dikenal harus ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Tipe node', $e->getMessage());
        }

        try {
            $this->service->link('document', $doc->id, 'document', $doc->id, 'lampiran');
            $this->fail('relasi tidak dikenal harus ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Relasi', $e->getMessage());
        }

        try {
            $this->service->link('document', $doc->id, 'document', $doc->id, 'referensi');
            $this->fail('self-link harus ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sama', $e->getMessage());
        }

        $this->assertSame(0, LetterLink::count());
    }

    #[Test]
    public function link_rejects_unknown_target_on_either_side(): void
    {
        $doc = Document::factory()->create();
        $ghost = (string) Str::uuid();

        try {
            $this->service->link('document', $ghost, 'document', $doc->id, 'balasan');
            $this->fail('parent tak dikenal harus ditolak.');
        } catch (LinkTargetNotFoundException $e) {
            $this->assertStringContainsString('parent', $e->getMessage());
        }

        try {
            $this->service->link('document', $doc->id, 'incoming_letter', $ghost, 'balasan');
            $this->fail('child tak dikenal harus ditolak.');
        } catch (LinkTargetNotFoundException $e) {
            $this->assertStringContainsString('child', $e->getMessage());
        }

        $this->assertSame(0, LetterLink::count());
    }

    #[Test]
    public function link_is_idempotent_for_duplicate_edge(): void
    {
        ['incoming' => $incoming, 'd1' => $d1] = $this->branchedThread();

        $again = $this->service->link('incoming_letter', $incoming->id, 'document', $d1->id, 'balasan', 99);

        $original = LetterLink::query()
            ->where('parent_id', $incoming->id)
            ->where('child_id', $d1->id)
            ->firstOrFail();

        $this->assertSame($original->id, $again->id);
        $this->assertSame(3, LetterLink::count(), 'duplikat tidak boleh menambah baris');
        $this->assertSame(
            3,
            AuditLog::query()->where('event', 'letter_linked')->count(),
            'duplikat idempoten tidak boleh menambah jejak audit baru',
        );
    }

    #[Test]
    public function link_rejects_direct_and_transitive_cycles(): void
    {
        ['incoming' => $incoming, 'd1' => $d1, 'd3' => $d3] = $this->branchedThread();

        // Siklus langsung: I→D1 sudah ada; D1→I akan menutup lingkar.
        try {
            $this->service->link('document', $d1->id, 'incoming_letter', $incoming->id, 'balasan');
            $this->fail('siklus langsung harus ditolak.');
        } catch (LinkCycleException $e) {
            $this->assertStringContainsString('siklus', $e->getMessage());
        }

        // Siklus melintasi 3 node: I→D1→D3 sudah ada; D3→I menutup lingkar.
        try {
            $this->service->link('document', $d3->id, 'incoming_letter', $incoming->id, 'referensi');
            $this->fail('siklus melintasi 3 node harus ditolak.');
        } catch (LinkCycleException $e) {
            $this->assertStringContainsString('siklus', $e->getMessage());
        }

        // Kedua penolakan tidak meninggalkan baris baru.
        $this->assertSame(3, LetterLink::count());
    }

    #[Test]
    public function thread_from_root_returns_flat_chronological_nodes_with_depth(): void
    {
        ['incoming' => $incoming, 'd1' => $d1, 'd2' => $d2, 'd3' => $d3] = $this->branchedThread();

        $thread = $this->builder->build('incoming_letter', $incoming->id);

        $this->assertSame(['type' => 'incoming_letter', 'id' => $incoming->id], $thread['root']);
        $this->assertSame(4, $thread['total']);
        $this->assertCount(4, $thread['nodes']);

        // Urutan flat chronological (tanggal efektif naik; incoming pakai
        // letter_date, bukan received_at).
        $this->assertSame(
            [$incoming->id, $d1->id, $d2->id, $d3->id],
            array_column($thread['nodes'], 'id'),
        );
        $this->assertSame([0, 1, 1, 2], array_column($thread['nodes'], 'depth'));
        $this->assertSame(
            [null, 'balasan', 'balasan', 'tindak_lanjut'],
            array_column($thread['nodes'], 'relation_to_parent'),
        );
        $this->assertSame([2, 1, 0, 0], array_column($thread['nodes'], 'children_count'));

        $rootNode = $thread['nodes'][0];
        $this->assertSame('incoming_letter', $rootNode['type']);
        $this->assertSame($incoming->agenda_number, $rootNode['number']);
        $this->assertSame('2026-10-01', $rootNode['date']);
        $this->assertSame('Undangan kerja sama', $rootNode['title']);
        $this->assertSame('PT Nusantara', $rootNode['party_name']);
        $this->assertSame('terdaftar', $rootNode['status']);

        // Document ber-metadata penerima menampilkan nama penerima.
        $this->assertSame('PT Nusantara', $thread['nodes'][1]['party_name']);
        $this->assertSame('001/ST/DIR/X/2026', $thread['nodes'][1]['number']);

        // Document tanpa metadata penerima → null; nomor null pra-final → '—'.
        $this->assertNull($thread['nodes'][2]['party_name']);
        $this->assertSame('—', $thread['nodes'][3]['number']);
        $this->assertSame('draft', $thread['nodes'][3]['status']);
    }

    #[Test]
    public function thread_from_middle_node_returns_same_root_and_nodes(): void
    {
        ['incoming' => $incoming, 'd1' => $d1, 'd2' => $d2, 'd3' => $d3] = $this->branchedThread();

        $fromRoot = $this->builder->build('incoming_letter', $incoming->id);
        $fromMiddle = $this->builder->build('document', $d1->id);

        $this->assertSame($fromRoot['root'], $fromMiddle['root']);
        $this->assertSame($fromRoot['total'], $fromMiddle['total']);
        $this->assertSame(
            array_column($fromRoot['nodes'], 'id'),
            array_column($fromMiddle['nodes'], 'id'),
        );
        $this->assertSame(
            array_column($fromRoot['nodes'], 'depth'),
            array_column($fromMiddle['nodes'], 'depth'),
            'depth dihitung dari root thread, bukan node awal',
        );
    }

    #[Test]
    public function thread_falls_back_to_received_at_when_letter_date_null(): void
    {
        $incoming = IncomingLetter::factory()->create([
            'letter_date' => null,
            'received_at' => '2026-10-09',
        ]);
        $reply = Document::factory()->create(['date' => '2026-10-05']);
        $this->service->link('incoming_letter', $incoming->id, 'document', $reply->id, 'balasan', 77);

        $thread = $this->builder->build('incoming_letter', $incoming->id);

        // Balasan (10-05) mendahului surat masuk (jatuh ke received_at 10-09).
        $this->assertSame([$reply->id, $incoming->id], array_column($thread['nodes'], 'id'));
        $this->assertSame('2026-10-09', $thread['nodes'][1]['date']);
    }

    #[Test]
    public function thread_of_lone_node_contains_only_itself(): void
    {
        $doc = Document::factory()->create(['number' => null]);

        $thread = $this->builder->build('document', $doc->id);

        $this->assertSame(['type' => 'document', 'id' => $doc->id], $thread['root']);
        $this->assertSame(1, $thread['total']);
        $this->assertSame('—', $thread['nodes'][0]['number']);
        $this->assertNull($thread['nodes'][0]['relation_to_parent']);
        $this->assertSame(0, $thread['nodes'][0]['depth']);
    }

    #[Test]
    public function thread_rejects_unknown_type_and_missing_node(): void
    {
        $doc = Document::factory()->create();

        try {
            $this->builder->build('fax', $doc->id);
            $this->fail('tipe tidak dikenal harus ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Tipe node', $e->getMessage());
        }

        $this->expectException(LinkTargetNotFoundException::class);
        $this->builder->build('document', (string) Str::uuid());
    }
}
