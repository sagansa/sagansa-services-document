<?php

namespace App\Services;

use App\Jobs\RecordVerificationHitJob;
use App\Models\Document;

/**
 * Pencatat hit verifikasi publik per surat. Endpoint verifikasi publik
 * harus cepat dan tidak boleh terblokir oleh tulis DB, sehingga kelas
 * ini TIDAK menulis apa pun — ia hanya dispatch RecordVerificationHitJob;
 * job-lah yang meng-insert baris verification_hits (pada queue sync di
 * testing, insert terjadi sinkron).
 */
class VerificationHitRecorder
{
    /**
     * @param  Document|string  $doc  Surat (model) atau uuid-nya langsung —
     *                                uuid diteruskan sebagai primitif agar
     *                                job tidak perlu menyerialisasi model.
     */
    public function record(Document|string $doc, ?string $ip = null): void
    {
        $documentId = $doc instanceof Document ? (string) $doc->getKey() : $doc;

        dispatch(new RecordVerificationHitJob($documentId, $ip));
    }
}
