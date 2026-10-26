<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DocumentLockedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\LetterType;
use App\Models\Signer;
use App\Services\AuditLogger;
use App\Services\NumberingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use RuntimeException;
use Throwable;

/**
 * API /api/v1/documents + /api/v1/me/signer (Task 13).
 *
 * Envelope sukses {success:true, data, meta?}; error {error:{code,message}}
 * dipetakan terpusat di bootstrap/app.php (401/403/404/409/422) — controller
 * cukup abort()/throw exception domain.
 *
 * Company scoping TIDAK diterapkan fase ini: company_id nullable, disimpan
 * bila dikirim, tanpa filtering.
 */
class DocumentController extends Controller
{
    private const PER_PAGE = 15;

    /** Status yang masih boleh diubah isinya / di-reserve nomornya. */
    private const EDITABLE_STATUS = ['draft', 'menunggu_ttd'];

    public function __construct(
        private readonly NumberingService $numbering = new NumberingService,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * GET /documents — daftar surat, paginate 15 (?page), filter
     * status|letter_type|year|q, eager letterType + activeIssuance.
     */
    public function index(Request $request): JsonResponse
    {
        $documents = Document::query()
            ->with(['letterType', 'activeIssuance'])
            ->when($request->filled('status'), fn ($query) => $query->status((string) $request->string('status')))
            ->when($request->filled('letter_type'), fn ($query) => $query->whereRelation('letterType', 'code', (string) $request->string('letter_type')))
            ->when($request->filled('year'), fn ($query) => $query->where('year', (int) $request->input('year')))
            ->when($request->filled('q'), fn ($query) => $query->search((string) $request->string('q')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => DocumentResource::collection($documents->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'total' => $documents->total(),
                'per_page' => $documents->perPage(),
            ],
        ]);
    }

    /**
     * POST /documents — buat draft Mode A. Nomor surat TIDAK di-reserve di
     * sini (spec §7.1: reserve terjadi saat finalize, atau eksplisit lewat
     * endpoint numbering/reserve untuk surat yang disusun di luar); pra-
     * nomor direpresentasikan string kosong.
     *
     * duplicate_from_id: sumber WAJIB 'terbit' (validasi); isi + lampiran
     * disalin (berkas lampiran di-copy di disk documents dengan path uuid
     * baru — sha256 & size identik karena bytes identik).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->storeRules(), $this->messages(), $this->fieldNames());

        /** @var LetterType $letterType */
        $letterType = LetterType::query()->where('code', $validated['letter_type_code'])->firstOrFail();

        $source = isset($validated['duplicate_from_id'])
            ? Document::query()->findOrFail($validated['duplicate_from_id'])
            : null;

        $user = $request->user();

        $document = new Document([
            'letter_type_id' => $letterType->id,
            'company_id' => $validated['company_id'] ?? null,
            'unit' => $validated['unit'] ?? $source?->unit ?? '',
            'year' => CarbonImmutable::parse($validated['date'])->year,
            'subject' => $validated['subject'] ?? $source?->subject ?? '',
            'date' => $validated['date'],
            'body_html' => array_key_exists('body_html', $validated) ? $validated['body_html'] : $source?->body_html,
            'metadata' => array_key_exists('metadata', $validated) ? $validated['metadata'] : $source?->metadata,
            'classification' => $validated['classification'] ?? $source?->classification ?? 'umum',
            'created_by' => (int) $user->id,
        ]);
        $document->id = (string) Str::uuid(); // tidak fillable — uuid di-generate kode
        // Pra-nomor: string kosong (kolom NOT NULL + unique — hanya satu
        // draft tanpa nomor; reserve/finalize mengisinya).
        $document->number = '';
        $document->seq = 0;
        $document->status = 'draft';          // tidak fillable — hanya lewat jalur ini/Finalizer

        // Berkas lampiran yang tertulis selama transaksi — bila transaksi
        // gagal setelah tulis, disk tidak ikut rollback, jadi dibersihkan
        // best-effort lalu exception asli dilempar ulang (pola Finalizer).
        $writtenPaths = [];

        try {
            DB::connection($document->getConnectionName())->transaction(function () use ($document, $source, $user, $request, &$writtenPaths): void {
                $document->save();

                $this->audit->log(
                    $document,
                    AuditLogger::EVENT_CREATED,
                    (int) $user->id,
                    array_filter(['duplicate_from' => $source?->id]),
                    $request->ip(),
                    $this->userAgent($request),
                );

                foreach ($source?->attachments ?? [] as $attachment) {
                    $contents = Storage::disk('documents')->get($attachment->path);
                    if ($contents === null) {
                        throw new RuntimeException("Berkas lampiran sumber tidak ditemukan di disk documents: {$attachment->path}.");
                    }

                    $copyId = (string) Str::uuid();
                    $extension = pathinfo($attachment->path, PATHINFO_EXTENSION);
                    $copyPath = sprintf(
                        '%d/%s/attachment-%s%s',
                        $document->year,
                        $document->id,
                        $copyId,
                        $extension !== '' ? '.'.$extension : '',
                    );

                    if (Storage::disk('documents')->put($copyPath, $contents) === false) {
                        throw new RuntimeException("Gagal menyalin berkas lampiran ke disk documents: {$copyPath}.");
                    }
                    $writtenPaths[] = $copyPath;

                    $copy = new DocumentAttachment([
                        'document_id' => $document->id,
                        'label' => $attachment->label,
                        'sort' => $attachment->sort,
                        'path' => $copyPath,
                        'sha256' => $attachment->sha256, // bytes identik → hash identik
                        'mime' => $attachment->mime,
                        'size' => $attachment->size,
                    ]);
                    $copy->id = $copyId; // PK uuid di-generate kode, tidak fillable
                    $copy->save();
                }
            });
        } catch (Throwable $e) {
            foreach ($writtenPaths as $path) {
                Storage::disk('documents')->delete($path);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'data' => (new DocumentResource($document->load('letterType')))->resolve($request),
        ], 201);
    }

