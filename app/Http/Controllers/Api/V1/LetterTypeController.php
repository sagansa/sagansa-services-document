<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LetterType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API /api/v1/letter-types (Task 14) — daftar jenis surat AKTIF urut kode,
 * dipakai wizard memilih jenis surat + definisi field khususnya.
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
            'data' => $types
                ->map(fn (LetterType $type): array => [
                    'code' => $type->code,
                    'name' => $type->name,
                    'specific_fields' => $type->specific_fields,
                ])
                ->all(),
        ]);
    }
}
