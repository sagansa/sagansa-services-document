<?php

namespace App\Jobs;

use App\Models\VerificationHit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Insert satu baris hit verifikasi publik (append-only). Dijalankan
 * lewat queue agar endpoint verifikasi publik tidak menulis DB secara
 * sinkron; pada testing queue connection `sync` mengeksekusi job
 * seketika di proses yang sama.
 */
class RecordVerificationHitJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $documentId  Uuid surat yang diverifikasi (primitif,
     *                              bukan model — hindari serialisasi model
     *                              dan binding ke data lama).
     */
    public function __construct(
        public readonly string $documentId,
        public readonly ?string $ip = null,
    ) {}

    public function handle(): void
    {
        // Assignment per-atribut (bukan create() massal): model
        // VerificationHit guarded tanpa $fillable.
        $hit = new VerificationHit;
        $hit->document_id = $this->documentId;
        $hit->ip = $this->ip;
        $hit->save();
    }
}