    /**
     * GET /documents/{id} — bentuk detail: letterType, activeIssuance,
     * issuances (terbaru dulu), attachments, signingRequests + signer.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $document = Document::query()
            ->with(['letterType', 'activeIssuance', 'attachments'])
            ->with(['issuances' => fn ($query) => $query->orderByDesc('finalized_at')])
            ->with('signingRequests.signer')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => (new DocumentResource($document))->resolve($request),
        ]);
    }

    /**
     * PATCH /documents/{id} — ubah isi surat; hanya draft|menunggu_ttd
     * (else 409 DOCUMENT_LOCKED). Kolom siklus hidup tidak bisa diubah
     * dari sini (tidak divalidasi pun).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate($this->updateRules(), $this->messages(), $this->fieldNames());

        /** @var Document $document */
        $document = Document::query()->findOrFail($id);

        if (! in_array($document->status, self::EDITABLE_STATUS, true)) {
            throw new DocumentLockedException(
                "Surat berstatus '{$document->status}' tidak bisa diubah; hanya draft|menunggu_ttd yang boleh diedit.",
            );
        }

        foreach (['subject', 'date', 'body_html', 'metadata', 'classification', 'unit'] as $field) {
            if (array_key_exists($field, $validated)) {
                $document->fill([$field => $validated[$field]]);
            }
        }

        // year diturunkan dari date (dipakai counter nomor + path berkas).
        if (array_key_exists('date', $validated) && $validated['date'] !== null) {
            $document->year = CarbonImmutable::parse($validated['date'])->year;
        }

        $document->save();

        $this->audit->log(
            $document,
            AuditLogger::EVENT_UPDATED,
            (int) $request->user()->id,
            ['action' => 'update_content', 'fields' => array_keys($validated)],
            $request->ip(),
            $this->userAgent($request),
        );

