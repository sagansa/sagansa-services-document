<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;

/**
 * Pencatat jejak aktivitas surat ke tabel audit_logs (append-only:
 * hanya INSERT, tidak pernah UPDATE/DELETE).
 *
 * Kegagalan insert dibiarkan melempar exception (fail-loud): jejak
 * audit adalah sumber kebenaran — operasi yang tidak bisa dicatat
 * sebaiknya gagal bersama pencatatannya, bukan berjalan tanpa jejak.
 */
class AuditLogger
{
    /** Surat dibuat (draft awal). */
    public const EVENT_CREATED = 'created';

    /** Isi surat diubah. */
    public const EVENT_UPDATED = 'updated';

    /** Permintaan tanda tangan dikirim ke penandatangan. */
    public const EVENT_SIGN_REQUESTED = 'sign_requested';

    /** Penandatangan menyetujui (menandatangani). */
    public const EVENT_SIGNED = 'signed';

    /** Penandatangan menolak. */
    public const EVENT_SIGN_REJECTED = 'sign_rejected';

    /** Surat mencapai status pra-final (semua tanda tangan lengkap). */
    public const EVENT_PRE_FINAL = 'pre_final';

    /** Surat difinalisasi (penerbitan berkas final dibuat). */
    public const EVENT_FINALIZED = 'finalized';

    /** Surat diterbitkan ulang (penerbitan pengganti). */
    public const EVENT_REISSUED = 'reissued';

    /** Surat dicabut. */
    public const EVENT_REVOKED = 'revoked';

    /** Surat dibatalkan (void). */
    public const EVENT_VOIDED = 'voided';

    /** Surat diverifikasi via halaman publik. */
    public const EVENT_VERIFIED_PUBLIC = 'verified_public';

    /** Surat masuk terdaftar (scan + agenda dicadangkan). */
    public const EVENT_INCOMING_CREATED = 'incoming_created';

    /** Data surat masuk diubah. */
    public const EVENT_INCOMING_UPDATED = 'incoming_updated';

    /** Status surat masuk naik tahap (terdaftar→diproses→selesai). */
    public const EVENT_INCOMING_STATUS = 'incoming_status';

    /** Disposisi baru ditambahkan ke surat masuk. */
    public const EVENT_DISPOSITION_ADDED = 'disposition_added';

    /** Disposisi diselesaikan oleh penerima. */
    public const EVENT_DISPOSITION_COMPLETED = 'disposition_completed';

    /** Surat ditautkan ke runutan (letter_links, fase 1b). */
    public const EVENT_LETTER_LINKED = 'letter_linked';

    /**
     * Catat satu baris jejak audit untuk sebuah surat.
     *
     * @param  Document|string  $doc  Surat (model) atau uuid-nya langsung —
     *                                string dipakai agar event tetap tercatat
     *                                tanpa perlu memuat ulang model.
     * @param  string  $event  Salah satu konstanta EVENT_*.
     * @param  int|null  $actorId  Id user pelaku (users di DB sagansa_user),
     *                             null untuk aksi sistem/publik.
     * @param  array<string, mixed>  $payload  Rincian tambahan, disimpan
     *                                         sebagai JSON.
     */
    public function log(Document|string $doc, string $event, ?int $actorId, array $payload = [], ?string $ip = null, ?string $ua = null): void
    {
        $documentId = $doc instanceof Document ? (string) $doc->getKey() : $doc;

        // Assignment per-atribut (bukan create() massal): model AuditLog
        // guarded tanpa $fillable — dan model memang tidak boleh diubah
        // demi keterbukaan API-nya. UPDATED_AT=null membuat save() hanya
        // mengisi created_at.
        $log = new AuditLog;
        $log->document_id = $documentId;
        $log->actor_user_id = $actorId;
        $log->event = $event;
        $log->payload = $payload;
        $log->ip = $ip;
        $log->user_agent = $ua;
        $log->save();
    }
}
