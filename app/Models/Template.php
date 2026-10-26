<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template isi surat (HTML) per jenis surat; satu default per jenis.
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function letterType(): BelongsTo
    {
        return $this->belongsTo(LetterType::class);
    }
}
