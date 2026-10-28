<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\DecodesPdfStreams;

/**
 * Regression flake-hunt 1c — helper decodedStreams HARUS tetap membaca
 * teks stream meski byte terakhir data stream zlib adalah 0x0D ("\r").
 *
 * Dulu helper memakai regex `stream\r?\n(.*?)\r?\nendstream`; ketika
 * data diakhiri "\r", byte itu diserap separator → gzuncompress gagal
 * → assertion teks PDF gagal acak (~1/256 per render). Test ini
 * membangun stream minimal dengan akhiran "\r" secara deterministik
 * (payload dipilih agar adler32 zlib ber-MSB 0x0D).
 */
class DecodesPdfStreamsTest extends TestCase
{
    #[Test]
    public function decodes_stream_whose_final_data_byte_is_carriage_return(): void
    {
        // Payload deterministik: gzcompress-nya berakhir byte 0x0D.
        $payload = 'Andi Wijaya menandatangani';
        for ($i = 0; ! str_ends_with(gzcompress($payload), "\r"); $i++) {
            $payload = 'Andi Wijaya menandatangani '.$i;
        }
        $data = gzcompress($payload);
        $this->assertSame("\r", substr($data, -1), 'precondition: stream berakhir 0x0D');

        // Bentuk CPDF/FPDF: /Length terakhir di dictionary, lalu "stream\n".
        $pdf = "<< /Filter /FlateDecode /Length ".strlen($data)." >>\nstream\n".$data."\nendstream\nendobj\n";

        $decoder = new class
        {
            use DecodesPdfStreams;
        };

        $this->assertStringContainsString($payload, $decoder->decodedStreams($pdf));
    }

    #[Test]
    public function decodes_multiple_streams_concatenated(): void
    {
        $first = gzcompress('PT SAGANSA');
        $second = gzcompress('Halaman 1 dari 1');

        $pdf = "<< /Filter /FlateDecode /Length ".strlen($first)." >>\nstream\n".$first."\nendstream\n"
            ."<< /Filter /FlateDecode /Length ".strlen($second).">>\nstream\n".$second."\nendstream\n";

        $decoder = new class
        {
            use DecodesPdfStreams;
        };

        $decoded = $decoder->decodedStreams($pdf);

        $this->assertStringContainsString('PT SAGANSA', $decoded);
        $this->assertStringContainsString('Halaman 1 dari 1', $decoded);
    }
}
