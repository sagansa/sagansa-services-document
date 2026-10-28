<?php

namespace App\Services;

use App\Exceptions\IncomingLockedException;
use App\Exceptions\PdfInvalidException;
use App\Models\IncomingLetter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Registrasi & siklus hidup surat masuk (fase 1b).
 *
 * register() menjalankan SEMUA mutasi dalam SATU transaksi pada koneksi
 * mysql_document: cadangkan nomor agenda (NumberingService::reserveAgenda
 * — baris counter number_sequences 'SM'/'IN' dikunci, savepoint di dalam
 * transaksi register), tulis scan ke disk documents, simpan baris surat,
 * lalu jejak audit. Kolom integritas & siklus hidup (id, agenda_number,
 * agenda_seq, status, scan_*) TIDAK mass-assign — diatur per-atribut di
 * jalur service ini saja (guard fillable IncomingLetter fase 1b).
 *
 * Urutan tulis-berkas vs transaksi mengikuti pola Finalizer: scan
 * ditulis DI DALAM transaksi setelah reserve agenda, sebelum save +
 * audit. Kegagalan apa pun SETELAH tulis sukses → transaksi rollback
 * DAN scan yatim dihapus best-effort di catch (bebas race: path memuat
 * uuid yang dibuat fresh dalam transaksi ini dan tak akan pernah
 * dipakai ulang).
 *
 * Status hanya bergerak MAJU terdaftar→diproses→selesai (rank map di
 * markStatus); mundur/berulang ditolak IncomingLockedException, dan
 * `selesai` membekukan seluruh field (guard di update()).
 */
class IncomingLetterService
{
    /**
     * Urutan status surat masuk — transisi hanya boleh MAJU (rank baru
     * HARUS lebih besar; sama/mundur → IncomingLockedException).
     *
     * @var array<string, int>
     */
    private const STATUS_RANKS = [
        'terdaftar' => 0,
        'diproses' => 1,
        'selesai' => 2,
    ];

    public function __construct(
        private readonly ImageToPdfComposer $composer = new ImageToPdfComposer,
        private readonly NumberingService $numbering = new NumberingService,
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly LetterLinkService $links = new LetterLinkService,
    ) {}

