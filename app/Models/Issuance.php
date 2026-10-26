<?php

namespace App\Models;

use Database\Factories\IssuanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil penerbitan surat (berkas final + hash). Ketika surat diterbitkan
 * ulang, penerbitan lama dinonaktifkan dan menunjuk penerbitan pengganti
 * lewat `replaced_by_id`.
 */
class Issuance extends Model
{
    /** @use HasFactory<IssuanceFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /** PK uuid, bukan auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Penerbitan pengganti yang mengaktifkan kembali/mengganti versi ini
     * (self-relation; null bila masih versi terakhir).
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
