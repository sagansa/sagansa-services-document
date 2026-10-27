<?php

namespace App\Http\Resources;

use App\Models\DispositionRecord;
use App\Models\IncomingLetter;
use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi IncomingLetter untuk API /api/v1/incoming-letters.
 *
 * Bentuk list (index): kolom registrasi + metadata integritas scan
 * (sha256/size/pages — isi scan hanya via GET /{id}/scan) +
 * sender_party saat di-eager controller. Surat rahasia tetap tampil
 * lengkap di list/detail fase ini (internal; konsisten fase 1).
 *
 * Bentuk detail (show) menambah relasi HANYA saat sudah di-eager-load
 * controller (whenLoaded): dispositions urut created_at dengan label
 * penerima lintas-DB "Pengguna #id" (users di DB sagansa_user tidak
 * dibaca dari sini). Embed thread + links_count bersifat OPSIONAL —
 * diisi controller show lewat property publik (list membiarkannya null
 * sehingga kunci tidak muncul).
 *
 * @property IncomingLetter $resource
 */
class IncomingLetterResource extends JsonResource
{
    /** Embed hasil ThreadBuilder::build (hanya bentuk detail; null = list). */
    public ?array $thread = null;

    /** Jumlah edge letter_links kedua arah (hanya bentuk detail; null = list). */
    public ?int $linksCount = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agenda_number' => $this->agenda_number,
            'number_external' => $this->number_external,
            'letter_date' => $this->letter_date?->format('Y-m-d'),
            'received_at' => $this->received_at?->format('Y-m-d'),
            'year' => $this->year,
            'subject' => $this->subject,
            'sifat' => $this->sifat,
            'classification' => $this->classification,
            'status' => $this->status,
            'attachments_note' => $this->attachments_note,
            'company_id' => $this->company_id,

            // Metadata integritas scan — path internal TIDAK diekspos.
            'scan' => [
                'sha256' => $this->scan_sha256,
                'size' => $this->scan_size,
                'pages' => $this->scan_pages,
            ],

            'sender_party' => $this->whenLoaded('senderParty', fn () => $this->senderParty instanceof Party ? [
                'id' => $this->senderParty->id,
                'name' => $this->senderParty->name,
            ] : null),

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // ——— bentuk detail (whenLoaded) ———
            'dispositions' => $this->whenLoaded('dispositions', fn () => $this->dispositions
                ->map(fn (DispositionRecord $disposition) => [
                    'id' => $disposition->id,
                    'to_user_id' => $disposition->to_user_id,
                    'to_user_label' => "Pengguna #{$disposition->to_user_id}",
                    'instruction' => $disposition->instruction,
                    'status' => $disposition->status,
                    'acted_at' => $disposition->acted_at?->toIso8601String(),
                    'created_at' => $disposition->created_at?->toIso8601String(),
                ])
                ->all()),

            'thread' => $this->when($this->thread !== null, fn () => $this->thread),
            'links_count' => $this->when($this->linksCount !== null, fn () => $this->linksCount),
        ];
    }
}
