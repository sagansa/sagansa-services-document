<?php

namespace App\Services;

use App\Models\Document;
use App\Models\NumberSequence;
use DateTimeInterface;

/**
 * Penerbitan nomor surat berurutan per (letter_type_code, unit, year).
 *
 * Counter disimpan pada tabel number_sequences.last_seq. Setiap reserve
 * berjalan DALAM transaksi pada koneksi mysql_document:
 *
 *   1. pastikan baris counter ada (insertOrIgnore — setara
 *      firstOrCreate, tapi kebal race: unique key menolak duplikat
 *      sehingga dua transaksi yang membuat baris bersamaan tidak
 *      saling gagal);
 *   2. kunci baris (MySQL: SELECT ... FOR UPDATE);
 *   3. increment last_seq lalu baca nilai barunya DI DALAM lock.
 *
 * Dengan begitu dua reserve paralel untuk counter yang sama selalu
 * mendapat seq berurutan tanpa duplikat. Transaksi diulang beberapa
 * kali bila koneksi lain sedang memegang write lock (contoh sqlite
 * "database is locked" terdeteksi sebagai concurrency error oleh
 * Laravel dan di-retry otomatis).
 */
class NumberingService
{
    /** Code counter agenda surat masuk pada number_sequences (baris generic, fase 1). */
    private const AGENDA_COUNTER_TYPE = 'SM';

    /** Unit counter agenda surat masuk pada number_sequences. */
    private const AGENDA_COUNTER_UNIT = 'IN';

    /**
     * Cadangkan nomor urut berikutnya untuk (jenis, unit, tahun).
     *
     * Format nomor: %03d/{code}/{unit}/{romanMonth}/{year},
     * contoh 001/ST/DIR/X/2026.
     *
     * @return array{seq: int, number: string}
     */
    public function reserve(string $letterTypeCode, string $unit, int $year): array
    {
        $seq = $this->nextSeq([
            'letter_type_code' => $letterTypeCode,
            'unit' => $unit,
            'year' => $year,
        ]);

        $number = sprintf(
            '%03d/%s/%s/%s/%d',
            $seq,
            $letterTypeCode,
            $unit,
            self::romanMonth(now()),
            $year,
        );

        return ['seq' => $seq, 'number' => $number];
    }

    /**
     * Cadangkan nomor AGENDA surat masuk untuk tahun $year — format
     * berbeda dari nomor surat keluar: `SM-%03d/%s/%d`
     * (seq, bulan romawi, tahun), contoh SM-001/X/2026.
     *
     * Counter agenda memakai baris number_sequences yang sama dengan
     * nomor surat keluar (code 'SM' + unit 'IN' — kolom generic fase 1),
     * sehingga dua register paralel diserialisasi oleh lock baris
     * counter yang sama; unique key `agenda_number` meringkukinya.
     *
     * @return array{seq: int, number: string}
     */
    public function reserveAgenda(int $year): array
    {
        $seq = $this->nextSeq([
            'letter_type_code' => self::AGENDA_COUNTER_TYPE,
            'unit' => self::AGENDA_COUNTER_UNIT,
            'year' => $year,
        ]);

        return [
            'seq' => $seq,
            'number' => sprintf('SM-%03d/%s/%d', $seq, self::romanMonth(now()), $year),
        ];
    }

    /**
     * Seq berikutnya untuk satu baris counter: insertOrIgnore baris →
     * lockForUpdate → increment → baca nilai baru DI DALAM lock.
     * Jalur bersama reserve()/reserveAgenda() (refactor fase 1b —
     * perilaku reserve()/ensureReserved() tidak berubah).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function nextSeq(array $attributes): int
    {
        // Transaksi DIJALANKAN pada koneksi model (mysql_document), bukan
        // koneksi default — di test koneksi default adalah sqlite :memory:
        // yang tidak memiliki tabel number_sequences.
        return NumberSequence::query()->getConnection()->transaction(function () use ($attributes): int {
            NumberSequence::query()->insertOrIgnore(
                [...$attributes, 'last_seq' => 0],
            );

            /** @var NumberSequence $sequence */
            $sequence = NumberSequence::query()
                ->where($attributes)
                ->lockForUpdate()
                ->firstOrFail();

            NumberSequence::query()
                ->whereKey($sequence->id)
                ->increment('last_seq');

            return (int) NumberSequence::query()
                ->whereKey($sequence->id)
                ->value('last_seq');
        }, attempts: 5);
    }

    /**
     * Pastikan surat SUDAH bernomor — dipanggil sebelum bytes PDF
     * diproduksi (render/stamp) supaya nomor benar-benar tercetak di
     * berkas (Fix C1: dulu reserve terjadi di persist SETELAH bytes
     * jadi, sehingga metode a merender number null).
     *
     * Transaksi + lockForUpdate re-read baris surat: bila `number`
     * masih null/'' → reserve lewat reserve() lalu simpan number+seq.
     * IDEMPOTEN: nomor sudah terisi → no-op (counter tidak maju), aman
     * dipanggil berkali-kali (mis. pra-final lalu finalize).
     */
    public function ensureReserved(Document $doc): void
    {
        Document::query()->getConnection()->transaction(function () use ($doc): void {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($doc->id)->lockForUpdate()->firstOrFail();

            if ($locked->number !== null && $locked->number !== '') {
                return;
            }

            ['seq' => $seq, 'number' => $number] = $this->reserve(
                $locked->letterType()->firstOrFail()->code,
                $locked->unit,
                $locked->year,
            );

            $locked->number = $number;
            $locked->seq = $seq;
            $locked->save();
        }, attempts: 5);
    }

    /**
     * Angka romawi bulan (I..XII) dari tanggal — dipakai pada segmen
     * bulan nomor surat.
     *
     * @return string nilai I..XII
     */
    private static function romanMonth(DateTimeInterface $date): string
    {
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

        return $roman[(int) $date->format('n') - 1];
    }
}
