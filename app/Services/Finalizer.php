<?php

namespace App\Services;

use App\Exceptions\DocumentLockedException;
use App\Exceptions\PdfInvalidException;
use App\Exceptions\SigningIncompleteException;
use App\Models\Document;
use App\Models\Issuance;
use App\Models\SigningRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finalizer — jantung integritas penerbitan surat.
 *
 * Empat jalur publik bermuara ke satu method privat persist():
 *
 *   finalizeFromContent  metode 'a' — bytes dari PdfRenderer (render HTML)
 *   finalizeFromStamps   metode 'b' — bytes dari PdfStamper (overlay QR)
 *   finalizeFromUpload   metode 'c'|'d' — bytes unggahan (validasi %PDF)
 *   reissue              metode a-d — status WAJIB 'terbit', issuance
 *                       aktif lama dinonaktifkan + replaced_by_id
 *
 * persist menjalankan SEMUA mutasi dalam SATU transaksi pada koneksi
 * mysql_document dengan baris surat dikunci lewat
 * SELECT ... FOR UPDATE (re-read di dalam lock). Karena setiap penulis
 * issuances selalu melewati lock baris suratnya, dua finalize paralel
 * surat yang sama diserialisasi: pemenang pertama mengubah status ke
 * 'terbit', yang keduanya masuk setelahnya melihat status baru dan
 * ditolak DocumentLockedException — mustahil ada dua issuance aktif.
 *
 * Urutan tulis-berkas vs transaksi: berkas ditulis DI DALAM transaksi
 * (setelah insert issuance, sebelum status + audit). Storage berada di
 * luar DB sehingga tidak bisa ikut rollback — trade-off-nya:
 *   - tulis gagal  → exception → transaksi rollback, DB konsisten
 *     (tidak ada baris issuance tanpa berkas);
 *   - kegagalan apa pun SETELAH tulis sukses (status/audit/commit) →
 *     transaksi rollback DAN berkas yatim dihapus best-effort di catch
 *     (delete lalu rethrow exception asli). Cleanup-nya bebas race:
 *     path memuat uuid issuance yang dibuat fresh dalam transaksi ini,
 *     jadi berkas itu tak akan pernah tereferensi baris mana pun.
 * Sisa risiko yang diterima: delete gagal (disk bermasalah) — berkas
 * yatim hanya sampah yang bisa digarap GC, sedangkan baris DB tanpa
 * berkas adalah surat terbit yang tidak bisa diunduh/dibuktikan.
 */
class Finalizer
{
    /**
     * Label penerbitan per metode (kolom issuances.method_label).
     *
     * @var array<string, string>
     */
    private const METHOD_LABELS = [
        'a' => 'elektronik',
        'b' => 'elektronik',
        'c' => 'ttd_elektronik_resmi',
        'd' => 'manual',
    ];

