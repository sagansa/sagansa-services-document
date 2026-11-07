<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LetterType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API /api/v1/letter-types (Task 14) — daftar jenis surat AKTIF urut kode,
 * dipakai wizard memilih jenis surat + definisi field khususnya.
 *
 * `store` menutup celah "jenis surat tidak ada di daftar": pengguna dapat
 * menambahkan jenis baru langsung dari wizard (mis. Surat Tanggapan untuk
 * membalas SP2DK/teguran instansi lain) tanpa menunggu seeder.
 */
class LetterTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = LetterType::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $types->map(fn (LetterType $type): array => $this->payload($type))->all(),
        ]);
    }

    /**
     * POST /letter-types — tambah jenis surat manual.
     *
     * `code` opsional: bila kosong, diturunkan dari nama (maks 8 karakter,
     * huruf besar/angka) dan diberi akhiran angka bila sudah dipakai.
     * `specific_fields` mengikuti bentuk kanonik `[{key,label}]` (kontrak
     * parser mobile).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:8', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('mysql_document.letter_types', 'code'),
            ],
            'specific_fields' => ['nullable', 'array'],
            'specific_fields.*.key' => ['required_with:specific_fields', 'string', 'max:40'],
            'specific_fields.*.label' => ['required_with:specific_fields', 'string', 'max:80'],
        ], [
            'code.regex' => 'Kode hanya boleh huruf besar dan angka (maks 8 karakter).',
            'code.unique' => 'Kode jenis surat sudah dipakai.',
        ], [
            'name' => 'nama jenis surat',
            'code' => 'kode',
        ]);

        $code = $validated['code'] ?? $this->suggestCode($validated['name']);

        // Model domain surat tidak memakai $fillable (pola fase 1) — isi
        // atribut secara eksplisit agar mass-assignment guard tetap utuh.
        $type = new LetterType;
        $type->code = $code;
        $type->name = $validated['name'];
        $type->specific_fields = $validated['specific_fields'] ?? null;
        $type->is_active = true;
        $type->save();

        return response()->json([
            'success' => true,
            'data' => $this->payload($type),
        ], 201);
    }

    /**
     * Bentuk payload seragam untuk index & store.
     *
     * @return array{code: string, name: string, specific_fields: mixed}
     */
    private function payload(LetterType $type): array
    {
        return [
            'code' => $type->code,
            'name' => $type->name,
            'specific_fields' => $type->specific_fields,
        ];
    }

    /**
     * Turunkan kode dari nama: inisial kata, lalu potong/padatkan menjadi
     * 2–8 karakter huruf besar-angka. Akhiran angka dipakai bila bentrok.
     */
    private function suggestCode(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', Str::ascii($name)) ?: [];
        $initials = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $initials .= strtoupper($word[0]);
        }

        $base = substr(preg_replace('/[^A-Z0-9]/', '', $initials) ?: '', 0, 8);

        if (strlen($base) < 2) {
            $base = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name)) ?: 'SURAT'), 0, 8);
        }

        $candidate = $base;
        $suffix = 1;

        while (LetterType::query()->where('code', $candidate)->exists()) {
            $tail = (string) $suffix;
            $candidate = substr($base, 0, max(1, 8 - strlen($tail))).$tail;
            $suffix++;

            if ($suffix > 99) {
                $candidate = 'LT'.strtoupper(Str::random(4));
                break;
            }
        }

        return $candidate;
    }
}
