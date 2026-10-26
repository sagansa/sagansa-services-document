<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DocumentLockedException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * API /api/v1/documents/{id}/attachments (Task 14) — unggah & hapus
 * lampiran surat. Hanya boleh saat draft|menunggu_ttd; kunci permanen
 * terjadi di finalize (Task Finalizer) — 409 DOCUMENT_LOCKED untuk status
 * lain via DocumentLockedException (dipetakan bootstrap/app.php).
 *
 * Response TIDAK memuat `path` internal; integritas diwakili sha256+size.
 */
class AttachmentController extends Controller
{
    /** Status yang masih boleh diubah lampirannya. */
    private const EDITABLE_STATUS = ['draft', 'menunggu_ttd'];

    /** Ekstensi berkas dari mime hasil deteksi isi (bukan nama file). */
    private const EXTENSION_BY_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * POST /documents/{id}/attachments — simpan berkas ke disk documents
     * dengan path {yyyy dari tanggal surat}/{docId}/att-{uuid}.{ext},
     * sha256 dari isi berkas, size dalam byte.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            [
                'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:20480'],
                'label' => ['required', 'string', 'max:255'],
                'sort' => ['nullable', 'integer'],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Document $document */
        $document = Document::query()->findOrFail($id);

        if (! in_array($document->status, self::EDITABLE_STATUS, true)) {
            throw new DocumentLockedException(
                "Lampiran hanya bisa diunggah untuk draft|menunggu_ttd; surat ini berstatus '{$document->status}'.",
            );
        }

        $file = $request->file('file');
        $contents = (string) $file->getContent();
        $extension = self::EXTENSION_BY_MIME[$file->getMimeType()]
            ?? $file->guessExtension()
            ?? 'bin';

        $attachment = new DocumentAttachment([
            'document_id' => $document->id,
            'label' => $validated['label'],
            'sort' => $validated['sort'] ?? 0,
            'path' => sprintf('%d/%s/att-%s.%s', $document->date->year, $document->id, Str::uuid(), $extension),
            'sha256' => hash('sha256', $contents),
            'mime' => $file->getMimeType(),
            'size' => strlen($contents),
        ]);
        $attachment->id = (string) Str::uuid(); // PK uuid di-generate kode, tidak fillable

        // Berkas ditulis lebih dulu; bila INSERT gagal setelahnya, disk
        // tidak ikut rollback — dibersihkan best-effort lalu exception
        // asli dilempar ulang (pola DocumentController/Finalizer).
        try {
            if (Storage::disk('documents')->put($attachment->path, $contents) === false) {
                throw new RuntimeException("Gagal menulis berkas lampiran ke disk documents: {$attachment->path}.");
            }

            $attachment->save();

            $this->audit->log(
                $document,
                AuditLogger::EVENT_UPDATED,
                (int) $request->user()->id,
                ['action' => 'upload_attachment', 'attachment_id' => $attachment->id, 'label' => $attachment->label],
                $request->ip(),
                $this->userAgent($request),
            );
        } catch (Throwable $e) {
            Storage::disk('documents')->delete($attachment->path);

            throw $e;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $attachment->id,
                'label' => $attachment->label,
                'sort' => $attachment->sort,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'sha256' => $attachment->sha256,
            ],
        ], 201);
    }

    /**
     * DELETE /documents/{id}/attachments/{attachmentId} — hapus row +
     * berkas fisiknya. Lampiran milik surat lain → 404 (bukan 403: pasangan
     * {surat, lampiran} memang tidak ada).
     */
    public function destroy(Request $request, string $id, string $attachmentId): JsonResponse
    {
        /** @var Document $document */
        $document = Document::query()->findOrFail($id);

        if (! in_array($document->status, self::EDITABLE_STATUS, true)) {
            throw new DocumentLockedException(
                "Lampiran hanya bisa dihapus untuk draft|menunggu_ttd; surat ini berstatus '{$document->status}'.",
            );
        }

        /** @var DocumentAttachment $attachment */
        $attachment = DocumentAttachment::query()
            ->where('document_id', $document->id)
            ->whereKey($attachmentId)
            ->firstOrFail();

        $attachment->delete();
        Storage::disk('documents')->delete($attachment->path);

        $this->audit->log(
            $document,
            AuditLogger::EVENT_UPDATED,
            (int) $request->user()->id,
            ['action' => 'delete_attachment', 'attachment_id' => $attachment->id, 'label' => $attachment->label],
            $request->ip(),
            $this->userAgent($request),
        );

        return response()->json(['success' => true, 'data' => null]);
    }

    /**
     * Pesan validasi bahasa Indonesia.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'file' => ':attribute harus berupa berkas.',
            'mimes' => ':attribute harus berkas bertipe: :values.',
            'max.file' => ':attribute tidak boleh lebih dari :max kilobyte (20 MB).',
            'string' => ':attribute harus berupa teks.',
            'integer' => ':attribute harus berupa angka bulat.',
            'max.string' => ':attribute tidak boleh lebih dari :max karakter.',
        ];
    }

    /**
     * Nama field ramah untuk pesan validasi.
     *
     * @return array<string, string>
     */
    private function fieldNames(): array
    {
        return [
            'file' => 'berkas lampiran',
            'label' => 'label lampiran',
            'sort' => 'urutan',
        ];
    }

    /** User agent dipotong agar muat kolom string audit_logs. */
    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}