        return response()->json([
            'success' => true,
            'data' => (new DocumentResource($document->load('letterType')))->resolve($request),
        ]);
    }

    /**
     * POST /documents/{id}/numbering/reserve — pesan nomor untuk surat yang
     * disusun di luar sistem. Hanya draft|menunggu_ttd (else 409).
     *
     * Idempoten: bila nomor sudah terisi (reserve/finalize sebelumnya),
     * nomor yang ada dikembalikan TANPA reserve baru — counter tidak dobel.
     * Guard + reserve dijalankan dalam transaksi dengan lock baris surat
     * agar dua reserve paralel surat yang sama tepat satu yang menulis.
     */
    public function reserveNumber(Request $request, string $id): JsonResponse
    {
        $document = Document::query()->findOrFail($id);
        $document->loadMissing('letterType');

        ['number' => $number, 'seq' => $seq] = DB::connection($document->getConnectionName())->transaction(function () use ($document, $request): array {
            // Re-read DALAM lock: keputusan di atas kondisi terkini, bukan
            // snapshot model yang dipanggil (pola Finalizer::persist).
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::EDITABLE_STATUS, true)) {
                throw new DocumentLockedException(
                    "Nomor hanya bisa dipesan untuk draft|menunggu_ttd; surat ini berstatus '{$locked->status}'.",
                );
            }

            // Idempoten: nomor sudah ada → kembalikan apa adanya.
            if ($locked->number !== null && $locked->number !== '') {
                return ['number' => $locked->number, 'seq' => (int) $locked->seq];
            }

            ['seq' => $seq, 'number' => $number] = $this->numbering->reserve(
                $locked->letterType->code,
                $locked->unit,
                $locked->year,
            );

            $locked->number = $number;
            $locked->seq = $seq;
            $locked->save();

            $this->audit->log(
                $locked,
                AuditLogger::EVENT_UPDATED,
                (int) $request->user()->id,
                ['action' => 'reserve_number', 'number' => $number],
                $request->ip(),
                $this->userAgent($request),
            );

            return ['number' => $number, 'seq' => $seq];
        });

        return response()->json([
            'success' => true,
            'data' => ['number' => $number, 'seq' => $seq],
        ]);
    }

    /**
     * GET /me/signer — peran signer milik user login (gate menu Flutter,
     * Task 19). signature_ready = aset tanda tangan benar-benar ada di disk.
     */
    public function meSigner(Request $request): JsonResponse
    {
        $signers = Signer::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'signers' => $signers->map(fn (Signer $signer) => [
                    'id' => $signer->id,
                    'role' => $signer->role,
                    'display_name' => $signer->display_name,
                    'title' => $signer->title,
                    'signature_ready' => $signer->signature_asset_path !== null
                        && Storage::disk('documents')->exists($signer->signature_asset_path),
                ])->all(),
            ],
        ]);
    }

    /**
     * Aturan validasi POST /documents (draft Mode A + duplikasi).
     *
     * @return array<string, list<string|Exists>>
     */
    private function storeRules(): array
    {
        return [
            'letter_type_code' => [
                'required', 'string', 'max:8',
                // Tabel domain hidup di koneksi mysql_document — prefix
                // koneksi wajib untuk rule exists (koneksi default berbeda).
                Rule::exists('mysql_document.letter_types', 'code')->where('is_active', true),
            ],
            // unit wajib diisi kecuali saat duplikasi (turunan unit sumber).
            'unit' => ['required_without:duplicate_from_id', 'nullable', 'string', 'max:16'],
            'subject' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'body_html' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'classification' => ['nullable', Rule::in(['umum', 'rahasia'])],
            'company_id' => ['nullable', 'uuid'],
            'template_id' => ['nullable', 'integer', Rule::exists('mysql_document.templates', 'id')],
            'duplicate_from_id' => [
                'nullable', 'uuid',
                // Sumber duplikasi wajib sudah terbit (else 422).
                Rule::exists('mysql_document.documents', 'id')->where('status', 'terbit'),
            ],
        ];
    }

    /**
     * Aturan validasi PATCH /documents/{id} — semua sometimes/nullable,
     * hanya field terkirim yang di-update.
     *
     * @return array<string, list<string|In>>
     */
    private function updateRules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date'],
            'body_html' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'classification' => ['sometimes', 'nullable', Rule::in(['umum', 'rahasia'])],
            'unit' => ['sometimes', 'required', 'string', 'max:16'],
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
            'required_without' => ':attribute wajib diisi bila :values tidak diisi.',
            'string' => ':attribute harus berupa teks.',
            'integer' => ':attribute harus berupa angka bulat.',
            'uuid' => ':attribute harus berupa UUID yang valid.',
            'date' => ':attribute harus berupa tanggal yang valid.',
            'array' => ':attribute harus berupa objek/array.',
            'max.string' => ':attribute tidak boleh lebih dari :max karakter.',
            'in' => ':attribute harus salah satu dari: :values.',
            'letter_type_code.exists' => 'Kode jenis surat tidak ditemukan atau tidak aktif.',
            'template_id.exists' => 'Template tidak ditemukan.',
            'duplicate_from_id.exists' => 'Surat sumber duplikasi tidak ditemukan atau belum berstatus terbit.',
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
            'letter_type_code' => 'kode jenis surat',
            'unit' => 'unit',
            'subject' => 'perihal',
            'date' => 'tanggal surat',
            'body_html' => 'isi surat',
            'metadata' => 'metadata',
            'classification' => 'klasifikasi',
            'company_id' => 'perusahaan',
            'template_id' => 'template',
            'duplicate_from_id' => 'surat sumber duplikasi',
        ];
    }

    /** User agent dipotong agar muat kolom string audit_logs. */
    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}
