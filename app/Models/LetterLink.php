<?php

namespace App\Models;

use Database\Factories\LetterLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Edge runutan antar surat lintas jenis (fase 1b): parent/child masing-
 * masing polymorphic manual (`document` | `incoming_letter`).
 *
 * TANPA relasi polymorphic Eloquent dan TANPA FK ke node tabel: keabsahan
 * target (ada/tidak, duplikat, self, cycle) dijaga di service layer —
 * DB hanya menjaga keunikan edge (`letter_links_edge_unique`). Append-only:
 * hanya kolom `created_at` (TANPA `updated_at`).
 *
 * `created_by` merujuk `users` di DB `sagansa_user` — tanpa FK lintas
 * database.
 */
class LetterLink extends Model
{
    /** @use HasFactory<LetterLinkFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /** PK uuid, bukan auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    /**
     * Kunci node parent: [type, id] untuk keperluan guard service
     * (self/cycle/duplikat) tanpa relasi Eloquent.
     *
     * @return array{0: string, 1: string}
     */
    public function parentKey(): array
    {
        return [$this->parent_type, (string) $this->parent_id];
    }

    /**
     * Kunci node child: [type, id].
     *
     * @return array{0: string, 1: string}
     */
    public function childKey(): array
    {
        return [$this->child_type, (string) $this->child_id];
    }
}