    /**
     * Daftarkan surat masuk: cadangkan agenda number, simpan scan
     * (bukti tunggal, immutable), lalu jejak audit.
     *
     * @param  array<string, mixed>  $data  kolom fillable IncomingLetter
     *                                      (year diabaikan — selalu diturunkan dari received_at agar
     *                                      folder scan, kolom year, dan counter agenda sepakat).
     * @param  string  $scanBytes  binary PDF scan — DIIKUTI bila
     *                             $scanKind 'pdf', diabaikan bila 'images'.
     * @param  string  $scanKind  'pdf' (scan sudah jadi) | 'images'
     *                            (foto digabung ImageToPdfComposer).
     * @param  list<string>  $imageBytesList  binary foto, satu foto = satu
     *                                        halaman (hanya untuk 'images').
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     * @param  array{type: string, id: string, relation: string}|null  $inReplyTo
     *                                                    induk runutan opsional — edge
     *                                                    ditulis DALAM transaksi register (Task 9 fase 1b):
     *                                                    gagal link → surat ikut rollback.
     *
     * @throws InvalidArgumentException scanKind tidak dikenal / received_at kosong / foto kosong-rusak
     * @throws PdfInvalidException scan final tidak diawali magic %PDF
     * @throws RuntimeException scan gagal ditulis ke disk documents
     */
    public function register(array $data, string $scanBytes, string $scanKind, array $imageBytesList = [], ?int $actorId = null, ?array $inReplyTo = null): IncomingLetter
    {
        $actorId ??= auth()->id();

        if (! in_array($scanKind, ['pdf', 'images'], true)) {
            throw new InvalidArgumentException(
                "scanKind harus 'pdf' atau 'images', didapat: '{$scanKind}'.",
            );
        }

        // Bytes scan final disusun SEBELUM transaksi: compose dompdf/FPDI
        // tidak menyentuh DB — bila gagal (foto kosong/tak terbaca)
        // belum ada mutasi apa pun.
        $scanPdf = $scanKind === 'images'
            ? $this->composer->compose($imageBytesList)
            : $scanBytes;

        if (! str_starts_with($scanPdf, '%PDF')) {
            throw new PdfInvalidException('Scan bukan PDF (magic byte %PDF tidak ada).');
        }

        if (! isset($data['received_at'])) {
            throw new InvalidArgumentException('Data registrasi wajib memuat received_at (tanggal surat diterima).');
        }

        $receivedAt = Carbon::parse($data['received_at']);
        $year = (int) $receivedAt->format('Y');

        // Path berkas yang mungkin tertulis selama transaksi — dipantau
        // (by-ref) agar catch di bawah tahu apa yang harus dibersihkan.
        $writtenPath = null;

        try {
            return IncomingLetter::query()->getConnection()->transaction(function () use ($data, $scanPdf, $scanKind, $actorId, $year, $inReplyTo, &$writtenPath): IncomingLetter {
                // Nomor agenda dicadangkan DI DALAM transaksi register
                // (savepoint): baris counter number_sequences dikunci,
                // jadi dua register paralel selalu mendapat agenda
                // berurutan tanpa duplikat — unique key agenda_number
                // meringkuki sisa race-nya.
                ['seq' => $seq, 'number' => $agendaNumber] = $this->numbering->reserveAgenda($year);

                // UUID dibuat DI DALAM transaksi sebelum berkas ditulis —
                // path scan diturunkan darinya sehingga baris dan berkas
                // selalu sepakat (pola Finalizer).
                $letterId = (string) Str::uuid();

                $letter = new IncomingLetter;
                $letter->fill($data); // hanya kolom fillable registrasi
                $letter->id = $letterId;
                $letter->year = $year;

                if (empty($letter->created_by) && $actorId !== null) {
                    $letter->created_by = $actorId;
                }

                // Sifat rahasia memaksa classification rahasia —
                // klasifikasi keamanan tidak boleh lebih longgar dari
                // sifat yang dinyatakan pengirim.
                if ($letter->sifat === 'rahasia') {
                    $letter->classification = 'rahasia';
                }

                // Kolom integritas & siklus hidup: per-atribut, bukan
                // mass-assign (lihat IncomingLetter::$fillable).
                $letter->status = 'terdaftar';
                $letter->agenda_number = $agendaNumber;
                $letter->agenda_seq = $seq;

                $scanPath = sprintf('incoming/%d/%s/scan.pdf', $year, $letterId);

                // Tulis berkas DI DALAM transaksi: gagal tulis →
                // exception → rollback seluruh mutasi DB di atas.
                if (Storage::disk('documents')->put($scanPath, $scanPdf) === false) {
                    throw new RuntimeException("Gagal menulis scan ke disk documents: {$scanPath}.");
                }
                $writtenPath = $scanPath;

                $letter->scan_path = $scanPath;
                $letter->scan_sha256 = hash('sha256', $scanPdf);
                $letter->scan_size = strlen($scanPdf);
                $letter->scan_pages = $this->countPdfPages($scanPdf);
                $letter->save();

                $this->audit->log(
                    $letterId,
                    AuditLogger::EVENT_INCOMING_CREATED,
                    $actorId,
                    array_filter([
                        'agenda_number' => $agendaNumber,
                        'scan_kind' => $scanKind,
                        'scan_sha256' => $letter->scan_sha256,
                        'scan_pages' => $letter->scan_pages,
                    ], fn ($value) => $value !== null),
                );

                // Edge in_reply_to (Task 9 fase 1b) ditulis DALAM transaksi
                // register (savepoint, pola DocumentController Task 6): bila
                // link() melempar (mis. induk terhapus dalam race setelah
                // preflight controller), baris surat + agenda + scan ikut
                // rollback — tidak pernah ada surat tanpa induk yang
                // diminta user. Untuk surat baru siklus mustahil (belum
                // punya edge), guard siklus tetap dijalankan link().
                if ($inReplyTo !== null) {
                    $this->links->link(
                        $inReplyTo['type'],
                        $inReplyTo['id'],
                        'incoming_letter',
                        $letterId,
                        $inReplyTo['relation'],
                        $actorId,
                    );
                }

                return $letter;
            }, attempts: 5);
        } catch (Throwable $e) {
            // Scan yatim: transaksi sudah rollback (DB konsisten), tapi
            // berkas yang tertulis sebelum kegagalan tidak ikut rollback.
            // Dihapus best-effort lalu exception ASLI dilempar ulang —
            // bebas race karena path memuat uuid yang gagal ter-commit
            // dan tak akan pernah dipakai ulang.
            if ($writtenPath !== null) {
                Storage::disk('documents')->delete($writtenPath);
            }

            throw $e;
        }
    }

