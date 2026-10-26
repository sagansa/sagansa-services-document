<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Surat perusahaan itu sendiri. PK uuid karena nomor surat dipakai publik
 * (link verifikasi) dan id tidak boleh mudah ditebak.
 *
 * Catatan referensi lintas database: `created_by` dan `company_id` merujuk
 * entitas di luar DB `sagansa_document` — tanpa relasi Eloquent.
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /** PK uuid, bukan auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'metadata' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function letterType(): BelongsTo
    {
        return $this->belongsTo(LetterType::class);
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class);
    }

    /**
     * Penerbitan yang saat ini berlaku; penerbitan lama dinonaktifkan
     * saat diganti (lihat issuances.replaced_by_id).
     */
    public function activeIssuance(): HasOne
    {
        return $this->hasOne(Issuance::class)->where('is_active', true);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    public function signingRequests(): HasMany
    {
        return $this->hasMany(SigningRequest::class);
    }

    /**
     * Cari surat berdasarkan nomor atau perihal (LIKE, case-insensitive
     * tergantung collation DB).
     */
    public function scopeSearch($query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $query->where(function ($q) use ($like) {
            $q->where('number', 'like', $like)
                ->orWhere('subject', 'like', $like);
        });
    }

    /**
     * Filter berdasarkan status surat
     * (draft|menunggu_ttd|terbit|dicabut|dibatalkan).
     */
    public function scopeStatus($query, string $status): void
    {
        $query->where('status', $status);
    }
}
