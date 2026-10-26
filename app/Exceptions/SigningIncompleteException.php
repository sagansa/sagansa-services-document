<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Masih ada signing_requests berstatus 'diminta' yang menggantung —
 * surat belum boleh difinalisasi/diterbitkan ulang sampai semua
 * penandatangan menyetujui atau permintaannya ditolak/dibatalkan.
 */
class SigningIncompleteException extends RuntimeException {}