    /**
     * Naikkan status surat masuk — HANYA maju terdaftar→diproses→
     * selesai (rank map); mundur atau mengulang status sama ditolak.
     *
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     *
     * @throws InvalidArgumentException status tidak dikenal
     * @throws IncomingLockedException mundur / status sama
     */
    public function markStatus(IncomingLetter $letter, string $status, ?int $actorId = null): IncomingLetter
    {
        if (! isset(self::STATUS_RANKS[$status])) {
            throw new InvalidArgumentException(
                "Status tidak dikenal: '{$status}' (yang sah: terdaftar|diproses|selesai).",
            );
        }

        $actorId ??= auth()->id();

        return IncomingLetter::query()->getConnection()->transaction(function () use ($letter, $status, $actorId): IncomingLetter {
            // Re-read DALAM lock: keputusan rank dibuat atas kondisi
            // terkini, bukan snapshot model pemanggil (pola Finalizer) —
            // dua markStatus paralel surat yang sama diserialisasi.
            /** @var IncomingLetter $locked */
            $locked = IncomingLetter::query()
                ->whereKey($letter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (self::STATUS_RANKS[$status] <= self::STATUS_RANKS[$locked->status]) {
                throw new IncomingLockedException(
                    "Status surat masuk {$locked->agenda_number} hanya bisa maju "
                    ."(terdaftar→diproses→selesai); saat ini '{$locked->status}', diminta '{$status}'.",
                );
            }

            $from = $locked->status;
            $locked->status = $status;
            $locked->save();

            $this->audit->log(
                (string) $locked->getKey(),
                AuditLogger::EVENT_INCOMING_STATUS,
                $actorId,
                ['from' => $from, 'to' => $status],
            );

            return $locked;
        }, attempts: 5);
    }

    /**
     * Ubah data surat masuk — hanya kolom fillable; surat berstatus
     * `selesai` terkunci penuh (semua field beku).
     *
     * @param  array<string, mixed>  $data  pasangan kolom→nilai; kolom di
     *                                      luar fillable (status/agenda/scan/…) diabaikan.
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     *
     * @throws IncomingLockedException surat sudah selesai
     */
    public function update(IncomingLetter $letter, array $data, ?int $actorId = null): IncomingLetter
    {
        $actorId ??= auth()->id();

        return IncomingLetter::query()->getConnection()->transaction(function () use ($letter, $data, $actorId): IncomingLetter {
            /** @var IncomingLetter $locked */
            $locked = IncomingLetter::query()
                ->whereKey($letter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isFinished()) {
                throw new IncomingLockedException(
                    "Surat masuk {$locked->agenda_number} sudah selesai — semua field terkunci dan tidak dapat diubah.",
                );
            }

            // Hanya kolom fillable: guard mass-assignment di model sudah
            // membuang sisanya, tapi disaring eksplisit di sini agar
            // payload audit jujur (kolom service-only tak pernah tampak
            // sebagai perubahan).
            $applicable = array_intersect_key($data, array_flip($locked->getFillable()));

            $locked->fill($applicable);

            // received_at berubah → year diturunkan ulang dari tahun baru
            // (register() mengabaikan year payload; update menyamakan
            // kembali) supaya filter tahun daftar sepakat dengan tanggal
            // tampil. Folder scan di disk TIDAK di-rename — bukti tunggal
            // sudah tertulis saat register, path scan_path tetap.
            if (array_key_exists('received_at', $applicable) && $locked->received_at !== null) {
                $locked->year = (int) $locked->received_at->format('Y');
            }

            // Invariant yang sama dengan register(): classification tidak
            // boleh lebih longgar dari sifat. Hanya dinaikkan otomatis ke
            // rahasia — tidak pernah diturunkan (over-classify aman,
            // under-classify bocor).
            if ($locked->sifat === 'rahasia') {
                $locked->classification = 'rahasia';
            }

            $locked->save();

            $this->audit->log(
                (string) $locked->getKey(),
                AuditLogger::EVENT_INCOMING_UPDATED,
                $actorId,
                ['fields' => array_keys($applicable)],
            );

            return $locked;
        }, attempts: 5);
    }

    /**
     * Hitung halaman PDF secara ringkas: hitung objek halaman
     * `/Type /Page` (bukan `/Pages`) via regex pada bytes.
     *
     * Jujur soal batasnya — ini heuristik: bisa meleset pada PDF yang
     * menyimpan objek di object stream (PDF 1.5+) sehingga polanya tak
     * terlihat di bytes mentah. Bila tidak menemukan satu pun halaman,
     * dikembalikan null (kolom scan_pages nullable = "tidak diketahui")
     * daripada membohongi dengan 0.
     */
    private function countPdfPages(string $bytes): ?int
    {
        $count = preg_match_all('/Type\s*\/Page(?!s)/', $bytes);

        return is_int($count) && $count > 0 ? $count : null;
    }
}
