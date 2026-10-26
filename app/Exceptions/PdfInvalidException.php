<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Berkas yang diunggah bukan PDF valid — magic byte %PDF tidak ditemukan
 * di awal byte stream (dicek via str_starts_with sebelum dipersist).
 */
class PdfInvalidException extends RuntimeException {}
