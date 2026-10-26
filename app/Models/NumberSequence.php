<?php

namespace App\Models;

use Database\Factories\NumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Counter nomor surat per (jenis, unit, tahun) untuk atomic increment —
 * baris counter murni, TANPA timestamps.
 */
class NumberSequence extends Model
{
    /** @use HasFactory<NumberSequenceFactory> */
    use HasFactory;

    protected $connection = 'mysql_document';

    public $timestamps = false;
}
