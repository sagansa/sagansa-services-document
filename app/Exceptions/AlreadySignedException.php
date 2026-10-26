<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Operasi bertabrakan dengan dokumen yang sudah ditandatangani —
 * mis. jalur unggah metode 'c' (ttd_elektronik_resmi) dikirim koordinat
 * overlay (seharusnya lewat jalur stempel 'b'); controller API yang
 * menjaga awal ini (Task 16).
 */
class AlreadySignedException extends RuntimeException {}
