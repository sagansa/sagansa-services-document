<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\LinkTargetNotFoundException;
use App\Http\Controllers\Controller;
use App\Services\ThreadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * API /api/v1/letters/thread (fase 1b) — rekonstruksi runutan surat.
 *
 * ThreadBuilder sudah melempar exception domain untuk dua kasus tolak:
 * InvalidArgumentException (tipe node tak dikenal) dan
 * LinkTargetNotFoundException (baris node tidak ada). Keduanya dipetakan
 * ke 404 NETRAL `NOT_FOUND` di sini — bukan 422 — karena keduanya adalah
 * "node yang diminta tidak ada", konsisten dengan endpoint verify fase 1:
 * penyebab (salah tipe vs id tidak ada) tidak dibocorkan ke klien.
 */
class LetterThreadController extends Controller
{
    public function __construct(private readonly ThreadBuilder $threads = new ThreadBuilder) {}

    /**
     * GET /letters/thread/{type}/{id} — thread dari node mana pun dalam
     * runutan (root dihitung ThreadBuilder; urutan flat chronological).
     *
     * @param  string  $type  'document' | 'incoming_letter'
     * @param  string  $id  uuid node awal (boleh node mana pun dalam thread)
     */
    public function show(Request $request, string $type, string $id): JsonResponse
    {
        try {
            $thread = $this->threads->build($type, $id);
        } catch (InvalidArgumentException|LinkTargetNotFoundException) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'data' => $thread,
        ]);
    }
}
