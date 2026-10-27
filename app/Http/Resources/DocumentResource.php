<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi Document untuk API /api/v1/documents.
 *
 * Bentuk dasar (list & create): kolom isi surat + letter_type ringkas.
 * Bentuk detail (GET /documents/{id}) menambah relasi HANYA saat sudah
 * di-eager-load controller (whenLoaded) — endpoint list tetap ringan.
 *
 * @property Document $resource
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'classification' => $this->classification,
            'subject' => $this->subject,
            'date' => $this->date?->format('Y-m-d'),
            'letter_type' => $this->whenLoaded('letterType', fn () => [
                'code' => $this->letterType->code,
                'name' => $this->letterType->name,
            ]),
            'metadata' => $this->metadata,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Isi surat HANYA ikut bentuk detail (marker: relasi issuances
            // di-eager-load endpoint show) dan hanya selagi masih editable —
            // editor mobile (wizard/jawab gap Task 20) membutuhkannya untuk
            // membuka kembali draft/menunggu_ttd. Surat terbit/dicabut/
            // dibatalkan menampilkan isi lewat PDF terbitan (endpoint verify),
            // jadi body_html TIDAK disertakan supaya payload tetap ramping.
            'body_html' => $this->when(
                $this->resource->relationLoaded('issuances')
                    && in_array($this->resource->status, ['draft', 'menunggu_ttd'], true),
                fn () => $this->body_html,
            ),

            // Unit pembuat nomor — ikut bentuk detail saja (kounter nomor
            // memuat {jenis}/{unit}/{seq}/...); detail & wizard edit mobile
            // menampilkannya, list tetap ramping.
            'unit' => $this->when(
                $this->resource->relationLoaded('issuances'),
                fn () => $this->unit,
            ),

            // Induk runutan (Task 6 fase 1b) — ikut bentuk detail: chip
            // IDENTITAS saja (type/id/nomor|agenda/relasi), isi surat induk
            // tidak dibocorkan; null bila surat ini bukan child edge mana
            // pun. Edge terbaru dipakai (edge lama tidak pernah dihapus —
            // append-only; praktiknya satu child paling banyak satu induk).
            'in_reply_to' => $this->when(
                $this->resource->relationLoaded('issuances'),
                fn () => $this->inReplyToIdentity(),
            ),

            // ——— bentuk detail (whenLoaded) ———
            'active_issuance' => $this->whenLoaded('activeIssuance', fn () => $this->activeIssuance !== null ? [
                'id' => $this->activeIssuance->id,
                'method' => $this->activeIssuance->method,
                'method_label' => $this->activeIssuance->method_label,
                'file_sha256' => $this->activeIssuance->file_sha256,
                'finalized_at' => $this->activeIssuance->finalized_at?->toIso8601String(),
            ] : null),
            'issuances' => $this->whenLoaded('issuances', fn () => $this->issuances->map(fn ($issuance) => [
                'id' => $issuance->id,
                'method' => $issuance->method,
                'method_label' => $issuance->method_label,
                'file_sha256' => $issuance->file_sha256,
                'note' => $issuance->note,
                'is_active' => $issuance->is_active,
                'replaced_by_id' => $issuance->replaced_by_id,
                'finalized_at' => $issuance->finalized_at?->toIso8601String(),
            ])->all()),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'label' => $attachment->label,
                'sort' => $attachment->sort,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'sha256' => $attachment->sha256,
            ])->all()),
            'signing_requests' => $this->whenLoaded('signingRequests', fn () => $this->signingRequests->map(fn ($signingRequest) => [
                'id' => $signingRequest->id,
                'status' => $signingRequest->status,
                'requested_at' => $signingRequest->requested_at?->toIso8601String(),
                'acted_at' => $signingRequest->acted_at?->toIso8601String(),
                'signer' => $signingRequest->signer !== null ? [
                    'display_name' => $signingRequest->signer->display_name,
                    'role' => $signingRequest->signer->role,
                ] : null,
            ])->all()),
        ];
    }

    /**
     * Identitas ringkas induk runutan: edge TERBARU yang menjadikan surat
     * ini child (kunci child_type='document' — resource ini untuk Document),
     * lalu nomor/agenda baris induknya. Hanya identitas — isi induk tidak
     * ikut (chip "membalas surat X" cukup tahu siapa X).
     *
     * @return array{type: string, id: string, number_or_agenda: string|null, relation: string}|null
     */
    private function inReplyToIdentity(): ?array
    {
        /** @var LetterLink|null $edge */
        $edge = LetterLink::query()
            ->where('child_type', 'document')
            ->where('child_id', $this->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($edge === null) {
            return null;
        }

        [$parentType, $parentId] = $edge->parentKey();

        // value() cukup satu kolom; null juga bila baris induk sudah
        // tidak ada (edge append-only bertahan — tetap tampilkan identitas).
        $number = $parentType === 'document'
            ? Document::query()->whereKey($parentId)->value('number')
            : IncomingLetter::query()->whereKey($parentId)->value('agenda_number');

        return [
            'type' => $parentType,
            'id' => $parentId,
            'number_or_agenda' => $number,
            'relation' => $edge->relation,
        ];
    }
}
