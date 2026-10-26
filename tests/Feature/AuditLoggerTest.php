<?php

namespace Tests\Feature;

use App\Jobs\RecordVerificationHitJob;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\VerificationHit;
use App\Services\AuditLogger;
use App\Services\VerificationHitRecorder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * AuditLogger::log() — insert append-only ke audit_logs, menerima
 * Document ATAU string uuid; VerificationHitRecorder::record() —
 * hanya dispatch RecordVerificationHitJob (job yang melakukan insert
 * verification_hits; pada queue sync insert terjadi sinkron).
 */
class AuditLoggerTest extends TestCase
{
    use CreatesDocumentSchema;

    private AuditLogger $logger;

    private VerificationHitRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
        $this->logger = new AuditLogger;
        $this->recorder = new VerificationHitRecorder;
    }

    #[Test]
    public function log_with_document_model_inserts_correct_audit_row(): void
    {
        $document = Document::factory()->create();

        $this->logger->log(
            $document,
            AuditLogger::EVENT_SIGNED,
            42,
            ['signer_role' => 'direktur', 'method' => 'a'],
            '10.0.0.1',
            'Mozilla/5.0 TestAgent',
        );

        $this->assertDatabaseCount('audit_logs', 1, 'mysql_document');

        $log = AuditLog::query()->firstOrFail();
        $this->assertSame($document->id, $log->document_id);
        $this->assertSame(42, $log->actor_user_id);
        $this->assertSame('signed', $log->event);
        $this->assertSame(['signer_role' => 'direktur', 'method' => 'a'], $log->payload);
        $this->assertSame('10.0.0.1', $log->ip);
        $this->assertSame('Mozilla/5.0 TestAgent', $log->user_agent);
        $this->assertNotNull($log->created_at);
    }

    #[Test]
    public function log_with_uuid_string_and_null_actor_stores_nullable_fields(): void
    {
        $uuid = (string) Str::uuid();

        $this->logger->log($uuid, AuditLogger::EVENT_VERIFIED_PUBLIC, null);

        $this->assertDatabaseHas('audit_logs', [
            'document_id' => $uuid,
            'actor_user_id' => null,
            'event' => 'verified_public',
            'ip' => null,
            'user_agent' => null,
        ], 'mysql_document');

        $log = AuditLog::query()->firstOrFail();
        $this->assertSame([], $log->payload);
        $this->assertNotNull($log->created_at);
    }

    #[Test]
    public function event_constants_match_planned_snake_case_names(): void
    {
        $this->assertSame('created', AuditLogger::EVENT_CREATED);
        $this->assertSame('updated', AuditLogger::EVENT_UPDATED);
        $this->assertSame('sign_requested', AuditLogger::EVENT_SIGN_REQUESTED);
        $this->assertSame('signed', AuditLogger::EVENT_SIGNED);
        $this->assertSame('sign_rejected', AuditLogger::EVENT_SIGN_REJECTED);
        $this->assertSame('pre_final', AuditLogger::EVENT_PRE_FINAL);
        $this->assertSame('finalized', AuditLogger::EVENT_FINALIZED);
        $this->assertSame('reissued', AuditLogger::EVENT_REISSUED);
        $this->assertSame('revoked', AuditLogger::EVENT_REVOKED);
        $this->assertSame('voided', AuditLogger::EVENT_VOIDED);
        $this->assertSame('verified_public', AuditLogger::EVENT_VERIFIED_PUBLIC);
    }

    #[Test]
    public function recorder_only_dispatches_the_job(): void
    {
        Queue::fake();

        $document = Document::factory()->create();

        $this->recorder->record($document, '203.0.113.9');

        Queue::assertPushed(
            RecordVerificationHitJob::class,
            fn (RecordVerificationHitJob $job) => $job->documentId === $document->id && $job->ip === '203.0.113.9',
        );

        // record() sendiri tidak menyentuh tabel — job (yang difake)
        // yang melakukan insert.
        $this->assertDatabaseCount('verification_hits', 0, 'mysql_document');
    }

    #[Test]
    public function recorder_on_sync_queue_inserts_verification_hit_row(): void
    {
        $uuid = (string) Str::uuid();

        $this->recorder->record($uuid, '198.51.100.7');

        $this->assertDatabaseHas('verification_hits', [
            'document_id' => $uuid,
            'ip' => '198.51.100.7',
        ], 'mysql_document');

        $hit = VerificationHit::query()->firstOrFail();
        $this->assertNotNull($hit->created_at);
    }
}