    public function __construct(
        private readonly PdfRenderer $renderer = new PdfRenderer,
        private readonly PdfStamper $stamper = new PdfStamper,
        private readonly NumberingService $numbering = new NumberingService,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * Mode A — render isi surat menjadi PDF lalu terbitkan.
     *
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     */
    public function finalizeFromContent(Document $doc, ?int $actorId = null): Issuance
    {
        return $this->persist(
            $doc, 'a', $this->renderer->render($doc), actorId: $actorId,
        );
    }

    /**
     * Mode B — tempel overlay (QR tanda tangan) ke PDF pra-final lalu terbitkan.
     *
     * @param  list<array{image: string, page: int, x_mm: float, y_mm: float, width_mm: float}>  $overlays
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     */
    public function finalizeFromStamps(Document $doc, string $pdfBytes, array $overlays, ?int $actorId = null): Issuance
    {
        return $this->persist(
            $doc, 'b', $this->stamper->stamp($pdfBytes, $overlays), actorId: $actorId,
        );
    }

    /**
     * Mode C/D — terbitkan PDF hasil unggahan pihak luar.
     *
     * Metode 'c' (ttd_elektronik_resmi) tidak boleh dipakai bersama
     * koordinat overlay — controller API yang menjaga awal itu
     * (AlreadySignedException, Task 16).
     *
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     *
     * @throws InvalidArgumentException metode bukan 'c'|'d'
     * @throws PdfInvalidException bytes tidak diawali magic %PDF
     */
    public function finalizeFromUpload(Document $doc, string $pdfBytes, string $method, ?int $actorId = null): Issuance
    {
        if (! in_array($method, ['c', 'd'], true)) {
            throw new InvalidArgumentException(
                "Metode unggah harus 'c' atau 'd', didapat: '{$method}'.",
            );
        }

        if (! str_starts_with($pdfBytes, '%PDF')) {
            throw new PdfInvalidException('Berkas unggahan bukan PDF (magic byte %PDF tidak ada).');
        }

        return $this->persist($doc, $method, $pdfBytes, actorId: $actorId);
    }

    /**
     * Terbitkan ulang surat yang SUDAH terbit (koreksi/perbaikan):
     * issuance aktif lama dinonaktifkan dan menunjuk pengganti lewat
     * replaced_by_id; note alasan wajib tercatat di jejak audit.
     *
     * @param  callable(): string  $bytesProducer  pabrik bytes PDF versi baru
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     *
     * @throws InvalidArgumentException metode tidak dikenal / note kosong
     * @throws DocumentLockedException status surat bukan 'terbit'
     */
    public function reissue(Document $doc, string $method, callable $bytesProducer, string $note, ?int $actorId = null): Issuance
    {
        if (! isset(self::METHOD_LABELS[$method])) {
            throw new InvalidArgumentException(
                "Metode harus salah satu dari '".implode("','", array_keys(self::METHOD_LABELS))."', didapat: '{$method}'.",
            );
        }

        if (trim($note) === '') {
            throw new InvalidArgumentException('Reissue wajib menyertakan note alasan (tidak boleh kosong).');
        }

        return $this->persist(
            $doc, $method, $bytesProducer(), reissue: true, note: $note, actorId: $actorId,
        );
    }

    /**
     * Satu-satunya jalur penulisan issuance — dipanggil dalam transaksi
     * dengan lock baris surat. Urutan: guard → reserve nomor → insert
     * issuance baru → nonaktifkan issuance aktif lama → tulis berkas →
     * status terbit → audit. Bila transaksi gagal SETELAH berkas
     * tertulis, berkas dibersihkan lalu exception asli dilempar ulang.
     *
     * @param  bool  $reissue  true = jalur penerbitan ulang (status wajib 'terbit')
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     *
     * @throws DocumentLockedException status tidak sesuai jalur
     * @throws SigningIncompleteException ada signing_request 'diminta' menggantung
     * @throws RuntimeException berkas gagal ditulis ke disk documents
     */
    private function persist(Document $doc, string $method, string $bytes, bool $reissue = false, ?string $note = null, ?int $actorId = null): Issuance
    {
        $actorId ??= auth()->id();

        // Path berkas yang mungkin tertulis selama transaksi — dipantau
        // (by-ref) agar catch di bawah tahu apa yang harus dibersihkan.
        $writtenPath = null;

        try {
            return DB::connection($doc->getConnectionName())->transaction(function () use ($doc, $method, $bytes, $reissue, $note, $actorId, &$writtenPath): Issuance {
                // Re-read DALAM lock: keputusan guard dibuat atas kondisi
                // terkini, bukan atas snapshot model yang dipanggil.
                /** @var Document $locked */
                $locked = Document::query()->whereKey($doc->id)->lockForUpdate()->firstOrFail();

                $allowedStatus = $reissue ? ['terbit'] : ['draft', 'menunggu_ttd'];
                if (! in_array($locked->status, $allowedStatus, true)) {
                    throw new DocumentLockedException(
                        "Status '{$locked->status}' tidak mengizinkan ".($reissue ? 'reissue' : 'finalize')
                        ." (diharapkan '".implode("'|'", $allowedStatus)."').",
                    );
                }

                if (SigningRequest::query()
                    ->where('document_id', $locked->id)
                    ->where('status', 'diminta')
                    ->exists()) {
                    throw new SigningIncompleteException('Masih ada signing_request berstatus diminta yang belum diputuskan.');
                }

                // Nomor surat dicadangkan saat finalize bila surat lahir tanpa
                // nomor. Kolom number NOT NULL di skema, jadi pra-nomor
                // direpresentasikan string kosong (null tetap ditangani bila
                // konstrain skema dilonggarkan nanti).
                if ($locked->number === null || $locked->number === '') {
                    ['seq' => $seq, 'number' => $number] = $this->numbering->reserve(
                        $locked->letterType()->firstOrFail()->code,
                        $locked->unit,
                        $locked->year,
                    );
                    $locked->number = $number;
                    $locked->seq = $seq;
                }

                // Hash lampiran: sha256 dari json daftar sha256 lampiran
                // terurut — null bila surat tak berlampiran.
                $attachmentHashes = $locked->attachments()->orderBy('sha256')->pluck('sha256')->values()->all();
                $attachmentsSha256 = $attachmentHashes === []
                    ? null
                    : hash('sha256', json_encode($attachmentHashes));

                // UUID dibuat DI DALAM transaksi sebelum berkas ditulis —
                // path berkas diturunkan darinya sehingga baris dan berkas
                // selalu sepakat.
                $issuanceId = (string) Str::uuid();
                $filePath = sprintf('%d/%s/issuance-%s.pdf', $locked->year, $locked->id, $issuanceId);

                $issuance = new Issuance;
                $issuance->id = $issuanceId;
                $issuance->document_id = $locked->id;
                $issuance->method = $method;
                $issuance->method_label = self::METHOD_LABELS[$method];
                $issuance->file_path = $filePath;
                $issuance->file_sha256 = hash('sha256', $bytes);
                $issuance->attachments_sha256 = $attachmentsSha256;
                $issuance->file_size = strlen($bytes);
                $issuance->note = $note;
                $issuance->is_active = true;
                $issuance->finalized_by = $actorId;
                $issuance->finalized_at = now();
                $issuance->save();

                // Nonaktifkan issuance aktif lama (finalize pertama biasanya
                // tak ada; reissue pasti ada satu). Baris baru sudah ter-insert
                // lebih dulu agar self-FK replaced_by_id sah.
                Issuance::query()
                    ->where('document_id', $locked->id)
                    ->where('is_active', true)
                    ->whereKeyNot($issuanceId)
                    ->update([
                        'is_active' => false,
                        'replaced_by_id' => $issuanceId,
                    ]);

                // Tulis berkas DI DALAM transaksi: gagal tulis → exception →
                // rollback seluruh mutasi DB di atas (lihat catatan kelas).
                if (Storage::disk('documents')->put($filePath, $bytes) === false) {
                    throw new RuntimeException("Gagal menulis berkas issuance ke disk documents: {$filePath}.");
                }

                // Tandai berkas sudah ada di disk — mulai sini kegagalan apa
                // pun sisa transaksi (status/audit/commit) harus dibersihkan
                // oleh catch di bawah.
                $writtenPath = $filePath;

                $locked->status = 'terbit';
                $locked->save();

                $this->audit->log(
                    $locked,
                    $reissue ? AuditLogger::EVENT_REISSUED : AuditLogger::EVENT_FINALIZED,
                    $actorId,
                    array_filter([
                        'method' => $method,
                        'method_label' => self::METHOD_LABELS[$method],
                        'issuance_id' => $issuanceId,
                        'file_sha256' => $issuance->file_sha256,
                        'note' => $note,
                    ], fn ($value) => $value !== null),
                );

                return Issuance::query()->findOrFail($issuanceId);
            });
        } catch (\Throwable $e) {
            // Berkas yatim: transaksi sudah rollback (DB konsisten), tapi
            // berkas yang tertulis sebelum kegagalan tidak ikut rollback.
            // Dihapus best-effort lalu exception ASLI dilempar ulang —
            // bebas race karena path memuat uuid issuance yang gagal
            // ter-commit dan tak akan pernah dipakai ulang.
            if ($writtenPath !== null) {
                Storage::disk('documents')->delete($writtenPath);
            }

            throw $e;
        }
    }
}
