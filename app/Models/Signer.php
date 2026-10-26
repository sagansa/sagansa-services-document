<?php

namespace App\Models;

use Database\Factories\SignerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penandatangan internal. `user_id` merujuk `users` di DB `sagansa_user`
 * (pola referensi lintas database — tanpa FK/relasi Eloquent).
 */
class Signer extends Model
{
    /** @use HasFactory<SignerFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function signingRequests(): HasMany
    {
        return $this->hasMany(SigningRequest::class);
    }
}
