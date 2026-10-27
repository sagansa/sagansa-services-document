<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use App\Models\LetterType;
use App\Models\Party;
use App\Models\Signer;
use App\Models\User;
use App\Services\LetterLinkService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Task 6 (fase 1b) — endpoint thread + in_reply_to di POST /documents:
 *
 * - GET /letters/thread/{type}/{id} memuat {root, nodes, total} hasil
 *   ThreadBuilder dari node mana pun dalam runutan; tipe tak dikenal dan
 *   node tak ada keduanya 404 NETRAL (pola verify — tidak membedakan
 *   penyebab).
 * - POST /documents + in_reply_to {type,id,relation}: draft ter-create,
 *   edge letter_links tertulis, detail (GET) memuat chip identitas
 *   in_reply_to; target ghost → 422 LINK_TARGET_NOT_FOUND TANPA draft
 *   yatim; relasi tak sah → 422 validasi.
 *
 * Graph bercabang yang dipakai test thread (pola Task 4, 4 node):
 *
 *     incoming I (10-01)
 *       ├── balasan → document D1 (10-03)
 *       │              └── tindak_lanjut → document D3 (10-07)
 *       └── balasan → document D2 (10-05)
 */
class ThreadApiTest extends TestCase
{
    use CreatesAuthSchema, CreatesDocumentSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthSchema();
        $this->createDocumentSchema();
        $this->user = User::factory()->create();
    }

    private function signInAsSigner(): void
    {
        Sanctum::actingAs($this->user);
        Signer::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Graph bercabang 4 node (pola LetterThreadTest) via service langsung.
     *
     * @return array{incoming: IncomingLetter, d1: Document, d2: Document, d3: Document}
     */
    private function branchedThread(LetterLinkService $service): array
    {
        $incoming = IncomingLetter::factory()->create([
            'agenda_number' => 'SM-001/X/2026',
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
            'number' => null,
            'date' => '2026-10-07',
            'subject' => 'Tindak lanjut kontrak',
        ]);

        $service->link('incoming_letter', $incoming->id, 'document', $d1->id, 'balasan', 77);
        $service->link('incoming_letter', $incoming->id, 'document', $d2->id, 'balasan', 77);
        $service->link('document', $d1->id, 'document', $d3->id, 'tindak_lanjut', 77);

        return compact('incoming', 'd1', 'd2', 'd3');
    }

    /**
     * Isian draft minimal POST /documents (jenis ST di-create per test).
     *
     * @return array<string, mixed>
     */
    private function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'subject' => 'Balasan undangan kerja sama',
            'date' => '2026-10-27',
        ], $overrides);
    }

    #[Test]
    public function thread_from_document_node_returns_root_nodes_total(): void
    {
        $this->signInAsSigner();
        ['incoming' => $incoming, 'd1' => $d1, 'd3' => $d3] = $this->branchedThread(new LetterLinkService);

        // Dari node TENGAH (D1): endpoint tetap mengembalikan thread dari
        // akar runutan, bukan sub-tree D1.
        $response = $this->getJson("/api/v1/letters/thread/document/{$d1->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.root', ['type' => 'incoming_letter', 'id' => $incoming->id])
            ->assertJsonPath('data.total', 4);

        $nodes = $response->json('data.nodes');
        $this->assertCount(4, $nodes);

        // Node akar: identitas surat masuk + party pengirim.
        $this->assertSame([
            'type' => 'incoming_letter',
            'id' => $incoming->id,
            'number' => 'SM-001/X/2026',
            'date' => '2026-10-01',
            'title' => 'Undangan kerja sama',
            'party_name' => 'PT Nusantara',
            'status' => 'terdaftar',
            'relation_to_parent' => null,
            'children_count' => 2,
            'depth' => 0,
        ], $nodes[0]);

        // Node D1 (node awal request): depth dihitung dari root thread.
        $this->assertSame($d1->id, $nodes[1]['id']);
        $this->assertSame('document', $nodes[1]['type']);
        $this->assertSame('001/ST/DIR/X/2026', $nodes[1]['number']);
        $this->assertSame('balasan', $nodes[1]['relation_to_parent']);
        $this->assertSame(1, $nodes[1]['depth']);

        // Pra-nomor tampil '—'.
        $this->assertSame('—', $nodes[3]['number']);
        $this->assertSame($d3->id, $nodes[3]['id']);
        $this->assertSame('tindak_lanjut', $nodes[3]['relation_to_parent']);
    }

    #[Test]
    public function thread_unknown_type_and_missing_node_both_404_neutral(): void
    {
        $this->signInAsSigner();
        ['d1' => $d1] = $this->branchedThread(new LetterLinkService);

        // Tipe tak dikenal → 404 netral (bukan 422/500).
        $this->getJson("/api/v1/letters/thread/fax/{$d1->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        // UUID valid tapi barisnya tidak ada → 404 netral juga (pola verify:
        // tidak membocorkan ada-tidaknya node per tipe).
        $this->getJson('/api/v1/letters/thread/document/'.(string) Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/v1/letters/thread/incoming_letter/'.(string) Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function store_with_in_reply_to_incoming_creates_draft_link_and_detail_chip(): void
    {
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST', 'name' => 'Surat Tugas']);

        $incoming = IncomingLetter::factory()->create([
            'agenda_number' => 'SM-042/X/2026',
            'received_at' => '2026-10-26',
        ]);

        $response = $this->postJson('/api/v1/documents', $this->draftPayload([
            'in_reply_to' => [
                'type' => 'incoming_letter',
                'id' => $incoming->id,
                'relation' => 'balasan',
            ],
        ]));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft');

        $draftId = $response->json('data.id');

        // Edge tercatat: incoming → draft, relasi balasan, aktor user login.
        $this->assertDatabaseHas('letter_links', [
            'parent_type' => 'incoming_letter',
            'parent_id' => $incoming->id,
            'child_type' => 'document',
            'child_id' => $draftId,
            'relation' => 'balasan',
            'created_by' => $this->user->id,
        ], 'mysql_document');

        // Detail (GET setelahnya) memuat chip in_reply_to ringkas — hanya
        // identitas, tanpa isi surat induk.
        $this->getJson("/api/v1/documents/{$draftId}")
            ->assertOk()
            ->assertJsonPath('data.in_reply_to', [
                'type' => 'incoming_letter',
                'id' => $incoming->id,
                'number_or_agenda' => 'SM-042/X/2026',
                'relation' => 'balasan',
            ]);

        // Thread kini memuat 2 node; draft adalah child surat masuk.
        $thread = $this->getJson("/api/v1/letters/thread/incoming_letter/{$incoming->id}");
        $thread->assertOk()->assertJsonPath('data.total', 2);
        $this->assertSame($draftId, $thread->json('data.nodes.1.id'));
    }

    #[Test]
    public function store_with_in_reply_to_document_outgoing_to_outgoing(): void
    {
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST', 'name' => 'Surat Tugas']);

        $parent = Document::factory()->create([
            'number' => '009/ST/DIR/X/2026',
            'date' => '2026-10-20',
        ]);

        $response = $this->postJson('/api/v1/documents', $this->draftPayload([
            'in_reply_to' => [
                'type' => 'document',
                'id' => $parent->id,
                'relation' => 'referensi',
            ],
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('letter_links', [
            'parent_type' => 'document',
            'parent_id' => $parent->id,
            'child_type' => 'document',
            'child_id' => $response->json('data.id'),
            'relation' => 'referensi',
        ], 'mysql_document');

        // number_or_agenda untuk induk document = nomor surat keluarnya.
        $this->getJson('/api/v1/documents/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.in_reply_to.number_or_agenda', '009/ST/DIR/X/2026');
    }

    #[Test]
    public function store_without_parent_detail_has_null_in_reply_to(): void
    {
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST', 'name' => 'Surat Tugas']);

        $response = $this->postJson('/api/v1/documents', $this->draftPayload());

        $response->assertCreated();
        $this->assertSame(0, LetterLink::count(), 'tanpa in_reply_to tidak boleh ada edge');

        $this->getJson('/api/v1/documents/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.in_reply_to', null);
    }

    #[Test]
    public function store_with_ghost_in_reply_to_target_422_and_no_orphan_draft(): void
    {
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST', 'name' => 'Surat Tugas']);

        $response = $this->postJson('/api/v1/documents', $this->draftPayload([
            'in_reply_to' => [
                'type' => 'incoming_letter',
                'id' => (string) Str::uuid(), // ghost: tidak pernah ada
                'relation' => 'balasan',
            ],
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'LINK_TARGET_NOT_FOUND');

        // Preflight existence SEBELUM draft dibuat: tidak ada draft yatim.
        $this->assertDatabaseMissing('documents', [
            'subject' => 'Balasan undangan kerja sama',
        ], 'mysql_document');
        $this->assertSame(0, LetterLink::count());
    }

    #[Test]
    public function store_with_invalid_in_reply_to_fields_returns_422_validation(): void
    {
        $this->signInAsSigner();
        LetterType::factory()->create(['code' => 'ST', 'name' => 'Surat Tugas']);

        // Relasi tak sah.
        $this->postJson('/api/v1/documents', $this->draftPayload([
            'in_reply_to' => ['type' => 'incoming_letter', 'id' => (string) Str::uuid(), 'relation' => 'lampiran'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Tipe tak sah.
        $this->postJson('/api/v1/documents', $this->draftPayload([
            'in_reply_to' => ['type' => 'fax', 'id' => (string) Str::uuid(), 'relation' => 'balasan'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Id bukan uuid.
        $this->postJson('/api/v1/documents', $this->draftPayload([
            'in_reply_to' => ['type' => 'document', 'id' => 'bukan-uuid', 'relation' => 'balasan'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // in_reply_to tanpa field wajib.
        $this->postJson('/api/v1/documents', $this->draftPayload([
            'in_reply_to' => ['type' => 'document'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseMissing('documents', [
            'subject' => 'Balasan undangan kerja sama',
        ], 'mysql_document');
    }
}
