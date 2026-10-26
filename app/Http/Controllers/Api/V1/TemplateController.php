<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API /api/v1/templates (Task 14) — template isi surat AKTIF untuk satu
 * jenis surat (filter letter_type_code WAJIB), template default duluan.
 * body_html ikut dibocorkan karena wizard menampilkan isinya untuk
 * dipratinjau/diedit sebelum surat dibuat.
 */
class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['letter_type_code' => ['required', 'string', 'max:8']],
            ['letter_type_code.required' => 'Kode jenis surat wajib diisi.'],
            ['letter_type_code' => 'kode jenis surat'],
        );

        $templates = Template::query()
            ->whereRelation('letterType', 'code', $validated['letter_type_code'])
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $templates
                ->map(fn (Template $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'letter_type_code' => $template->letterType->code,
                    'body_html' => $template->body_html,
                    'placeholders' => $template->placeholders,
                    'is_default' => $template->is_default,
                ])
                ->all(),
        ]);
    }
}
