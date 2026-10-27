<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\LetterType;
use App\Services\NumberingService;
use Carbon\Carbon;
use Symfony\Component\Process\Process;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * NumberingService::reserve() — counter nomor surat per
 * (letter_type_code, unit, year) di bawah lock transaksi.
 *
 * Format: sprintf('%03d/%s/%s/%s/%d', seq, code, unit, romanMonth(now()), year)
 * contoh 001/ST/DIR/X/2026.
 */
class NumberingServiceTest extends TestCase
{
    use CreatesDocumentSchema;

    private NumberingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
        $this->service = new NumberingService;
        Carbon::setTestNow('2026-10-26');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_reservation_produces_001_st_dir_x_2026(): void
    {
        $result = $this->service->reserve('ST', 'DIR', 2026);

        $this->assertSame(['seq' => 1, 'number' => '001/ST/DIR/X/2026'], $result);
        $this->assertDatabaseHas('number_sequences', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'year' => 2026,
            'last_seq' => 1,
        ], 'mysql_document');
    }

    public function test_second_reservation_gets_002(): void
    {
        $first = $this->service->reserve('ST', 'DIR', 2026);
        $second = $this->service->reserve('ST', 'DIR', 2026);

        $this->assertSame(1, $first['seq']);
        $this->assertSame('001/ST/DIR/X/2026', $first['number']);
        $this->assertSame(2, $second['seq']);
        $this->assertSame('002/ST/DIR/X/2026', $second['number']);
        $this->assertDatabaseHas('number_sequences', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'year' => 2026,
            'last_seq' => 2,
        ], 'mysql_document');
    }

    public function test_different_units_have_independent_counters(): void
    {
        $dir = $this->service->reserve('ST', 'DIR', 2026);
        $keu = $this->service->reserve('ST', 'KEU', 2026);
        $dirAgain = $this->service->reserve('ST', 'DIR', 2026);

        $this->assertSame('001/ST/DIR/X/2026', $dir['number']);
        $this->assertSame('001/ST/KEU/X/2026', $keu['number']);
        $this->assertSame('002/ST/DIR/X/2026', $dirAgain['number']);
        $this->assertDatabaseCount('number_sequences', 2, 'mysql_document');
    }

    /**
     * Fix C1 — ensureReserved(): cadangkan nomor untuk surat pra-nomor;
     * IDEMPOTEN: panggilan kedua tidak memajukan counter / mengganti
     * nomor (pra-final lalu finalize boleh memanggil berkali-kali).
     */
    public function test_ensure_reserved_fills_number_and_is_idempotent(): void
    {
        $type = LetterType::factory()->create(['code' => 'ST']);
        $document = Document::factory()->create([
            'letter_type_id' => $type->id,
            'number' => null,
            'seq' => 0,
            'unit' => 'DIR',
            'year' => 2026,
        ]);

        $this->service->ensureReserved($document);

        $first = $document->fresh();
        $this->assertSame('001/ST/DIR/X/2026', $first->number);
        $this->assertSame(1, $first->seq);

        // Panggilan kedua: no-op — counter TIDAK maju, nomor tetap.
        $this->service->ensureReserved($first);
        $this->service->ensureReserved($document);

        $second = $document->fresh();
        $this->assertSame('001/ST/DIR/X/2026', $second->number, 'nomor tidak boleh berubah');
        $this->assertSame(1, $second->seq, 'seq tidak boleh maju pada panggilan ulang');
        $this->assertDatabaseHas('number_sequences', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'year' => 2026,
            'last_seq' => 1,
        ], 'mysql_document');
    }

    /**
     * Dua PROSES php terpisah (dua koneksi PDO asli ke file sqlite WAL
     * yang sama) memanggil reserve() serentak — kombinasi 5 reserve per
     * proses. Lock transaksi HARUS menghasilkan seq 1..10 unik tanpa
     * duplikat/galat.
     */
    public function test_parallel_reservations_from_two_processes_are_unique_and_consecutive(): void
    {
        $perWorker = 5;
        $workerPath = $this->writeWorkerScript($perWorker);

        try {
            $processes = [];
            for ($i = 0; $i < 2; $i++) {
                $process = new Process(
                    [PHP_BINARY, $workerPath],
                    null,
                    ['APP_ENV' => 'testing'] + getenv(),
                );
                $process->setTimeout(120);
                $process->start();
                $processes[] = $process;
            }

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(
                    0,
                    $process->getExitCode(),
                    "worker gagal:\n".$process->getOutput()."\n".$process->getErrorOutput(),
                );

                foreach (array_filter(explode("\n", trim($process->getOutput()))) as $line) {
                    $results[] = json_decode($line, true);
                }
            }
        } finally {
            @unlink($workerPath);
        }

        $this->assertCount(2 * $perWorker, $results);

        $seqs = array_column($results, 'seq');
        sort($seqs);
        $this->assertSame(
            range(1, 2 * $perWorker),
            $seqs,
            'seq paralel harus persis 1..'.(2 * $perWorker).' tanpa duplikat',
        );

        $numbers = array_column($results, 'number');
        $this->assertCount(2 * $perWorker, array_unique($numbers), 'nomor tidak boleh duplikat');
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^\d{3}\/ST\/DIR\/X\/2026$/', $number);
        }

        $this->assertDatabaseHas('number_sequences', [
            'letter_type_code' => 'ST',
            'unit' => 'DIR',
            'year' => 2026,
            'last_seq' => 2 * $perWorker,
        ], 'mysql_document');
    }

    /**
     * Worker standalone: boot app pada APP_ENV=testing (koneksi
     * mysql_document → file sqlite WAL yang sama dengan parent), lalu
     * reserve() sejumlah $times dan cetak hasil sebagai JSON per baris.
     */
    private function writeWorkerScript(int $times): string
    {
        $script = <<<PHP
        <?php

        require {$this->varExport(base_path('vendor/autoload.php'))};

        \$app = require {$this->varExport(base_path('bootstrap/app.php'))};
        \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        \Carbon\Carbon::setTestNow('2026-10-26');

        \$service = new App\Services\NumberingService();
        for (\$i = 0; \$i < {$times}; \$i++) {
            echo json_encode(\$service->reserve('ST', 'DIR', 2026)), PHP_EOL;
        }
        PHP;

        $path = sys_get_temp_dir().'/numbering_worker_'.uniqid().'.php';
        file_put_contents($path, $script);

        return $path;
    }

    private function varExport(string $value): string
    {
        return var_export($value, true);
    }
}
