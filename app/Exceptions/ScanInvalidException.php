<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Payload scan surat masuk tidak sah secara SEMANTIK (bukan per-field):
 * scan tidak dikirim sama sekali, atau bentuk `scan` dan `scan_images`
 * dikirim bersamaan (hanya salah satu yang boleh). Kesalahan per-field
 * (mime/ukuran) tetap lewat ValidationException seperti biasa.
 *
 * Dipetakan 422 SCAN_INVALID di bootstrap/app.php (fase 1b).
 */
class ScanInvalidException extends RuntimeException {}
