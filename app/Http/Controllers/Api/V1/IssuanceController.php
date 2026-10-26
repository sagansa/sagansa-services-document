<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AlreadySignedException;
use App\Exceptions\DocumentLockedException;
use App\Exceptions\PdfInvalidException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Issuance;
use App\Services\AuditLogger;
use App\Services\Finalizer;
use App\Services\ImageToPdfComposer;
use App\Services\PdfRenderer;
use App\Services\PdfStamper;
use App\Services\QrBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API penerbitan inti (Task 16):
 *
 *   POST /documents/{id}/pre-final   pra-final mode a (render) / b (stempel
 *                                    QR ke PDF unggahan) → disimpan sementara
 *                                    + URL signed 15 menit.
 *   GET  /documents/{id}/pre-final/download
 *                                    unduh hasil pra-final — OTORISASINYA
 *                                    ADALAH URL SIGNED (route di luar grup
 *                                    auth:sanctum/document.role, middleware
 *                                    `signed`), berkas dihapus setelah
 *                                    terkirim.
 *   POST /documents/{id}/finalize    terbitkan: a render, b stempel, c PDF
 *                                    ttd elektronik resmi, d foto/scan
 *                                    (multipart images[] → ImageToPdfComposer).
 *   POST /documents/{id}/reissue     terbitkan ulang surat terbit (note
 *                                    wajib; issuance lama dinonaktifkan).
 *   POST /documents/{id}/revoke      cabut — HANYA jenis KUA + status terbit.
 *   POST /documents/{id}/void        batalkan — draft|menunggu_ttd|terbit.
 *
 * Semua penerbitan bermuara ke Finalizer (transaksi + lock baris surat);
 * controller hanya menyiapkan bytes per metode dan menjaga awal khusus
 * (status pra-final, magic %PDF, metode c tanpa overlay, note reissue).
 */
class IssuanceController extends Controller
{
    /** Status yang masih boleh dibuat pra-finalnya. */
    private const PRE_FINAL_STATUS = ['draft', 'menunggu_ttd'];

    /** Umur URL unduh pra-final (detik) — sinkron dengan addMinutes(15). */
    private const PRE_FINAL_TTL_SECONDS = 900;

    /** Posisi overlay QR default (mode b tanpa overlay custom). */
    private const DEFAULT_QR_PAGE = 1;

    private const DEFAULT_QR_X_MM = 165.0;

    private const DEFAULT_QR_Y_MM = 15.0;

    private const DEFAULT_QR_WIDTH_MM = 25.0;

    public function __construct(
        private readonly Finalizer $finalizer = new Finalizer,
        private readonly PdfRenderer $renderer = new PdfRenderer,
        private readonly PdfStamper $stamper = new PdfStamper,
        private readonly QrBuilder $qrBuilder = new QrBuilder,
        private readonly ImageToPdfComposer $composer = new ImageToPdfComposer,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    // ——— pra-final ———

    /**
     * POST /documents/{id}/pre-final — {mode:'a'|'b', pdf_base64?, overlays?}.
     *
     * Mode a: bytes = PdfRenderer::render. Mode b: pdf_base64 wajib
     * (magic %PDF dicek → 422 PDF_INVALID), bytes = PdfStamper::stamp;
     * overlays kosong → overlay QR default posisi. Hasil disimpan ke
     * disk local `pre-final/{docId}-{ts}.pdf` dan dikembalikan sebagai
     * URL signed 15 menit — status surat TIDAK berubah.
     */
    public function preFinal(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            [
                'mode' => ['required', Rule::in(['a', 'b'])],
                'pdf_base64' => ['nullable', 'string', 'required_if:mode,b'],
                ...$this->overlayRules(),
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Document $document */
        $document = Document::query()->findOrFail($id);

        if (! in_array($document->status, self::PRE_FINAL_STATUS, true)) {
            throw new DocumentLockedException(
                "Pra-final hanya bisa untuk draft|menunggu_ttd; surat ini berstatus '{$document->status}'.",
            );
        }

        $bytes = $validated['mode'] === 'a'
            ? $this->renderer->render($document)
            : $this->stamper->stamp(
                $this->decodePdfBase64((string) ($validated['pdf_base64'] ?? '')),
                $this->overlaysOrDefault($validated['overlays'] ?? null, $document),
            );

        $path = sprintf('pre-final/%s-%d.pdf', $document->id, now()->getTimestamp());
        if (Storage::disk('local')->put($path, $bytes) === false) {
            throw new RuntimeException("Gagal menulis berkas pra-final ke disk local: {$path}.");
        }

        $this->audit->log(
            $document,
            AuditLogger::EVENT_PRE_FINAL,
            (int) $request->user()->id,
            ['mode' => $validated['mode']],
            $request->ip(),
            $this->userAgent($request),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'download_url' => URL::temporarySignedRoute(
                    'documents.pre-final.download',
                    now()->addSeconds(self::PRE_FINAL_TTL_SECONDS),
                    ['id' => $document->id],
                ),
                'expires_in' => self::PRE_FINAL_TTL_SECONDS,
            ],
        ]);
    }

