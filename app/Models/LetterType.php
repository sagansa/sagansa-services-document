<?php

namespace App\Models;

use Database\Factories\LetterTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis surat (ST/SK/SPR/... KUA) + definisi field khusus per jenis.
 */
class LetterType extends Model
{
    /** @use HasFactory<LetterTypeFactory> */
    use HasFactory;

    /** Semua model domain surat hidup di DB `sagansa_document`. */
    protected $connection = 'mysql_document';

    protected function casts(): array
    {
        return [
            'specific_fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Template isi surat (HTML) untuk jenis surat ini.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }
}
