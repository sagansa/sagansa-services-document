<?php

namespace App\Models;

use Database\Factories\DispositionRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan pengarahan (disposisi) per surat masuk, append-only: hanya ada
 * kolom `created_at` (TANPA `updated_at`) — `UPDATED_AT = null` membuat
 * Eloquent hanya mengisi created_at saat insert.
 *
 * Kolom diatur jalur service (IncomingController/DispositionService):
 * model guarded penuh secara default; hanya `status` + `acted_at` yang
 * boleh di-update pada baris yang sudah ada. `to_user_id` dan `created_by`
 * merujuk `users` di DB `sagansa_user` — tanpa FK lintas database.
 */
class DispositionRecord extends Model
{
    /** @use HasFactory<DispositionRecordFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /** PK uuid, bukan auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function incomingLetter(): BelongsTo
    {
        return $this->belongsTo(IncomingLetter::class);
    }
}
