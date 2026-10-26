<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DocumentLockedException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Signer;
use App\Models\SigningRequest;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API alur tanda tangan (Task 15):
 *
 *   POST /documents/{id}/signers                ajukan penandatangan
 *   POST /signing-requests/{id}/approve         setujui (menandatangani)
 *   POST /signing-requests/{id}/reject          tolak → surat balik draft
 *   GET  /signing-requests/mine?status=         daftar tugas signer login
 *
 * Semua mutasi berjalan dalam transaksi mysql_document dengan lock baris
 * surat/request (pola Finalizer::persist / DocumentController::reserveNumber)
 * agar keputusan dibuat atas kondisi terkini, bukan snapshot.
 *
 * Semantik penting:
 * - approve TIDAK mengubah status surat (tetap menunggu_ttd); Finalizer
 *   yang mengizinkan finalize setelah tak ada lagi request 'diminta'.
 * - reject mengembalikan surat ke draft, tetapi request penandatangan
 *   lain dibiarkan apa adanya (tidak dibatalkan otomatis — mereka tetap
 *   bisa memutuskan; jejak keputusan masing-masing terjaga di audit).
 * - request sudah diputuskan (disetujui|ditolak) → 409 DOCUMENT_LOCKED
 *   via DocumentLockedException (dipetakan bootstrap/app.php).
 */
class SigningController extends Controller
{
    private const PER_PAGE = 15;

    /** Status surat yang masih boleh diajukan tanda tangannya. */
    private const REQUESTABLE_STATUS = ['draft', 'menunggu_ttd'];

