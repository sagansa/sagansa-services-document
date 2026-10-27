<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Node surat yang ditaut tidak ditemukan pada tabel tipenya — letter_links
 * tidak memakai FK ke node (polymorphic manual), sehingga keabsahan target
 * dijaga di service layer (LetterLinkService::link / ThreadBuilder::build).
 *
 * Controller fase 1b memetakan exception ini ke 404/422 sesuai konteks.
 */
class LinkTargetNotFoundException extends RuntimeException {}
