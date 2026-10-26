<?php

namespace App\Models;

use Database\Factories\VerificationHitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Hit counter verifikasi publik per surat, append-only: hanya ada kolom
 * `created_at` (TANPA `updated_at`). `document_id` TANPA FK.
 */
class VerificationHit extends Model
{
    /** @use HasFactory<VerificationHitFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    const UPDATED_AT = null;
}
