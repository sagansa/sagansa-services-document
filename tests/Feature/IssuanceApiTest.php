<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Issuance;
use App\Models\LetterType;
use App\Models\Signer;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use setasign\Fpdi\Fpdi;
use Tests\Support\CreatesAuthSchema;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * Task 16 — API penerbitan inti:
 *
 *   POST /documents/{id}/pre-final        pra-final mode a/b → URL signed 15 menit
 *   GET  /documents/{id}/pre-final/download  unduh hasil pra-final (URL signed)
 *   POST /documents/{id}/finalize         terbitkan (metode a/b/c/d)
 *   POST /documents/{id}/reissue          terbitkan ulang (note wajib)
 *   POST /documents/{id}/revoke           cabut (hanya jenis KUA, status terbit)
 *   POST /documents/{id}/void             batalkan (draft|menunggu_ttd|terbit)
 *
 * Termasuk mapping error baru di bootstrap/app.php: SIGNING_INCOMPLETE /
 * PDF_INVALID / PDF_UNSUPPORTED 422, ALREADY_SIGNED 409.
 */
class IssuanceApiTest extends TestCase
{
    use CreatesAuthSchema, CreatesDocumentSchema;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthSchema();
        $this->createDocumentSchema();
        Storage::fake('documents');
        Storage::fake('local');
        $this->creator = User::factory()->create();
        $this->signInAsSigner($this->creator);
    }

    // ——— pre-final ———

    #[Test]
    public function pre_final_mode_a_returns_working_signed_download_url(): void
    {
        $document = $this->draftFor($this->creator);

        $response = $this->postJson("/api/v1/documents/{$document->id}/pre-final", ['mode' => 'a']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.expires_in', 900);

        $url = $response->json('data.download_url');
        $this->assertIsString($url);
        $this->assertStringContainsString(
            "/api/v1/documents/{$document->id}/pre-final/download",
            $url,
            'download_url harus menuju route unduh pra-final',
        );

        // Berkas tersimpan sementara di disk local.
        $this->assertSame(1, $this->preFinalFileCount($document));

        // URL signed berfungsi: 200 + PDF; berkas dihapus setelah dikirim.
        $download = $this->get($url);
        $download->assertOk();
        $this->assertStringStartsWith('%PDF', $download->streamedContent());
        $this->assertSame(0, $this->preFinalFileCount($document), 'berkas pra-final harus terhapus setelah diunduh');

        // Audit PRE_FINAL payload {mode}.
        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_PRE_FINAL)->firstOrFail();
        $this->assertSame($this->creator->id, $log->actor_user_id);
        $this->assertSame('a', $log->payload['mode']);
    }

    #[Test]
    public function pre_final_download_rejects_unsigned_and_tampered_url(): void
    {
        $document = $this->draftFor($this->creator);

        $url = $this->postJson("/api/v1/documents/{$document->id}/pre-final", ['mode' => 'a'])
            ->assertOk()->json('data.download_url');

        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        // Tanpa tanda tangan sama sekali → 403.
        $this->get($parts['path'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // Tanda tangan dirusak → 403.
        $query['signature'] = str_repeat('deadbeef', 8);
        $this->get($parts['path'].'?'.http_build_query($query))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function pre_final_mode_b_without_overlay_stamps_default_qr(): void
    {
        $document = $this->draftFor($this->creator);

        $response = $this->postJson("/api/v1/documents/{$document->id}/pre-final", [
            'mode' => 'b',
            'pdf_base64' => $this->fixturePdfBase64(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.expires_in', 900);

        // Default overlay QR tertanam di output (page 1, x 165, y 15, w 25).
        $download = $this->get($response->json('data.download_url'));
        $download->assertOk();
        $this->assertStringContainsString('/Subtype /Image', $download->streamedContent(), 'QR default harus tertanam');

        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_PRE_FINAL)->firstOrFail();
        $this->assertSame('b', $log->payload['mode']);
    }

    #[Test]
    public function pre_final_mode_b_without_pdf_returns_422(): void
    {
        $document = $this->draftFor($this->creator);

        $this->postJson("/api/v1/documents/{$document->id}/pre-final", ['mode' => 'b'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function pre_final_mode_b_non_pdf_base64_returns_422_pdf_invalid(): void
    {
        $document = $this->draftFor($this->creator);

        $this->postJson("/api/v1/documents/{$document->id}/pre-final", [
            'mode' => 'b',
            'pdf_base64' => base64_encode('INI BUKAN PDF, hanya teks biasa.'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PDF_INVALID');

        $this->assertSame(0, $this->preFinalFileCount($document));
    }

    #[Test]
    public function pre_final_on_published_document_returns_409(): void
    {
        $document = $this->draftFor($this->creator, ['status' => 'terbit']);

        $this->postJson("/api/v1/documents/{$document->id}/pre-final", ['mode' => 'a'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');
    }

    // ——— finalize ———

    #[Test]
    public function finalize_mode_a_creates_issuance_file_and_verify_url(): void
    {
        $document = $this->draftFor($this->creator); // tanpa nomor → auto-reserve

        $response = $this->postJson("/api/v1/documents/{$document->id}/finalize", ['method' => 'a']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.issuance.method', 'a')
            ->assertJsonPath('data.issuance.method_label', 'elektronik');
        $this->assertNotNull($response->json('data.issuance.id'));
        $this->assertNotNull($response->json('data.issuance.finalized_at'));

        $issuance = Issuance::query()->findOrFail($response->json('data.issuance.id'));
        $this->assertTrue($issuance->is_active);
        $this->assertSame($issuance->file_sha256, $response->json('data.issuance.file_sha256'));
        Storage::disk('documents')->assertExists($issuance->file_path);
        $this->assertSame(
            hash('sha256', (string) Storage::disk('documents')->get($issuance->file_path)),
            $issuance->file_sha256,
        );

        $this->assertSame(config('document.domain')."/verify/{$document->id}", $response->json('data.verify_url'));
        $this->assertSame($document->fresh()->number, $response->json('data.number'));
        $this->assertSame('terbit', $document->fresh()->status);
    }

    #[Test]
    public function finalize_mode_b_stamps_custom_overlay_qr(): void
    {
        $document = $this->draftFor($this->creator);

        $response = $this->postJson("/api/v1/documents/{$document->id}/finalize", [
            'method' => 'b',
            'pdf_base64' => $this->fixturePdfBase64(),
            'overlays' => [
                // Tanpa image_base64 → memakai QR dokumen (default).
                ['page' => 2, 'x_mm' => 20, 'y_mm' => 10, 'width_mm' => 30],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.issuance.method', 'b')
            ->assertJsonPath('data.issuance.method_label', 'elektronik');

        $issuance = Issuance::query()->findOrFail($response->json('data.issuance.id'));
        $stored = (string) Storage::disk('documents')->get($issuance->file_path);
        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertStringContainsString('/Subtype /Image', $stored, 'overlay QR harus tertanam');
    }

    #[Test]
    public function finalize_mode_c_uploads_signed_pdf(): void
    {
        $document = $this->draftFor($this->creator);

        $response = $this->postJson("/api/v1/documents/{$document->id}/finalize", [
            'method' => 'c',
            'pdf_base64' => $this->fixturePdfBase64(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.issuance.method', 'c')
            ->assertJsonPath('data.issuance.method_label', 'ttd_elektronik_resmi');
    }

    #[Test]
    public function finalize_mode_c_with_overlays_returns_409_already_signed(): void
    {
        // Metode c (ttd_elektronik_resmi) tidak boleh menyertakan overlay —
        // guard controller (lihat catatan Finalizer::finalizeFromUpload).
        $document = $this->draftFor($this->creator);

        $this->postJson("/api/v1/documents/{$document->id}/finalize", [
            'method' => 'c',
            'pdf_base64' => $this->fixturePdfBase64(),
            'overlays' => [['page' => 1, 'x_mm' => 10, 'y_mm' => 10, 'width_mm' => 25]],
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_SIGNED');

        $this->assertSame(0, Issuance::query()->where('document_id', $document->id)->count());
    }

    #[Test]
    public function finalize_mode_d_composes_three_photos_into_three_full_pages(): void
    {
        $document = $this->draftFor($this->creator);

        $response = $this->post("/api/v1/documents/{$document->id}/finalize", [
            'method' => 'd',
            'images' => [
                UploadedFile::fake()->image('foto-1.png', 600, 800), // portrait
                UploadedFile::fake()->image('foto-2.png', 800, 600), // landscape
                UploadedFile::fake()->image('foto-3.png', 600, 800), // portrait
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.issuance.method', 'd')
            ->assertJsonPath('data.issuance.method_label', 'manual');

        $issuance = Issuance::query()->findOrFail($response->json('data.issuance.id'));
        $bytes = (string) Storage::disk('documents')->get($issuance->file_path);

        // 3 foto → 3 halaman (objek /Type /Page, bukan /Pages) — dan parser
        // FPDI membaca ulang output: orientasi tiap halaman mengikuti rasio
        // foto (1&3 portrait, 2 landscape).
        $this->assertSame(3, preg_match_all('#/Type\s*/Page(?!s)#', $bytes), 'harus ada tepat 3 halaman');
        $this->assertSame(['P', 'L', 'P'], $this->pageOrientations($bytes));
    }

    #[Test]
    public function finalize_with_pending_signing_request_returns_422_signing_incomplete(): void
    {
        $document = $this->draftFor($this->creator);
        SigningRequest::factory()->status('diminta')->create(['document_id' => $document->id]);

        $this->postJson("/api/v1/documents/{$document->id}/finalize", ['method' => 'a'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SIGNING_INCOMPLETE');

        $this->assertSame(0, Issuance::query()->where('document_id', $document->id)->count());
    }

    // ——— reissue ———

    #[Test]
    public function reissue_replaces_active_issuance_and_keeps_history(): void
    {
        $document = $this->draftFor($this->creator);

        // Terbitkan versi 1 via metode b (overlay QR default).
        $firstId = $this->postJson("/api/v1/documents/{$document->id}/finalize", [
            'method' => 'b',
            'pdf_base64' => $this->fixturePdfBase64(),
        ])->assertCreated()->json('data.issuance.id');

        // Reissue ke versi 2 via metode d (2 foto scan baru).
        $response = $this->postJson("/api/v1/documents/{$document->id}/reissue", [
            'method' => 'd',
            'note' => 'Perbaikan data pihak penerima kuasa.',
            'images_base64' => [
                $this->pngBase64(600, 800),
                $this->pngBase64(800, 600),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.note', 'Perbaikan data pihak penerima kuasa.')
            ->assertJsonPath('data.issuance.method', 'd')
            ->assertJsonPath('data.issuance.method_label', 'manual');

        $newId = $response->json('data.issuance.id');

        // Issuance aktif tepat satu; yang lama nonaktif + menunjuk pengganti.
        $this->assertSame(1, Issuance::query()->where('document_id', $document->id)->where('is_active', true)->count());
        $old = Issuance::query()->findOrFail($firstId);
        $this->assertFalse($old->is_active);
        $this->assertSame($newId, $old->replaced_by_id);

        $new = Issuance::query()->findOrFail($newId);
        $this->assertTrue($new->is_active);
        $this->assertSame('Perbaikan data pihak penerima kuasa.', $new->note);
        Storage::disk('documents')->assertExists($new->file_path);
        Storage::disk('documents')->assertExists($old->file_path); // riwayat tetap tersimpan

        // Dokumen tetap terbit + jejak audit REISSUED dengan note.
        $this->assertSame('terbit', $document->fresh()->status);
        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_REISSUED)->firstOrFail();
        $this->assertSame('Perbaikan data pihak penerima kuasa.', $log->payload['note']);
    }

    #[Test]
    public function reissue_without_note_returns_422(): void
    {
        $document = $this->draftFor($this->creator, ['status' => 'terbit']);
        Issuance::factory()->create(['document_id' => $document->id]);

        $this->postJson("/api/v1/documents/{$document->id}/reissue", [
            'method' => 'a',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ——— revoke ———

    #[Test]
    public function revoke_non_kua_letter_returns_422(): void
    {
        $document = $this->draftFor($this->creator, ['status' => 'terbit']); // jenis ST

        $response = $this->postJson("/api/v1/documents/{$document->id}/revoke", [
            'reason' => 'Salah isi data.',
            'revoked_at' => '2026-10-26 09:00:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertIsArray($response->json('error.details.letter_type'));
        $this->assertStringContainsString('KUA', implode(' ', $response->json('error.details.letter_type')));

        $this->assertSame('terbit', $document->fresh()->status, 'status tidak boleh berubah');
    }

    #[Test]
    public function revoke_kua_published_sets_dicabut_and_audits(): void
    {
        $kua = LetterType::factory()->create(['code' => 'KUA', 'name' => 'Keterangan Ahli Waris']);
        $document = $this->draftFor($this->creator, ['letter_type_id' => $kua->id, 'status' => 'terbit']);
        $issuance = Issuance::factory()->create(['document_id' => $document->id]);
        Storage::disk('documents')->put($issuance->file_path, '%PDF-1.4 versi terbit');

        $response = $this->postJson("/api/v1/documents/{$document->id}/revoke", [
            'reason' => 'Data ahli waris terbukti salah.',
            'revoked_at' => '2026-10-20 09:30:00',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'dicabut')
            ->assertJsonPath('data.reason', 'Data ahli waris terbukti salah.');

        $fresh = $document->fresh();
        $this->assertSame('dicabut', $fresh->status);
        $this->assertSame('2026-10-20 09:30:00', $fresh->revoked_at->format('Y-m-d H:i:s'));
        $this->assertSame('Data ahli waris terbukti salah.', $fresh->revocation_reason);

        // Issuance + berkas tetap tersimpan (riwayat tidak dihapus).
        Storage::disk('documents')->assertExists($issuance->file_path);

        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_REVOKED)->firstOrFail();
        $this->assertSame($this->creator->id, $log->actor_user_id);
        $this->assertSame('Data ahli waris terbukti salah.', $log->payload['reason']);
    }

    #[Test]
    public function revoke_non_published_returns_409(): void
    {
        $kua = LetterType::factory()->create(['code' => 'KUA', 'name' => 'Keterangan Ahli Waris']);
        $document = $this->draftFor($this->creator, ['letter_type_id' => $kua->id]); // draft

        $this->postJson("/api/v1/documents/{$document->id}/revoke", [
            'reason' => 'Data salah.',
            'revoked_at' => '2026-10-20 09:30:00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');
    }

    // ——— void ———

    #[Test]
    public function void_draft_sets_dibatalkan_and_audits(): void
    {
        $document = $this->draftFor($this->creator);

        $response = $this->postJson("/api/v1/documents/{$document->id}/void", [
            'reason' => 'Surat tidak jadi diterbitkan.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.reason', 'Surat tidak jadi diterbitkan.');

        $this->assertSame('dibatalkan', $document->fresh()->status);

        $log = AuditLog::query()->where('document_id', $document->id)
            ->where('event', AuditLogger::EVENT_VOIDED)->firstOrFail();
        $this->assertSame($this->creator->id, $log->actor_user_id);
        $this->assertSame('Surat tidak jadi diterbitkan.', $log->payload['reason']);
    }

    #[Test]
    public function void_on_revoked_or_voided_returns_409(): void
    {
        $revoked = $this->draftFor($this->creator, ['status' => 'dicabut']);

        $this->postJson("/api/v1/documents/{$revoked->id}/void", ['reason' => 'Akhiri.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');

        $voided = $this->draftFor($this->creator, ['status' => 'dibatalkan']);

        $this->postJson("/api/v1/documents/{$voided->id}/void", ['reason' => 'Akhiri.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');
    }

    // ——— fixture & helper ———

    private function draftFor(User $creator, array $overrides = []): Document
    {
        $typeId = LetterType::query()->where('code', 'ST')->value('id')
            ?? LetterType::factory()->create(['code' => 'ST'])->id;

        return Document::factory()->create(array_merge([
            'letter_type_id' => $typeId,
            'number' => null, // pra-nomor: finalize harus auto-reserve
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

    /** PDF fixture 2 halaman (classic xref) dari tests/fixtures. */
    private function fixturePdfBase64(): string
    {
        return base64_encode(
            (string) file_get_contents(dirname(__DIR__).'/fixtures/sample_pdf14.pdf'),
        );
    }

    /**
     * Orientasi tiap halaman ('P'|'L') via parser FPDI — output composer
     * adalah classic xref sehingga selalu bisa dibaca ulang.
     *
     * @return list<string>
     */
    private function pageOrientations(string $pdfBytes): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'sagansa_pages_');
        file_put_contents($tmp, $pdfBytes);

        try {
            $fpdi = new Fpdi;
            $count = $fpdi->setSourceFile($tmp);

            $orientations = [];
            for ($page = 1; $page <= $count; $page++) {
                $size = $fpdi->getTemplateSize($fpdi->importPage($page));
                $orientations[] = $size['orientation'];
            }

            return $orientations;
        } finally {
            @unlink($tmp);
        }
    }

    /** PNG kecil via GD, dikembalikan sebagai base64. */
    private function pngBase64(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 120, 200));

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($binary);
    }

    /** Jumlah berkas pra-final milik dokumen di disk local temp. */
    private function preFinalFileCount(Document $document): int
    {
        return count(array_filter(
            Storage::disk('local')->files('pre-final'),
            fn (string $path): bool => str_starts_with($path, "pre-final/{$document->id}-"),
        ));
    }
}
