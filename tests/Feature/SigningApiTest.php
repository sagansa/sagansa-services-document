<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\LetterType;
use App\Models\Signer;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finalizer;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Task 15 — alur tanda tangan: POST /documents/{id}/signers (ajukan),
 * POST /signing-requests/{id}/approve|reject (putuskan), GET
 * /signing-requests/mine (daftar tugas signer login). Termasuk integrasi
 * guard Finalizer: selama ada request 'diminta' finalize ditolak
 * SigningIncompleteException; setelah semua disetujui finalize SUKSES
 * dengan status dokumen tetap menunggu_ttd (bukan diubah approve).
 */
class SigningApiTest extends TestCase
{
    use CreatesAuthSchema, CreatesDocumentSchema;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthSchema();
        $this->createDocumentSchema();
        Storage::fake('documents');
        $this->creator = User::factory()->create();
    }

    #[Test]
    public function request_signer_creates_diminta_moves_document_and_audits(): void
    {
        [$document, $signer] = $this->documentWithSigner();

        $response = $this->postJson("/api/v1/documents/{$document->id}/signers", [
            'signer_id' => $signer->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'diminta')
            ->assertJsonPath('data.signer.id', $signer->id)
            ->assertJsonPath('data.signer.role', 'komisaris')
            ->assertJsonPath('data.document_status', 'menunggu_ttd');
        $this->assertNotNull($response->json('data.requested_at'));

        // Dokumen pindah ke menunggu_ttd; row request diminta + requested_at.
        $this->assertSame('menunggu_ttd', $document->fresh()->status);

        $request = SigningRequest::query()->where('document_id', $document->id)->firstOrFail();
        $this->assertSame('diminta', $request->status);
        $this->assertSame($signer->id, $request->signer_id);
        $this->assertNotNull($request->requested_at);
        $this->assertNull($request->acted_at);

        // Audit SIGN_REQUESTED payload {signer_id, role}, aktor pembuat surat.
        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_SIGN_REQUESTED)->firstOrFail();
        $this->assertSame($this->creator->id, $log->actor_user_id);
        $this->assertSame($signer->id, $log->payload['signer_id']);
        $this->assertSame('komisaris', $log->payload['role']);
    }

    #[Test]
    public function second_signer_on_same_document_is_allowed(): void
    {
        // Banyak penandatangan berbeda pada satu surat adalah alur normal.
        [$document, $signer] = $this->documentWithSigner();
        $other = Signer::factory()->role('pemegang_saham')->create();

        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertCreated();
        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $other->id])
            ->assertCreated();

        $this->assertSame(2, SigningRequest::query()->where('document_id', $document->id)->count());
    }

    #[Test]
    public function duplicate_active_request_is_rejected_422(): void
    {
        [$document, $signer] = $this->documentWithSigner();

        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertCreated();

        // Duplikat saat masih diminta → 422.
        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'Data yang dikirim tidak valid.');
        $this->assertIsArray($this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])->json('error.details.signer_id'));

        // Request berstatus disetujui juga dihitung aktif → tetap 422.
        SigningRequest::query()->where('document_id', $document->id)->update(['status' => 'disetujui']);
        $this->assertSame(
            422,
            $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])->status(),
        );

        // Request ditolak TIDAK menghalangi pengajuan ulang signer yang sama.
        SigningRequest::query()->where('document_id', $document->id)->update(['status' => 'ditolak']);
        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertCreated();
    }

    #[Test]
    public function signer_equal_to_creator_is_rejected_422(): void
    {
        // Pembuat surat otomatis masuk blok TTD (PdfRenderer::signersFor)
        // — tidak boleh diajukan sebagai penandatangan request.
        $this->signInAsSigner($this->creator); // gate (direktur)
        $document = $this->draftFor($this->creator);
        $ownSigner = Signer::factory()->role('komisaris')->create(['user_id' => $this->creator->id]);

        $response = $this->postJson("/api/v1/documents/{$document->id}/signers", [
            'signer_id' => $ownSigner->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertIsArray($response->json('error.details.signer_id'));
        $this->assertSame(0, SigningRequest::query()->where('document_id', $document->id)->count());
    }

    #[Test]
    public function creator_owned_signer_row_is_rejected_even_when_submitted_by_another_user(): void
    {
        // Review fix: larangan "signer ≠ pembuat" membandingkan pemilik
        // signer-row dengan documents.created_by — bukan dengan aktor.
        // Endpoint tanpa gate kepemilikan: user B (signer aktif) pun bisa
        // mengajukan signer-row milik pembuat A atas dokumen buatan A.
        $document = $this->draftFor($this->creator);
        $creatorSigner = Signer::factory()->role('komisaris')->create([
            'user_id' => $this->creator->id,
        ]);

        $b = User::factory()->create();
        $this->signInAsSigner($b);

        $response = $this->postJson("/api/v1/documents/{$document->id}/signers", [
            'signer_id' => $creatorSigner->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertIsArray($response->json('error.details.signer_id'));
        $this->assertSame(0, SigningRequest::query()->where('document_id', $document->id)->count());

        // Kontras: signer-row milik user C (bukan pembuat) atas dokumen
        // yang sama → 201 — jalur normal komisaris, pelaku bebas siapa.
        $cSigner = Signer::factory()->role('komisaris')->create();
        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $cSigner->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'diminta');
        $this->assertSame(1, SigningRequest::query()->where('document_id', $document->id)->count());
    }

    #[Test]
    public function unknown_or_inactive_signer_is_rejected_422(): void
    {
        [$document] = $this->documentWithSigner();

        // id tidak ada → 422 exists.
        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // ada tapi non-aktif → 422.
        $inactive = Signer::factory()->create(['is_active' => false]);
        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $inactive->id])
            ->assertStatus(422);
    }

    #[Test]
    public function request_on_published_document_returns_409(): void
    {
        [$document, $signer] = $this->documentWithSigner();
        // status bukan kolom fillable Document — pakai forceFill (fixture).
        $document->forceFill(['status' => 'terbit'])->save();

        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');

        $this->assertSame(0, SigningRequest::query()->where('document_id', $document->id)->count());
    }

    #[Test]
    public function request_on_unknown_document_returns_404(): void
    {
        $this->signInAsSigner($this->creator);
        $signer = Signer::factory()->create();

        $this->postJson('/api/v1/documents/'.\Illuminate\Support\Str::uuid().'/signers', [
            'signer_id' => $signer->id,
        ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function approve_by_non_owner_returns_403(): void
    {
        [$document, $signer] = $this->documentWithSigner();
        $requestId = $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertCreated()->json('data.id');

        // User lain (punya signer aktif → lolos gate) tetap bukan pemilik.
        $stranger = User::factory()->create();
        $this->signInAsSigner($stranger);

        $this->postJson("/api/v1/signing-requests/{$requestId}/approve", ['note' => 'Setuju'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertSame('diminta', SigningRequest::query()->findOrFail($requestId)->status);
    }

    #[Test]
    public function approve_by_owner_sets_disetujui_acted_at_and_audits(): void
    {
        [$document, $signer] = $this->documentWithSigner();
        $requestId = $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertCreated()->json('data.id');

        // Login sebagai user pemilik signer (bukan siapa pun).
        $owner = User::factory()->create();
        $signer->forceFill(['user_id' => $owner->id])->save();
        $this->signInAsSigner($owner);

        $response = $this->postJson("/api/v1/signing-requests/{$requestId}/approve", [
            'note' => 'Saya setuju isi surat ini.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'disetujui')
            ->assertJsonPath('data.note', 'Saya setuju isi surat ini.')
            ->assertJsonPath('data.document_status', 'menunggu_ttd'); // TIDAK berubah

        $request = SigningRequest::query()->findOrFail($requestId);
        $this->assertNotNull($request->acted_at);

        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_SIGNED)->firstOrFail();
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame($signer->id, $log->payload['signer_id']);
        $this->assertSame('Saya setuju isi surat ini.', $log->payload['note']);
    }

    #[Test]
    public function reject_returns_document_to_draft_and_audits(): void
    {
        [$document, $first] = $this->documentWithSigner();
        $second = Signer::factory()->role('pemegang_saham')->create();

        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $first->id])->assertCreated();
        $secondId = $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $second->id])
            ->assertCreated()->json('data.id');

        $owner = User::factory()->create();
        $second->forceFill(['user_id' => $owner->id])->save();
        $this->signInAsSigner($owner);

        $response = $this->postJson("/api/v1/signing-requests/{$secondId}/reject", [
            'note' => 'Nominal tidak sesuai keputusan direksi.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.note', 'Nominal tidak sesuai keputusan direksi.')
            ->assertJsonPath('data.document_status', 'draft');

        // Dokumen balik ke draft; request penandatangan LAIN dibiarkan
        // apa adanya (keputusan semantik: tidak dibatalkan otomatis).
        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame('diminta', SigningRequest::query()
            ->where('document_id', $document->id)->where('signer_id', $first->id)->value('status'));

        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_SIGN_REJECTED)->firstOrFail();
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame($second->id, $log->payload['signer_id']);
    }

    #[Test]
    public function decide_already_decided_request_returns_409(): void
    {
        [$document, $signer] = $this->documentWithSigner();
        $requestId = $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $signer->id])
            ->assertCreated()->json('data.id');

        $owner = User::factory()->create();
        $signer->forceFill(['user_id' => $owner->id])->save();
        $this->signInAsSigner($owner);

        $this->postJson("/api/v1/signing-requests/{$requestId}/approve")->assertOk();

        // Approve/reject ulang request yang sudah diputuskan → 409.
        $this->postJson("/api/v1/signing-requests/{$requestId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');
        $this->postJson("/api/v1/signing-requests/{$requestId}/reject")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');
    }

    #[Test]
    public function after_all_approved_finalize_succeeds_but_not_before(): void
    {
        [$document, $first] = $this->documentWithSigner();
        $second = Signer::factory()->role('pemegang_saham')->create();

        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $first->id])->assertCreated();
        $this->postJson("/api/v1/documents/{$document->id}/signers", ['signer_id' => $second->id])->assertCreated();

        $firstOwner = User::factory()->create();
        $first->forceFill(['user_id' => $firstOwner->id])->save();
        $secondOwner = User::factory()->create();
        $second->forceFill(['user_id' => $secondOwner->id])->save();

        // Guard Finalizer: selama masih ada yang diminta → SigningIncomplete.
        try {
            (new Finalizer)->finalizeFromContent($document->fresh(), (int) $this->creator->id);
            $this->fail('Finalizer seharusnya menolak selama masih ada signing_request diminta.');
        } catch (\App\Exceptions\SigningIncompleteException) {
            // diharapkan
        }

        $this->signInAsSigner($firstOwner);
        $firstRequestId = SigningRequest::query()->where('signer_id', $first->id)->value('id');
        $this->postJson("/api/v1/signing-requests/{$firstRequestId}/approve")->assertOk();

        // Masih satu lagi diminta → tetap belum boleh.
        $this->signInAsSigner($secondOwner);
        $secondRequestId = SigningRequest::query()->where('signer_id', $second->id)->value('id');

        try {
            (new Finalizer)->finalizeFromContent($document->fresh(), (int) $this->creator->id);
            $this->fail('Finalizer seharusnya menolak selama masih ada signing_request diminta.');
        } catch (\App\Exceptions\SigningIncompleteException) {
            // diharapkan
        }

        $this->postJson("/api/v1/signing-requests/{$secondRequestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.document_status', 'menunggu_ttd');

        // Semua disetujui: finalize SUKSES, status dokumen terbit.
        $issuance = (new Finalizer)->finalizeFromContent($document->fresh(), (int) $this->creator->id);

        $this->assertSame('terbit', $document->fresh()->status);
        $this->assertTrue($issuance->is_active);
        Storage::disk('documents')->assertExists($issuance->file_path);
    }

    #[Test]
    public function mine_lists_own_requests_with_document_and_filters(): void
    {
        $mine = Signer::factory()->role('komisaris')->create(['user_id' => $this->creator->id]);
        $other = Signer::factory()->role('direktur')->create();

        $older = $this->draftFor($this->creator, ['subject' => 'Surat lama']);
        $newer = $this->draftFor($this->creator, ['subject' => 'Surat baru']);

        // Request milik signer saya: diminta (lama) + disetujui (baru).
        SigningRequest::factory()->create([
            'document_id' => $older->id,
            'signer_id' => $mine->id,
            'status' => 'diminta',
            'requested_at' => now()->subDay(),
        ]);
        SigningRequest::factory()->create([
            'document_id' => $newer->id,
            'signer_id' => $mine->id,
            'status' => 'disetujui',
            'requested_at' => now(),
            'acted_at' => now(),
        ]);
        // Request signer lain — TIDAK boleh muncul.
        SigningRequest::factory()->create([
            'document_id' => $newer->id,
            'signer_id' => $other->id,
            'status' => 'diminta',
            'requested_at' => now(),
        ]);

        $this->signInAsSigner($this->creator);

        // Semua milik saya: 2 baris, terbaru dulu.
        $response = $this->getJson('/api/v1/signing-requests/mine');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 15);
        $this->assertSame($newer->id, $response->json('data.0.document.id'));
        $this->assertSame($older->id, $response->json('data.1.document.id'));

        // Bentuk item: subset dokumen id|number|subject|status + signer.
        $item = $response->json('data.0');
        $this->assertSame([$item['document']['id'], $item['document']['number'], $item['document']['subject'], $item['document']['status']],
            [$newer->id, $newer->number, 'Surat baru', $newer->status]);
        $this->assertSame($mine->id, $item['signer']['id']);
        $this->assertSame('komisaris', $item['signer']['role']);
        $this->assertArrayNotHasKey('body_html', $item['document']);

        // Filter status=diminta → hanya yang lama.
        $this->getJson('/api/v1/signing-requests/mine?status=diminta')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->tap(fn ($response) => $this->assertSame($older->id, $response->json('data.0.document.id')));

        // Status tidak dikenal → 422.
        $this->getJson('/api/v1/signing-requests/mine?status=ngawur')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /**
     * Fixture: dokumen draft milik $creator + satu signer komisaris milik
     * user lain, dan $creator sudah login sebagai signer (lolos gate).
     *
     * @return array{0: Document, 1: Signer}
     */
    private function documentWithSigner(): array
    {
        $document = $this->draftFor($this->creator);
        $signer = Signer::factory()->role('komisaris')->create();

        $this->signInAsSigner($this->creator);

        return [$document, $signer];
    }

    private function draftFor(User $creator, array $overrides = []): Document
    {
        // Jenis surat dipakai ulang antar draft dalam satu test (kode unik).
        $typeId = LetterType::query()->where('code', 'ST')->value('id')
            ?? LetterType::factory()->create(['code' => 'ST'])->id;

        return Document::factory()->create(array_merge([
            'letter_type_id' => $typeId,
            'body_html' => '<p>Isi surat</p>',
            'created_by' => $creator->id,
            'status' => 'draft',
        ], $overrides));
    }

    /** Login sebagai $user dan beri baris signer aktif (lolos gate). */
    private function signInAsSigner(User $user): void
    {
        Sanctum::actingAs($user);
        Signer::factory()->role('direktur')->create(['user_id' => $user->id]);
    }
}
