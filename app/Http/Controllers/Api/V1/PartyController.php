<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API /api/v1/parties (Task 14) — buku pihak eksternal (penerima/kuasa)
 * yang bisa dipakai ulang antar surat: daftar + cari (q), tambah, ubah.
 *
 * Perusahaan scoping TIDAK diterapkan fase ini (tabel tanpa company_id).
 */
class PartyController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * GET /parties?q= — daftar pihak, cari nama/NIK (LIKE, karakter
     * wildcard di-escape), paginate 15.
     */
    public function index(Request $request): JsonResponse
    {
        $parties = Party::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';

                $query->where(function ($q) use ($like): void {
                    $q->where('name', 'like', $like)
                        ->orWhere('identity_no', 'like', $like);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $parties->getCollection()
                ->map(fn (Party $party) => $this->resource($party))
                ->all(),
            'meta' => [
                'current_page' => $parties->currentPage(),
                'last_page' => $parties->lastPage(),
                'total' => $parties->total(),
                'per_page' => $parties->perPage(),
            ],
        ]);
    }

    /**
     * POST /parties — tambah pihak baru ke buku pihak.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(
            $this->rules(requiredName: true),
            $this->messages(),
            $this->fieldNames(),
        );

        $party = Party::query()->create([
            'name' => $validated['name'],
            'identity_no' => $validated['identity_no'] ?? null,
            'address' => $validated['address'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->resource($party),
        ], 201);
    }

    /**
     * PATCH /parties/{id} — ubah field pihak; hanya field terkirim.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(
            $this->rules(requiredName: false),
            $this->messages(),
            $this->fieldNames(),
        );

        /** @var Party $party */
        $party = Party::query()->findOrFail($id);
        $party->fill($validated)->save();

        return response()->json([
            'success' => true,
            'data' => $this->resource($party->fresh()),
        ]);
    }

    /**
     * Bentuk pihak untuk response.
     *
     * @return array<string, mixed>
     */
    private function resource(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'identity_no' => $party->identity_no,
            'address' => $party->address,
            'occupation' => $party->occupation,
            'notes' => $party->notes,
            'created_at' => $party->created_at?->toIso8601String(),
        ];
    }

    /**
     * Aturan validasi — PATCH memakai sometimes (semua opsional saat ubah).
     *
     * @return array<string, list<string>>
     */
    private function rules(bool $requiredName): array
    {
        $sometimes = $requiredName ? [] : ['sometimes'];

        return [
            'name' => [...$sometimes, 'required', 'string', 'max:255'],
            'identity_no' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'address' => [...$sometimes, 'nullable', 'string'],
            'occupation' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'notes' => [...$sometimes, 'nullable', 'string'],
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
            'string' => ':attribute harus berupa teks.',
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
            'name' => 'nama pihak',
            'identity_no' => 'nomor identitas',
            'address' => 'alamat',
            'occupation' => 'pekerjaan',
            'notes' => 'catatan',
        ];
    }
}
