<?php

namespace Tests\Feature;

use App\Models\DispositionRecord;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use App\Models\Party;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Model surat masuk fase 1b: relasi IncomingLetter↔Party↔DispositionRecord,
 * PK uuid, guard fillable kolom yang diatur service (scan/status/agenda),
 * timestamps created_at-saja untuk tabel append-only.
 */
class IncomingModelTest extends TestCase
{
    use CreatesDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
    }

    public function test_incoming_letter_belongs_to_sender_party(): void
    {
        $party = Party::factory()->create(['name' => 'PT Nusantara']);
        $letter = IncomingLetter::factory()->create(['sender_party_id' => $party->id]);

        $this->assertTrue($letter->senderParty->is($party));
        $this->assertSame('PT Nusantara', $letter->senderParty->name);
    }

    public function test_incoming_letter_has_many_dispositions(): void
    {
        $letter = IncomingLetter::factory()->create();
        DispositionRecord::factory()->count(2)->create(['incoming_letter_id' => $letter->id]);

        $dispositions = $letter->fresh()->dispositions;
        $this->assertCount(2, $dispositions);
    }

    public function test_disposition_belongs_to_incoming_letter(): void
    {
        $letter = IncomingLetter::factory()->create();
        $disposition = DispositionRecord::factory()->create(['incoming_letter_id' => $letter->id]);

        $this->assertTrue($disposition->incomingLetter->is($letter));
    }

    public function test_incoming_letter_uses_uuid_string_primary_key(): void
    {
        $letter = IncomingLetter::factory()->create();

        $this->assertFalse($letter->incrementing);
        $this->assertSame('string', $letter->getKeyType());
        $this->assertTrue(Str::isUuid($letter->id));
        $this->assertTrue(IncomingLetter::find($letter->id)->is($letter));
    }

    public function test_incoming_letter_casts_dates_and_integers(): void
    {
        $letter = IncomingLetter::factory()->create([
            'letter_date' => '2026-10-01',
            'received_at' => '2026-10-05',
            'year' => '2026',
        ]);

        $fresh = IncomingLetter::find($letter->id);

        $this->assertInstanceOf(Carbon::class, $fresh->letter_date);
        $this->assertSame('2026-10-01', $fresh->letter_date->format('Y-m-d'));
        $this->assertInstanceOf(Carbon::class, $fresh->received_at);
        $this->assertSame('2026-10-05', $fresh->received_at->format('Y-m-d'));
        $this->assertSame(2026, $fresh->year);
    }

    public function test_fillable_whitelists_only_request_columns(): void
    {
        $fillable = (new IncomingLetter)->getFillable();

        $this->assertEqualsCanonicalizing([
            'company_id', 'year', 'number_external', 'letter_date', 'received_at',
            'sender_party_id', 'subject', 'sifat', 'classification',
            'attachments_note', 'created_by',
        ], $fillable);
    }

    public function test_service_controlled_columns_are_not_mass_assignable(): void
    {
        $letter = IncomingLetter::factory()->create();
        $originalSha = $letter->scan_sha256;
        $originalAgenda = $letter->agenda_number;
        $originalSeq = $letter->agenda_seq;

        // Attempt mass-assignment pada kolom yang diatur service saja;
        // fill() membuang kolom yang tidak fillable (guard aktif).
        $letter->fill([
            'subject' => 'Subjek baru yang sah',
            'scan_path' => 'scans/hacked.pdf',
            'scan_sha256' => str_repeat('a', 64),
            'scan_size' => 1,
            'status' => 'selesai',
            'agenda_number' => 'SM-999/X/2026',
            'agenda_seq' => 999,
        ]);

        $this->assertSame('Subjek baru yang sah', $letter->subject);
        $this->assertSame($originalSha, $letter->scan_sha256, 'scan_sha256 tidak boleh terisi mass-assignment');
        $this->assertSame($originalAgenda, $letter->agenda_number, 'agenda_number tidak boleh terisi mass-assignment');
        $this->assertSame($originalSeq, $letter->agenda_seq, 'agenda_seq tidak boleh terisi mass-assignment');
        $this->assertSame('terdaftar', $letter->status, 'status tidak boleh terisi mass-assignment');
    }

    public function test_is_finished_helper(): void
    {
        $open = IncomingLetter::factory()->create();
        $finished = IncomingLetter::factory()->status('selesai')->create();

        $this->assertFalse($open->fresh()->isFinished());
        $this->assertTrue($finished->fresh()->isFinished());
    }

    public function test_disposition_record_sets_created_at_only(): void
    {
        $disposition = DispositionRecord::factory()->create();

        $this->assertNull(DispositionRecord::UPDATED_AT);
        $this->assertNotNull($disposition->fresh()->created_at);
        $this->assertFalse(
            Schema::connection('mysql_document')->hasColumn('disposition_records', 'updated_at')
        );
    }

    public function test_letter_link_sets_created_at_only(): void
    {
        $link = LetterLink::factory()->create();

        $this->assertNull(LetterLink::UPDATED_AT);
        $this->assertNotNull($link->fresh()->created_at);
        $this->assertFalse(
            Schema::connection('mysql_document')->hasColumn('letter_links', 'updated_at')
        );
    }

    public function test_letter_link_parent_and_child_key_helpers(): void
    {
        $link = LetterLink::factory()->make([
            'parent_type' => 'document',
            'child_type' => 'incoming_letter',
        ]);

        [$parentType, $parentId] = $link->parentKey();
        [$childType, $childId] = $link->childKey();

        $this->assertSame('document', $parentType);
        $this->assertSame($link->parent_id, $parentId);
        $this->assertSame('incoming_letter', $childType);
        $this->assertSame($link->child_id, $childId);
    }

    public function test_letter_links_rejects_duplicate_edge(): void
    {
        $link = LetterLink::factory()->create();

        $this->expectException(QueryException::class);
        LetterLink::factory()->create([
            'parent_type' => $link->parent_type,
            'parent_id' => $link->parent_id,
            'child_type' => $link->child_type,
            'child_id' => $link->child_id,
            'relation' => $link->relation,
        ]);
    }
}
