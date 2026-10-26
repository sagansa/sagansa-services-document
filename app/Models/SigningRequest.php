<?php

namespace App\Models;

use Database\Factories\SigningRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan persetujuan penandatangan per surat.
 *
 * Tabel TANPA created_at/updated_at — kolom waktu domain
 * (`requested_at`/`acted_at`) diisi eksplisit oleh aplikasi.
 */
class SigningRequest extends Model
{
    /** @use HasFactory<SigningRequestFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /** PK uuid, bukan auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'acted_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class);
    }
}
