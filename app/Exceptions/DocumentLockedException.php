<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Status surat tidak mengizinkan operasi ini: finalize hanya untuk
 * draft|menunggu_ttd (dan justru menolak terbit), reissue hanya untuk
 * terbit. Dilempar DALAM transaksi setelah baris surat dikunci
 * (SELECT ... FOR UPDATE) sehingga dua finalize paralel surat yang sama
 * selalu tepat satu yang berhasil.
 */
class DocumentLockedException extends RuntimeException {}
