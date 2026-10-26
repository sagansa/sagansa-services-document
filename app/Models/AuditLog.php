<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Jejak aktivitas surat, append-only: hanya ada kolom `created_at`
 * (TANPA `updated_at`) — `UPDATED_AT = null` membuat Eloquent hanya
 * mengisi created_at saat insert.
 *
 * `document_id` TANPA FK: jejak audit dipertahankan meski baris surat
 * dihapus. `actor_user_id` merujuk `users` di DB `sagansa_user`.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