    /**
     * GET /documents/{id}/pre-final/download — khusus pemegang URL signed
     * (middleware `signed`; 403 bila tanda tangan hilang/salah/kedaluwarsa).
     * Menyajikan berkas pra-final TERBARU milik surat lalu menghapusnya
     * (delete-after-send); versi lama ikut dibersihkan.
     */
    public function downloadPreFinal(Request $request, string $id): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        $prefix = "pre-final/{$id}-";

        $candidates = array_values(array_filter(
            $disk->files('pre-final'),
            fn (string $path): bool => str_starts_with($path, $prefix),
        ));

        if ($candidates === []) {
            abort(404, 'Berkas pra-final tidak ditemukan atau sudah terunduh.');
        }

        usort($candidates, fn (string $a, string $b): int => $disk->lastModified($b) <=> $disk->lastModified($a));
        $newest = array_shift($candidates);

        foreach ($candidates as $stale) {
            $disk->delete($stale);
        }

        return response()
            ->download($disk->path($newest), basename($newest), ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    // ——— finalize / reissue ———

    /**
     * POST /documents/{id}/finalize — dua bentuk request:
     * (i)  JSON {method:'a'|'b'|'c', overlays?, pdf_base64?}
     * (ii) multipart {method:'d', images[]: file[], note?} — foto/scan,
     *      tiap foto maks 20 MB, dikomposisi menjadi satu PDF.
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            [
                'method' => ['required', Rule::in(['a', 'b', 'c', 'd'])],
                'pdf_base64' => ['nullable', 'string', 'required_if:method,b,c'],
                ...$this->overlayRules(),
                'images' => ['nullable', 'array', 'min:1', 'required_if:method,d'],
                'images.*' => ['file', 'image', 'max:20480'],
                'note' => ['nullable', 'string', 'max:255'],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Document $document */
        $document = Document::query()->findOrFail($id);
        $actorId = (int) $request->user()->id;

        // Metode c (ttd_elektronik_resmi) = PDF sudah ditandatangani pihak
        // luar — tidak boleh menyertakan koordinat overlay (lihat catatan
        // Finalizer::finalizeFromUpload).
        if ($validated['method'] === 'c' && ! empty($validated['overlays'])) {
            throw new AlreadySignedException(
                'Metode c (ttd_elektronik_resmi) tidak boleh menyertakan overlay; gunakan metode b untuk penempelan QR.',
            );
        }

        $issuance = match ($validated['method']) {
            'a' => $this->finalizer->finalizeFromContent($document, $actorId),
            'b' => $this->finalizer->finalizeFromStamps(
                $document,
                $this->decodePdfBase64((string) ($validated['pdf_base64'] ?? '')),
                $this->overlaysOrDefault($validated['overlays'] ?? null, $document),
                $actorId,
            ),
            'c' => $this->finalizer->finalizeFromUpload(
                $document,
                $this->decodePdfBase64((string) ($validated['pdf_base64'] ?? '')),
                'c',
                $actorId,
            ),
            'd' => $this->finalizer->finalizeFromUpload(
                $document,
                $this->composer->compose(array_map(
                    fn ($file): string => (string) $file->getContent(),
                    (array) ($validated['images'] ?? []),
                )),
                'd',
                $actorId,
            ),
        };

        return response()->json([
            'success' => true,
            'data' => [
                'issuance' => $this->issuanceShape($issuance),
                'verify_url' => config('document.domain')."/verify/{$document->id}",
                'number' => $document->fresh()->number,
            ],
        ], 201);
    }