    /** Status request yang dihitung "aktif" (menghalangi pengajuan ulang). */
    private const ACTIVE_REQUEST_STATUS = ['diminta', 'disetujui'];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * POST /documents/{id}/signers — buat signing_request 'diminta' untuk
     * signer terpilih dan pindahkan surat ke menunggu_ttd.
     */
    public function requestSign(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            [
                'signer_id' => [
                    'required', 'integer',
                    Rule::exists('mysql_document.signers', 'id')->where('is_active', true),
                ],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Document $document */
        $document = Document::query()->findOrFail($id);

        /** @var Signer $signer */
        $signer = Signer::query()->findOrFail($validated['signer_id']);
        $actorId = (int) $request->user()->id;

        $signingRequest = DB::connection($document->getConnectionName())->transaction(function () use ($document, $signer, $actorId, $request): SigningRequest {
            // Re-read DALAM lock: keputusan status/duplikat dibuat atas
            // kondisi terkini (pola reserveNumber/Finalizer).
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::REQUESTABLE_STATUS, true)) {
                throw new DocumentLockedException(
                    "Tanda tangan hanya bisa diajukan untuk draft|menunggu_ttd; surat ini berstatus '{$locked->status}'.",
                );
            }

            // Pembuat surat otomatis masuk blok TTD (PdfRenderer::signersFor)
            // — tidak perlu/boleh diajukan sebagai penandatangan request.
            if ((int) $signer->user_id === $actorId) {
                throw ValidationException::withMessages([
                    'signer_id' => 'Penandatangan tidak boleh sama dengan pembuat surat: pembuat sudah otomatis menandatangani lewat blok tanda tangan.',
                ]);
            }

            // Duplikat: signer yang sama masih punya request aktif
            // (diminta/disetujui) di surat ini — ditolak 'ditolak' tidak
            // menghalangi pengajuan ulang.
            $duplicate = SigningRequest::query()
                ->where('document_id', $locked->id)
                ->where('signer_id', $signer->id)
                ->whereIn('status', self::ACTIVE_REQUEST_STATUS)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'signer_id' => 'Penandatangan ini sudah memiliki permintaan tanda tangan aktif pada surat tersebut.',
                ]);
            }

            $signingRequest = new SigningRequest;
            $signingRequest->id = (string) Str::uuid(); // PK uuid di-generate kode
            $signingRequest->document_id = $locked->id;
            $signingRequest->signer_id = $signer->id;
            $signingRequest->status = 'diminta';
            $signingRequest->requested_at = now();
            $signingRequest->save();

            $locked->status = 'menunggu_ttd';
            $locked->save();

            $this->audit->log(
                $locked,
                AuditLogger::EVENT_SIGN_REQUESTED,
                $actorId,
                ['signer_id' => $signer->id, 'role' => $signer->role],
                $request->ip(),
                $this->userAgent($request),
            );

            return $signingRequest->setRelation('signer', $signer)->setRelation('document', $locked);
        });

        return response()->json([
            'success' => true,
            'data' => $this->requestShape($signingRequest) + [
                'document_status' => $document->fresh()->status,
            ],
        ], 201);
    }

    /**
     * POST /signing-requests/{id}/approve — pemilik request menyetujui
     * (menandatangani). Status surat TETAP menunggu_ttd; finalize menjadi
     * boleh ketika tak ada lagi request 'diminta' (guard Finalizer).
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'disetujui', AuditLogger::EVENT_SIGNED);
    }

    /**
     * POST /signing-requests/{id}/reject — pemilik request menolak; surat
     * kembali ke draft untuk direvisi. Request penandatangan lain
     * dibiarkan apa adanya (lihat catatan kelas).
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'ditolak', AuditLogger::EVENT_SIGN_REJECTED);
    }

    /**
     * GET /signing-requests/mine?status= — daftar request milik signer
     * user login (layar tugas komisaris/direktur), terbaru dulu,
     * paginate 15, dokumen diproyeksikan ringkas.
     */
    public function mine(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['status' => ['nullable', Rule::in(['diminta', 'disetujui', 'ditolak'])]],
            $this->messages(),
            $this->fieldNames(),
        );

        $requests = SigningRequest::query()
            ->whereHas('signer', fn ($query) => $query->where('user_id', $request->user()->id))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->with(['document', 'signer'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $requests->getCollection()->map(fn (SigningRequest $signingRequest) => $this->requestShape($signingRequest))->all(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
                'per_page' => $requests->perPage(),
            ],
        ]);
    }

    /**
     * Satu jalur keputusan approve/reject: otorisasi pemilik → lock surat
     * → re-read request → guard status 'diminta' → putuskan (+ balik draft
     * bila ditolak) → audit.
     *
     * Surat dikunci lebih dulu agar keputusan berserialisasi dengan
     * Finalizer (yang juga mengunci baris surat lalu memeriksa sisa
     * request 'diminta'): mustahil reject membalikkan surat yang baru
     * saja difinalisasi — finalize hanya lanjut bila tak ada 'diminta',
     * dan reject atas request non-diminta ditolak 409.
     *
     * @param  'disetujui'|'ditolak'  $nextStatus
     * @param  AuditLogger::EVENT_*  $event
     */
    private function decide(Request $request, string $id, string $nextStatus, string $event): JsonResponse
    {
        /** @var SigningRequest $signingRequest */
        $signingRequest = SigningRequest::query()->with('signer')->findOrFail($id);

        // HANYA user di balik signer pemilik request yang boleh memutuskan.
        if ((int) $signingRequest->signer->user_id !== (int) $request->user()->id) {
            abort(403, 'Hanya penandatangan pemilik permintaan ini yang boleh memutuskan.');
        }

        $actorId = (int) $request->user()->id;
        $validated = $request->validate(
            ['note' => ['nullable', 'string', 'max:255']],
            $this->messages(),
            $this->fieldNames(),
        );

        [$decided, $documentStatus] = DB::connection($signingRequest->getConnectionName())->transaction(function () use ($signingRequest, $nextStatus, $event, $validated, $actorId, $request): array {
            // Kunci surat dulu (serialisasi dengan Finalizer), lalu re-read
            // request DI DALAM lock untuk keputusan atas kondisi terkini.
            /** @var Document $lockedDocument */
            $lockedDocument = Document::query()->whereKey($signingRequest->document_id)->lockForUpdate()->firstOrFail();

            /** @var SigningRequest $locked */
            $locked = SigningRequest::query()->whereKey($signingRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'diminta') {
                throw new DocumentLockedException(
                    "Permintaan tanda tangan sudah diputuskan ({$locked->status}); hanya permintaan berstatus diminta yang bisa diputuskan.",
                );
            }

            $locked->status = $nextStatus;
            $locked->acted_at = now();
            $locked->note = $validated['note'] ?? null;
            $locked->save();

            // Reject → surat kembali ke draft untuk direvisi penyusun.
            if ($nextStatus === 'ditolak') {
                $lockedDocument->status = 'draft';
                $lockedDocument->save();
            }

            $this->audit->log(
                $lockedDocument,
                $event,
                $actorId,
                array_filter([
                    'signer_id' => $locked->signer_id,
                    'role' => $signingRequest->signer->role,
                    'note' => $validated['note'] ?? null,
                ], fn ($value) => $value !== null),
                $request->ip(),
                $this->userAgent($request),
            );

            return [$locked, $lockedDocument->status];
        });

        return response()->json([
            'success' => true,
            'data' => $this->requestShape($decided) + ['document_status' => $documentStatus],
        ]);
    }

    /**
     * Bentuk satu signing_request untuk API (dipakai ajukan/approve/reject/
     * mine). Dokumen diproyeksikan RINGKAS — cukup untuk daftar tugas.
     *
     * @return array<string, mixed>
     */
    private function requestShape(SigningRequest $signingRequest): array
    {
        $signer = $signingRequest->relationLoaded('signer') ? $signingRequest->signer : $signingRequest->signer()->first();

        return [
            'id' => $signingRequest->id,
            'status' => $signingRequest->status,
            'requested_at' => $signingRequest->requested_at?->toIso8601String(),
            'acted_at' => $signingRequest->acted_at?->toIso8601String(),
            'note' => $signingRequest->note,
            'signer' => [
                'id' => $signer->id,
                'role' => $signer->role,
                'display_name' => $signer->display_name,
                'title' => $signer->title,
            ],
            'document' => $signingRequest->relationLoaded('document') ? [
                'id' => $signingRequest->document->id,
                'number' => $signingRequest->document->number,
                'subject' => $signingRequest->document->subject,
                'status' => $signingRequest->document->status,
            ] : ['id' => $signingRequest->document_id],
        ];
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
            'integer' => ':attribute harus berupa angka bulat.',
            'string' => ':attribute harus berupa teks.',
            'max.string' => ':attribute tidak boleh lebih dari :max karakter.',
            'in' => ':attribute harus salah satu dari: :values.',
            'signer_id.exists' => 'Penandatangan tidak ditemukan atau tidak aktif.',
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
            'signer_id' => 'penandatangan',
            'status' => 'status',
            'note' => 'catatan',
        ];
    }

    /** User agent dipotong agar muat kolom string audit_logs. */
    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}
