<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ScanInvalidException;
use App\Http\Controllers\Controller;
use App\Http\Resources\IncomingLetterResource;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use App\Models\Party;
use App\Services\IncomingLetterService;
use App\Services\ThreadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API /api/v1/incoming-letters (fase 1b) — registrasi + buku agenda surat
 * masuk: create (scan PDF tunggal ATAU foto multi → satu PDF), list +
 * filter, detail (disposisi + thread + jumlah link), PATCH (kunci saat
 * `selesai`), naik status (hanya maju), stream scan.
 *
 * Semua mutasi isi baris bermuara ke IncomingLetterService (transaksi,
 * kunci baris, audit) — controller hanya memvalidasi request, menyiapkan
 * bytes scan, dan membentuk response. Shape error terpusat di
 * bootstrap/app.php: 409 INCOMING_LOCKED, 422 SCAN_INVALID, dsb.
 */
class IncomingLetterController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly IncomingLetterService $service = new IncomingLetterService,
        private readonly ThreadBuilder $threads = new ThreadBuilder,
    ) {}

    /**
     * GET /incoming-letters — filter status|sifat|year|q (LIKE pada
     * agenda_number/number_external/subject, wildcard di-escape), urut
     * received_at desc, paginate 15, eager senderParty.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(
            [
                'status' => ['nullable', Rule::in(['terdaftar', 'diproses', 'selesai'])],
                'sifat' => ['nullable', Rule::in(['biasa', 'segera', 'rahasia'])],
                'year' => ['nullable', 'integer', 'min:2000', 'max:2999'],
                'q' => ['nullable', 'string', 'max:255'],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        $letters = IncomingLetter::query()
            ->with('senderParty')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['sifat'] ?? null, fn ($query, string $sifat) => $query->where('sifat', $sifat))
            ->when($filters['year'] ?? null, fn ($query, int $year) => $query->where('year', $year))
            ->when($filters['q'] ?? null, function ($query, string $q): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

                $query->where(function ($where) use ($like): void {
                    $where->where('agenda_number', 'like', $like)
                        ->orWhere('number_external', 'like', $like)
                        ->orWhere('subject', 'like', $like);
                });
            })
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => IncomingLetterResource::collection($letters->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $letters->currentPage(),
                'last_page' => $letters->lastPage(),
                'total' => $letters->total(),
                'per_page' => $letters->perPage(),
            ],
        ]);
    }

    /**
     * POST /incoming-letters (multipart) — daftarkan surat masuk.
     *
     * Scan dikirim SALAH SATU bentuk: `scan` (pdf|jpg|png, maks 20 MB) atau
     * `scan_images[]` (foto jpg|png masing-masing maks 20 MB, digabung
     * ImageToPdfComposer menjadi satu PDF). Tanpa keduanya / keduanya
     * sekaligus → 422 SCAN_INVALID. `scan` gambar tunggal diperlakukan
     * sebagai foto satu halaman (kind 'images') — hanya `scan` PDF yang
     * lewat jalur kind 'pdf'.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                ...$this->fieldRules(),
                'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
                'scan_images' => ['nullable', 'array', 'min:1'],
                'scan_images.*' => ['file', 'mimes:jpg,jpeg,png', 'max:20480'],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        $scan = $request->file('scan');
        $images = (array) $request->file('scan_images', []);

        if ($scan !== null && $images !== []) {
            throw new ScanInvalidException(
                'Scan hanya boleh satu bentuk: kirim berkas scan (PDF/JPG/PNG) ATAU daftar foto scan_images, tidak keduanya.',
            );
        }

        if ($scan === null && $images === []) {
            throw new ScanInvalidException(
                'Scan wajib dikirim: unggah berkas scan (PDF/JPG/PNG) atau minimal satu foto scan_images.',
            );
        }

        // Bentuk bytes untuk service: scan PDF → 'pdf'; selain itu (foto
        // multi atau scan gambar tunggal) → 'images' (difoto-compose).
        $kind = 'pdf';
        $scanBytes = '';
        $imageBytes = [];

        if ($scan !== null && $scan->getMimeType() === 'application/pdf') {
            $scanBytes = (string) $scan->getContent();
        } else {
            $kind = 'images';
            $imageBytes = array_map(
                fn ($file): string => (string) $file->getContent(),
                $scan !== null ? [$scan] : $images,
            );
        }

        $data = collect($validated)->except(['scan', 'scan_images'])->all();

        $letter = $this->service->register($data, $scanBytes, $kind, $imageBytes, (int) $request->user()->id);

        // Kolom default DB (classification 'umum') tidak terisi pada model
        // in-memory jalur service — refresh agar response jujur terhadap
        // baris yang baru saja ter-commit (transaksi sudah selesai).
        $letter->refresh();

        return response()->json([
            'success' => true,
            'data' => (new IncomingLetterResource($letter->load('senderParty')))->resolve($request),
        ], 201);
    }

    /**
     * GET /incoming-letters/{id} — bentuk detail: senderParty, dispositions
     * (urut created_at, lalu id agar deterministik), thread (embed hasil
     * ThreadBuilder dari akar runutan) + links_count (edge letter_links
     * kedua arah). Rahasia tetap penuh — internal (pola fase 1).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()
            ->with([
                'senderParty',
                'dispositions' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ])
            ->findOrFail($id);

        $resource = new IncomingLetterResource($letter);
        $resource->thread = $this->threads->build('incoming_letter', (string) $letter->id);
        $resource->linksCount = LetterLink::query()
            ->where(fn ($query) => $query
                ->where('parent_type', 'incoming_letter')
                ->where('parent_id', $letter->id))
            ->orWhere(fn ($query) => $query
                ->where('child_type', 'incoming_letter')
                ->where('child_id', $letter->id))
            ->count();

        return response()->json([
            'success' => true,
            'data' => $resource->resolve($request),
        ]);
    }

    /**
     * PATCH /incoming-letters/{id} — ubah kolom isian (minus scan);
     * IncomingLetterService::update mengunci baris, membekukan surat
     * `selesai` (409 INCOMING_LOCKED via mapping bootstrap) dan mencatat
     * audit incoming_updated.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            $this->patchRules(),
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()->findOrFail($id);

        $updated = $this->service->update($letter, $validated, (int) $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => (new IncomingLetterResource($updated->load('senderParty')))->resolve($request),
        ]);
    }

    /**
     * POST /incoming-letters/{id}/status {status} — naikkan status; hanya
     * boleh MAJU terdaftar→diproses→selesai (mundur/mengulang 409).
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            ['status' => ['required', Rule::in(['terdaftar', 'diproses', 'selesai'])]],
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()->findOrFail($id);

        $updated = $this->service->markStatus($letter, $validated['status'], (int) $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => (new IncomingLetterResource($updated->load('senderParty')))->resolve($request),
        ]);
    }

    /**
     * GET /incoming-letters/{id}/scan — stream scan.pdf dari disk documents
     * (gate sama dengan metadata; rahasia tetap boleh — internal). Isi
     * tidak pernah lewat URL publik. Filename = agenda number tersanitasi
     * (pola fase 1 VerifyController) + .pdf.
     */
    public function scan(Request $request, string $id): StreamedResponse
    {
        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()->findOrFail($id);

        $disk = Storage::disk('documents');

        if (! $disk->exists($letter->scan_path)) {
            abort(404, 'Berkas scan tidak ditemukan.');
        }

        return $disk->download(
            $letter->scan_path,
            $this->safeFilename($letter->agenda_number.'.pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Aturan kolom isian untuk create (semua kadarnya wajar untuk POST).
     *
     * @return array<string, list<string|callable>>
     */
    private function fieldRules(): array
    {
        return [
            // Pihak pengirim mesti sudah terdaftar lewat endpoint parties
            // (create-on-fly TIDAK dipakai fase ini) — pengecekan lewat
            // closure agar tetap membaca koneksi mysql_document.
            'sender_party_id' => ['required', 'integer', $this->partyExists()],
            'number_external' => ['nullable', 'string', 'max:255'],
            'letter_date' => ['nullable', 'date'],
            'received_at' => ['required', 'date'],
            'subject' => ['required', 'string', 'max:255'],
            'sifat' => ['required', Rule::in(['biasa', 'segera', 'rahasia'])],
            'attachments_note' => ['nullable', 'string'],
            'company_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Aturan PATCH — kolom sama seperti create minus scan; semua `sometimes`
     * (hanya field terkirim yang divalidasi/diubah).
     *
     * @return array<string, list<string|callable>>
     */
    private function patchRules(): array
    {
        return [
            'sender_party_id' => ['sometimes', 'required', 'integer', $this->partyExists()],
            'number_external' => ['sometimes', 'nullable', 'string', 'max:255'],
            'letter_date' => ['sometimes', 'nullable', 'date'],
            'received_at' => ['sometimes', 'required', 'date'],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'sifat' => ['sometimes', 'required', Rule::in(['biasa', 'segera', 'rahasia'])],
            'attachments_note' => ['sometimes', 'nullable', 'string'],
            'company_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * Closure exists untuk parties pada koneksi model (bukan koneksi
     * default) — Rule::exists string memakai koneksi default.
     *
     * @return callable(string, string, callable): void
     */
    private function partyExists(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (! Party::query()->whereKey($value)->exists()) {
                $fail('Pihak pengirim tidak ditemukan pada buku pihak.');
            }
        };
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
            'string' => ':attribute harus berupa teks.',
            'date' => ':attribute harus berupa tanggal yang valid.',
            'uuid' => ':attribute harus berupa UUID yang valid.',
            'integer' => ':attribute harus berupa angka bulat.',
            'in' => ':attribute harus salah satu dari: :values.',
            'file' => ':attribute harus berupa berkas.',
            'mimes' => ':attribute harus berupa berkas bertipe: :values.',
            'max.file' => ':attribute tidak boleh lebih dari :max kilobyte (20 MB).',
            'max.string' => ':attribute tidak boleh lebih dari :max karakter.',
            'min.array' => ':attribute minimal berisi :min berkas.',
            'min.integer' => ':attribute minimal :min.',
            'max.integer' => ':attribute tidak boleh lebih dari :max.',
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
            'sender_party_id' => 'pihak pengirim',
            'number_external' => 'nomor surat pengirim',
            'letter_date' => 'tanggal surat',
            'received_at' => 'tanggal diterima',
            'subject' => 'perihal',
            'sifat' => 'sifat surat',
            'attachments_note' => 'uraian lampiran',
            'company_id' => 'perusahaan',
            'status' => 'status',
            'year' => 'tahun',
            'q' => 'kata kunci',
            'scan' => 'berkas scan',
            'scan_images' => 'foto scan',
            'scan_images.*' => 'foto scan',
        ];
    }

    /**
     * Filename aman untuk Content-Disposition (pola fase 1 VerifyController:
     * karakter di luar A-Za-z0-9._- menjadi '-', dipotong 100 karakter —
     * agenda number "SM-001/X/2026" → "SM-001-X-2026").
     */
    private function safeFilename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'scan';

        return substr($clean, 0, 100);
    }
}
