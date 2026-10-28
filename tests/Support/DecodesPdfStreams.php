<?php

namespace Tests\Support;

/**
 * Dekode seluruh content stream PDF (FlateDecode) menjadi satu string.
 *
 * Stream di-slice PERSIS sepanjang `/Length` yang dideklarasikan
 * dictionary objek — BUKAN dengan regex pemisah
 * `stream\r?\n(.*?)\r?\nendstream`. Regex itu mencuri byte terakhir
 * data ketika byte penutup stream zlib kebetulan 0x0D ("\r"):
 * separator stream CPDF/FPDF hanyalah "\n" sebelum "endstream",
 * sehingga "\r" milik data telanjur diserap sebagai bagian dari
 * pola separator `\r?\n` — chunk terpotong 1 byte, gzuncompress gagal
 * (byte terakhir checksum adler32 hilang), bytes terkompresi mentah
 * ter-append, dan SEMUA assertion teks pada stream itu gagal.
 *
 * Byte terakhir stream zlib = byte tertinggi adler32 (hampir uniform)
 * → peluang ~1/256 per render; memanggil test mana pun yang membaca
 * teks stream bisa jadi korban (flake).
 */
trait DecodesPdfStreams
{
    public function decodedStreams(string $pdf): string
    {
        preg_match_all(
            '/\/Length (\d+)[^>]*>>\s*stream\r?\n/',
            $pdf,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $decoded = '';
        foreach ($matches[0] as $i => [$token, $offset]) {
            $chunk = substr($pdf, $offset + strlen($token), (int) $matches[1][$i][0]);

            $uncompressed = @gzuncompress($chunk);
            $decoded .= $uncompressed === false ? $chunk : $uncompressed;
        }

        return $decoded;
    }
}
