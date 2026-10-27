<?php

namespace App\Models;

use Database\Factories\IncomingLetterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registrasi surat masuk dari pihak eksternal (fase 1b). PK uuid — pola
 * `documents`: agenda number dipakai publik dan id tidak boleh mudah ditebak.
 *
 * Catatan referensi lintas database: `created_by`, `to_user_id` (via
 * DispositionRecord) dan `company_id` merujuk entitas di luar DB
 * `sagansa_document` — tanpa relasi Eloquent.
 *
 * Mass-assignment DIBATAS ke kolom isian registrasi yang boleh diisi dari
 * request. Kolom integritas & siklus hidup TIDAK fillable dan hanya diatur
 * lewat jalur service: `scan_path`/`scan_sha256`/`scan_size`/`scan_pages`
 * (upload scan), `status` (transisi disposisi), dan
 * `agenda_number`+`agenda_seq`+`id` (counter NumberingService/uuid).
 */
#[Fillable([
    'company_id',
    'year',
    'number_external',
    'letter_date',
    'received_at',
    'sender_party_id',
    'subject',
    'sifat',
    'classification',
    'attachments_note',
    'created_by',
])]
class IncomingLetter extends Model
{
    /** @use HasFactory<IncomingLetterFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /** PK uuid, bukan auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'letter_date' => 'date',
            'received_at' => 'date',
            'year' => 'integer',
            'agenda_seq' => 'integer',
            'scan_size' => 'integer',
            'scan_pages' => 'integer',
        ];
    }

    public function senderParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'sender_party_id');
    }

    public function dispositions(): HasMany
    {
        return $this->hasMany(DispositionRecord::class);
    }

    /**
     * Surat masuk selesai diproses: saat status `selesai` semua field
     * terkunci (409 INCOMING_LOCKED di service).
     */
    public function isFinished(): bool
    {
        return $this->status === 'selesai';
    }
}