    /**
     * POST /documents/{id}/reissue — JSON {method:'a'..'d', note: wajib,
     * overlays?, pdf_base64?, images_base64?: string[]}. Hanya untuk surat
     * berstatus terbit (guard Finalizer → 409); note alasan tercatat di
     * issuance baru + jejak audit REISSUED.
     */
    public function reissue(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            [
                'method' => ['required', Rule::in(['a', 'b', 'c', 'd'])],
                'note' => ['required', 'string', 'max:255'],
                'pdf_base64' => ['nullable', 'string', 'required_if:method,b,c'],
                ...$this->overlayRules(),
                'images_base64' => ['nullable', 'array', 'min:1', 'required_if:method,d'],
                'images_base64.*' => ['string'],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Document $document */
        $document = Document::query()->findOrFail($id);
        $actorId = (int) $request->user()->id;
        $note = (string) $validated['note'];

        if ($validated['method'] === 'c' && ! empty($validated['overlays'])) {
            throw new AlreadySignedException(
                'Metode c (ttd_elektronik_resmi) tidak boleh menyertakan overlay; gunakan metode b untuk penempelan QR.',
            );
        }

        // Pabrik bytes versi baru — dieksekusi Finalizer::reissue di dalam
        // transaksinya (guard status dulu, baru produksi + persist).
        $bytesProducer = function () use ($validated, $document): string {
            return match ($validated['method']) {
                'a' => $this->renderer->render($document),
                'b' => $this->stamper->stamp(
                    $this->decodePdfBase64((string) ($validated['pdf_base64'] ?? '')),
                    $this->overlaysOrDefault($validated['overlays'] ?? null, $document),
                ),
                'c' => $this->decodePdfBase64((string) ($validated['pdf_base64'] ?? '')),
                'd' => $this->composer->compose(
                    $this->decodeImagesBase64((array) ($validated['images_base64'] ?? [])),
                ),
            };
        };

        $issuance = $this->finalizer->reissue($document, $validated['method'], $bytesProducer, $note, $actorId);

        return response()->json([
            'success' => true,
            'data' => [
                'issuance' => $this->issuanceShape($issuance),
                'verify_url' => config('document.domain')."/verify/{$document->id}",
                'number' => $document->fresh()->number,
                'note' => $note,
            ],
        ], 201);
    }

    // ——— revoke / void ———

    /**
     * POST /documents/{id}/revoke — {reason, revoked_at} — HANYA jenis
     * surat KUA dan HANYA status terbit: status → dicabut, revoked_at +
     * revocation_reason terisi. Issuance & berkas tetap tersimpan
     * (riwayat penerbitan tidak pernah dihapus).
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            [
                'reason' => ['required', 'string', 'max:255'],
                'revoked_at' => ['required', 'date'],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Document $document */
        $document = Document::query()->with('letterType')->findOrFail($id);

        $code = strtoupper((string) $document->letterType->code);
        if ($code !== 'KUA') {
            throw ValidationException::withMessages([
                'letter_type' => "Pencabutan hanya berlaku untuk surat jenis KUA; surat ini jenis '{$document->letterType->code}'.",
            ]);
        }

        $actorId = (int) $request->user()->id;
        $revokedAt = Carbon::parse($validated['revoked_at']);

        $revoked = DB::connection($document->getConnectionName())->transaction(function () use ($document, $validated, $actorId, $revokedAt, $request): Document {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'terbit') {
                throw new DocumentLockedException(
                    "Pencabutan hanya untuk surat berstatus terbit; surat ini berstatus '{$locked->status}'.",
                );
            }

            $locked->status = 'dicabut';
            $locked->revoked_at = $revokedAt;
            $locked->revocation_reason = $validated['reason'];
            $locked->save();

            $this->audit->log(
                $locked,
                AuditLogger::EVENT_REVOKED,
                $actorId,
                [
                    'reason' => $validated['reason'],
                    'revoked_at' => $revokedAt->toIso8601String(),
                ],
                $request->ip(),
                $this->userAgent($request),
            );

            return $locked;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $revoked->id,
                'status' => $revoked->status,
                'revoked_at' => $revoked->revoked_at?->toIso8601String(),
                'reason' => $revoked->revocation_reason,
            ],
        ]);
    }

    /**
     * POST /documents/{id}/void — {reason} — semua jenis surat, status
     * draft|menunggu_ttd|terbit boleh (dicabut|dibatalkan → 409):
     * status → dibatalkan.
     */
    public function void(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Document $document */
        $document = Document::query()->findOrFail($id);
        $actorId = (int) $request->user()->id;

        $voided = DB::connection($document->getConnectionName())->transaction(function () use ($document, $validated, $actorId, $request): Document {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['draft', 'menunggu_ttd', 'terbit'], true)) {
                throw new DocumentLockedException(
                    "Pembatalan tidak bisa untuk surat berstatus '{$locked->status}'.",
                );
            }

            $locked->status = 'dibatalkan';
            $locked->save();

            $this->audit->log(
                $locked,
                AuditLogger::EVENT_VOIDED,
                $actorId,
                ['reason' => $validated['reason']],
                $request->ip(),
                $this->userAgent($request),
            );

            return $locked;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $voided->id,
                'status' => $voided->status,
                'reason' => $validated['reason'],
            ],
        ]);
    }

    // ——— helper bersama ———

    /**
     * Aturan validasi overlay: tiap item {page, x_mm, y_mm, width_mm} +
     * image_base64 opsional (default: QR verifikasi dokumen).
     *
     * @return array<string, list<string>>
     */
    private function overlayRules(): array
    {
        return [
            'overlays' => ['nullable', 'array'],
            'overlays.*.page' => ['required_with:overlays', 'integer', 'min:1'],
            'overlays.*.x_mm' => ['required_with:overlays', 'numeric', 'min:0'],
            'overlays.*.y_mm' => ['required_with:overlays', 'numeric', 'min:0'],
            'overlays.*.width_mm' => ['required_with:overlays', 'numeric', 'gt:0'],
            'overlays.*.image_base64' => ['nullable', 'string'],
        ];
    }

    /**
     * Normalisasi overlay request → kontrak PdfStamper (image binary).
     * Bila tidak ada overlay sama sekali → satu overlay QR default
     * (page 1, x_mm 165, y_mm 15, width_mm 25).
     *
     * @param  list<array<string, mixed>>|null  $overlays
     * @return list<array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}>
     */
    private function overlaysOrDefault(?array $overlays, Document $document): array
    {
        if ($overlays === null || $overlays === []) {
            return [[
                'image' => $this->qrBuilder->build($document->id),
                'page' => self::DEFAULT_QR_PAGE,
                'x_mm' => self::DEFAULT_QR_X_MM,
                'y_mm' => self::DEFAULT_QR_Y_MM,
                'width_mm' => self::DEFAULT_QR_WIDTH_MM,
            ]];
        }

        return array_map(function (array $overlay) use ($document): array {
            $imageBase64 = $overlay['image_base64'] ?? null;

            return [
                'image' => is_string($imageBase64) && $imageBase64 !== ''
                    ? $this->decodeImageBase64($imageBase64)
                    : $this->qrBuilder->build($document->id),
                'page' => (int) $overlay['page'],
                'x_mm' => (float) $overlay['x_mm'],
                'y_mm' => (float) $overlay['y_mm'],
                'width_mm' => (float) $overlay['width_mm'],
            ];
        }, $overlays);
    }

    /**
     * Decode base64 → wajib PDF (magic %PDF), selain itu 422 PDF_INVALID.
     */
    private function decodePdfBase64(string $base64): string
    {
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new PdfInvalidException('pdf_base64 bukan base64 yang valid.');
        }

        if (! str_starts_with($bytes, '%PDF')) {
            throw new PdfInvalidException('pdf_base64 tidak berisi PDF valid (magic byte %PDF tidak ada di awal).');
        }

        return $bytes;
    }

    /**
     * Decode daftar gambar base64 (reissue metode d) — tiap entri harus
     * base64 valid dan terbaca sebagai gambar.
     *
     * @param  list<string>  $imagesBase64
     * @return list<string>
     */
    private function decodeImagesBase64(array $imagesBase64): array
    {
        return array_map(function (string $base64): string {
            $bytes = base64_decode($base64, true);

            if ($bytes === false || $bytes === '' || getimagesizefromstring($bytes) === false) {
                throw ValidationException::withMessages([
                    'images_base64' => 'Setiap images_base64 harus berupa gambar valid yang di-encode base64.',
                ]);
            }

            return $bytes;
        }, $imagesBase64);
    }

    /**
     * Decode image_base64 satu overlay → binary gambar.
     */
    private function decodeImageBase64(string $base64): string
    {
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '' || getimagesizefromstring($bytes) === false) {
            throw ValidationException::withMessages([
                'overlays' => 'overlays.*.image_base64 harus berupa gambar valid yang di-encode base64.',
            ]);
        }

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private function issuanceShape(Issuance $issuance): array
    {
        return [
            'id' => $issuance->id,
            'method' => $issuance->method,
            'method_label' => $issuance->method_label,
            'file_sha256' => $issuance->file_sha256,
            'finalized_at' => $issuance->finalized_at?->toIso8601String(),
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
            'required_if' => ':attribute wajib diisi ketika :other bernilai :value.',
            'required_with' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'array' => ':attribute harus berupa daftar.',
            'min.array' => ':attribute minimal berisi :min item.',
            'integer' => ':attribute harus berupa angka bulat.',
            'numeric' => ':attribute harus berupa angka.',
            'date' => ':attribute harus berupa tanggal yang valid.',
            'in' => ':attribute harus salah satu dari: :values.',
            'file' => ':attribute harus berupa berkas.',
            'image' => ':attribute harus berupa gambar.',
            'max.file' => ':attribute tidak boleh lebih dari :max kilobyte (20 MB).',
            'max.string' => ':attribute tidak boleh lebih dari :max karakter.',
            'min.integer' => ':attribute minimal :min.',
            'min.numeric' => ':attribute minimal :min.',
            'gt.numeric' => ':attribute harus lebih besar dari :value.',
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
            'mode' => 'mode pra-final',
            'method' => 'metode',
            'pdf_base64' => 'berkas PDF (base64)',
            'overlays' => 'overlay',
            'overlays.*.page' => 'halaman overlay',
            'overlays.*.x_mm' => 'posisi x overlay',
            'overlays.*.y_mm' => 'posisi y overlay',
            'overlays.*.width_mm' => 'lebar overlay',
            'overlays.*.image_base64' => 'gambar overlay (base64)',
            'images' => 'foto/scan',
            'images.*' => 'foto/scan',
            'images_base64' => 'foto/scan (base64)',
            'images_base64.*' => 'foto/scan (base64)',
            'note' => 'catatan',
            'reason' => 'alasan',
            'revoked_at' => 'tanggal pencabutan',
        ];
    }

    /** User agent dipotong agar muat kolom string audit_logs. */
    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}
