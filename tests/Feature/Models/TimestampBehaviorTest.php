<?php

namespace Tests\Feature\Models;

use App\Models\AuditLog;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\SigningRequest;
use App\Models\VerificationHit;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Perilaku timestamps khusus:
 * - signing_requests/number_sequences: tabel TANPA kolom created_at/
 *   updated_at → model `$timestamps = false`, insert harus bekerja.
 * - audit_logs/verification_hits: append-only, hanya kolom created_at →
 *   `UPDATED_AT = null`, created_at terisi otomatis saat insert model.
 */
class TimestampBehaviorTest extends TestCase
{
    use CreatesDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
    }

    public function test_number_sequence_inserts_without_timestamps(): void
    {
        $sequence = NumberSequence::factory()->forSequence('ST', 'DIR', 2026)->create();

        $this->assertSame(2026, $sequence->year);
        $this->assertSame(0, $sequence->last_seq);
        $this->assertDatabaseHas('number_sequences', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'year' => 2026,
        ], 'mysql_document');
    }

    public function test_signing_request_inserts_without_timestamps(): void
    {
        $request = SigningRequest::factory()->status('disetujui')->create();

        $this->assertFalse($request->timestamps);
        $this->assertNotNull($request->requested_at);
        $this->assertSame('disetujui', $request->status);
    }

    public function test_audit_log_sets_created_at_only_on_insert(): void
    {
        $log = AuditLog::factory()->event('document.created', ['number' => '001/ST/DIR/X/2026'])->create();

        $fresh = AuditLog::find($log->id);

        $this->assertNotNull($fresh->created_at, 'created_at harus terisi otomatis');
        $this->assertIsArray($fresh->payload);
        $this->assertSame('001/ST/DIR/X/2026', $fresh->payload['number']);

        // Kolom updated_at memang tidak ada di tabel (append-only).
        $this->assertFalse(
            Schema::connection('mysql_document')->hasColumn('audit_logs', 'updated_at')
        );
    }

    public function test_audit_log_update_does_not_touch_updated_at(): void
    {
        $log = AuditLog::factory()->create();
        $createdAt = $log->created_at;

        // Update append-style tetap bekerja tanpa updated_at
        // (forceFill: model guarded secara default, factory yang bypass).
        $log->forceFill(['user_agent' => 'PHPUnit'])->save();

        $this->assertTrue($log->fresh()->created_at->equalTo($createdAt));
    }

    public function test_verification_hit_sets_created_at_only(): void
    {
        VerificationHit::factory()->count(2)->create();

        $hits = VerificationHit::query()->orderBy('id')->get();

        $this->assertCount(2, $hits);
        $this->assertTrue($hits->every(fn ($hit) => $hit->created_at !== null));
        $this->assertFalse(
            Schema::connection('mysql_document')->hasColumn('verification_hits', 'updated_at')
        );
    }

    public function test_party_inserts_with_timestamps(): void
    {
        $party = Party::factory()->create(['name' => 'Budi Santoso']);

        $this->assertNotNull($party->created_at);
        $this->assertNotNull($party->updated_at);
        $this->assertDatabaseHas('parties', ['name' => 'Budi Santoso'], 'mysql_document');
    }
}
