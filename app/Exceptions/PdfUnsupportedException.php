<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * PDF input tidak dapat diproses PdfStamper: dokumen memakai fitur yang
 * hanya didukung parser komersial FPDI (cross-reference stream / object
 * stream, PDF 1.5+) dan qpdf tidak tersedia untuk menormalisasi dokumen
 * ke classic xref table.
 */
class PdfUnsupportedException extends RuntimeException {}
