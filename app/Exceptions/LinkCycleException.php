<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Penambahan edge letter_links akan membentuk siklus runutan: node child
 * sudah merupakan leluhur node parent (ditolak oleh LetterLinkService
 * sebelum baris ditulis).
 */
class LinkCycleException extends RuntimeException {}
