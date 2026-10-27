<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Surat masuk terkunci: status hanya boleh MAJU satu arah
 * (terdaftar→diproses→selesai — mundur/berulang ditolak), dan setelah
 * `selesai` SEMUA field surat beku (tidak bisa diubah lagi).
 *
 * Dilempar DALAM transaksi setelah baris dikunci (SELECT ... FOR
 * UPDATE) sehingga dua mutasi paralel surat yang sama selalu tepat
 * satu yang berhasil — pola DocumentLockedException fase 1.
 */
class IncomingLockedException extends RuntimeException {}
