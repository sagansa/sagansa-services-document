<?php

namespace App\Models;

use Database\Factories\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lampiran per surat: path di storage service + sha256 untuk integritas.
 */
class DocumentAttachment extends Model
{
    /** @use HasFactory<DocumentAttachmentFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    /** PK uuid, bukan auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
