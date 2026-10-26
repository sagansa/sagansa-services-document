<?php

namespace App\Models;

use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pihak eksternal (penerima/kuasa) yang bisa dipakai ulang antar surat.
 * `created_from_document_id` menandai pihak yang lahir dari isian bebas
 * saat membuat surat — tanpa FK ke documents (lihat migration master).
 */
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';
}
