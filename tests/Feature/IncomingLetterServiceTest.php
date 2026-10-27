<?php

namespace Tests\Feature;

use App\Exceptions\IncomingLockedException;
use App\Exceptions\PdfInvalidException;
use App\Models\AuditLog;
use App\Models\IncomingLetter;
use App\Models\Party;
use App\Services\AuditLogger;
use App\Services\ImageToPdfComposer;
use App\Services\IncomingLetterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CreatesDocumentSchema;
use Tests\TestCase;

/**
 * IncomingLetterService — registrasi surat masuk (fase 1b): agenda
 * number via NumberingService::reserveAgenda (counter 'SM'/'IN' under
 * lock), scan immutable (sha256/size/pages + berkas disk documents pada
 * path incoming/{yyyy}/{uuid}/scan.pdf), sifat rahasia memaksa
 * classification rahasia, status hanya MAJU terdaftar→diproses→selesai,
 * dan selesai mengunci semua field.
 */
class IncomingLetterServiceTest extends TestCase
{
    use CreatesDocumentSchema;

    private IncomingLetterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDocumentSchema();
        Storage::fake('documents');
        $this->service = new IncomingLetterService;
        Carbon::setTestNow('2026-10-27');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function register_pdf_assigns_agenda_scan_metadata_and_audit(): void
    {
        $pdf = (new ImageToPdfComposer)->compose([$this->pngBytes(40, 60)]);

        $letter = $this->service->register($this->registrationData(), $pdf, 'pdf', [], 77);

        // Agenda pertama tahun 2026 — Oktober = X (setTestNow 2026-10-27).
        $this->assertSame('SM-001/X/2026', $letter->agenda_number);
        $this->assertSame(1, $letter->agenda_seq);
        $this->assertSame(2026, $letter->year, 'year mengikuti received_at');
        $this->assertSame('terdaftar', $letter->status);
        $this->assertSame('umum', $letter->classification);

        // Counter agenda = baris number_sequences code 'SM' unit 'IN'.
        $this->assertDatabaseHas('number_sequences', [
            'letter_type_code' => 'SM',
            'unit' => 'IN',
            'year' => 2026,
            'last_seq' => 1,
        ], 'mysql_document');

        // Metadata scan dari bytes final yang disimpan.
        $this->assertSame(hash('sha256', $pdf), $letter->scan_sha256);
        $this->assertSame(strlen($pdf), $letter->scan_size);
        $this->assertSame(1, $letter->scan_pages);

        // Berkas scan pada path incoming/{yyyy}/{uuid}/scan.pdf, isi
        // round-trip identik dengan bytes masukan.
        $path = "incoming/2026/{$letter->id}/scan.pdf";
        $this->assertSame($path, $letter->scan_path);
        Storage::disk('documents')->assertExists($path);
        $this->assertSame($pdf, Storage::disk('documents')->get($path));

        // Jejak audit incoming_created dengan actor + agenda.
        $log = AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_INCOMING_CREATED)
            ->firstOrFail();
        $this->assertSame(77, $log->actor_user_id);
        $this->assertSame('SM-001/X/2026', $log->payload['agenda_number']);
        $this->assertSame(hash('sha256', $pdf), $log->payload['scan_sha256']);
    }

    #[Test]
    public function register_images_composes_three_photos_into_three_page_scan(): void
    {
        $photos = [$this->pngBytes(30, 40), $this->pngBytes(30, 40), $this->pngBytes(30, 40)];

        // scanKind 'images': $scanBytes diabaikan, scan disusun dari foto.
        $letter = $this->service->register($this->registrationData(), 'bytes-diabaikan', 'images', $photos, 77);

        $stored = Storage::disk('documents')->get("incoming/2026/{$letter->id}/scan.pdf");

        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertSame(3, $letter->scan_pages, 'satu foto = satu halaman');
        $this->assertSame(hash('sha256', $stored), $letter->scan_sha256, 'sha256 dari bytes tersimpan');
        $this->assertSame(strlen((string) $stored), $letter->scan_size);
    }

    #[Test]
    public function register_rahasia_forces_classification_rahasia(): void
    {
        $letter = $this->service->register(
            $this->registrationData(['sifat' => 'rahasia', 'classification' => 'umum']),
            '%PDF-1.4 surat rahasia',
            'pdf',
            [],
            77,
        );

        $this->assertSame('rahasia', $letter->sifat);
        $this->assertSame(
            'rahasia',
            $letter->classification,
            'sifat rahasia memaksa classification rahasia meski isian menyebut umum',
        );
    }

    #[Test]
    public function register_rejects_bytes_that_are_not_pdf(): void
    {
        try {
            $this->service->register($this->registrationData(), 'INI BUKAN PDF', 'pdf', [], 77);
            $this->fail('Scan non-PDF harus ditolak.');
        } catch (PdfInvalidException) {
            // Validasi terjadi SEBELUM transaksi: tanpa baris & berkas.
            $this->assertSame(0, IncomingLetter::query()->count());
            $this->assertSame([], Storage::disk('documents')->allFiles());
            $this->assertDatabaseMissing('number_sequences', [
                'letter_type_code' => 'SM',
            ], 'mysql_document');
        }
    }

    #[Test]
    public function register_rejects_unknown_scan_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->register($this->registrationData(), '%PDF-1.4', 'fax', [], 77);
    }

    /**
     * Dua PROSES php terpisah (dua koneksi PDO asli ke file sqlite WAL
     * yang sama) memanggil reserveAgenda(2026) serentak — 5 reserve per
     * proses. Lock transaksi HARUS menghasilkan seq 1..10 unik dengan
     * agenda number SM-xxx/X/2026 tanpa duplikat (melingkupi guard
     * unique agenda_number).
     */
    #[Test]
    public function parallel_agenda_reservations_are_unique_and_consecutive(): void
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
            'seq agenda paralel harus persis 1..'.(2 * $perWorker).' tanpa duplikat',
        );

        $numbers = array_column($results, 'number');
        $this->assertCount(2 * $perWorker, array_unique($numbers), 'agenda number tidak boleh duplikat');
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^SM-\d{3}\/X\/2026$/', $number);
        }

        $this->assertDatabaseHas('number_sequences', [
            'letter_type_code' => 'SM',
            'unit' => 'IN',
            'year' => 2026,
            'last_seq' => 2 * $perWorker,
        ], 'mysql_document');
    }

    #[Test]
    public function mark_status_advances_forward_and_writes_audit(): void
    {
        $letter = IncomingLetter::factory()->create(['status' => 'terdaftar']);

        $this->service->markStatus($letter, 'diproses', 77);
        $this->assertSame('diproses', $letter->fresh()->status);

        $this->service->markStatus($letter->fresh(), 'selesai', 77);
        $this->assertSame('selesai', $letter->fresh()->status);
        $this->assertTrue($letter->fresh()->isFinished());

        $logs = AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_INCOMING_STATUS)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['from' => 'terdaftar', 'to' => 'diproses'], $logs[0]->payload);
        $this->assertSame(['from' => 'diproses', 'to' => 'selesai'], $logs[1]->payload);
        $this->assertSame(77, $logs[0]->actor_user_id);
    }

    #[Test]
    public function mark_status_rejects_backward_same_and_unknown_status(): void
    {
        $letter = IncomingLetter::factory()->create(['status' => 'diproses']);

        try {
            $this->service->markStatus($letter, 'terdaftar', 77);
            $this->fail('mundur harus ditolak IncomingLockedException.');
        } catch (IncomingLockedException) {
            $this->assertSame('diproses', $letter->fresh()->status);
        }

        try {
            $this->service->markStatus($letter, 'diproses', 77);
            $this->fail('status sama harus ditolak IncomingLockedException.');
        } catch (IncomingLockedException) {
            $this->assertSame('diproses', $letter->fresh()->status);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->service->markStatus($letter, 'terbit', 77);
    }

    #[Test]
    public function update_after_selesai_is_locked(): void
    {
        $letter = IncomingLetter::factory()->status('selesai')->create();
        $subject = $letter->subject;

        try {
            $this->service->update($letter, ['subject' => 'perubahan terlarang'], 77);
            $this->fail('update surat selesai harus ditolak IncomingLockedException.');
        } catch (IncomingLockedException) {
            $this->assertSame($subject, $letter->fresh()->subject);
            $this->assertSame(0, AuditLog::query()
                ->where('document_id', $letter->id)
                ->where('event', AuditLogger::EVENT_INCOMING_UPDATED)
                ->count());
        }
    }

    #[Test]
    public function update_changes_only_fillable_fields_and_audits(): void
    {
        $letter = IncomingLetter::factory()->status('terdaftar')->create();
        $agenda = $letter->agenda_number;

        $updated = $this->service->update($letter, [
            'subject' => 'Revisi judul sesuai disposisi',
            'number_external' => '200/EXT/2026',
            // Kolom di luar fillable harus diabaikan:
            'status' => 'selesai',
            'agenda_number' => 'SM-999/X/2026',
            'scan_sha256' => str_repeat('a', 64),
        ], 77);

        $this->assertSame('Revisi judul sesuai disposisi', $updated->subject);
        $this->assertSame('200/EXT/2026', $updated->number_external);
        $this->assertSame('terdaftar', $updated->fresh()->status, 'status bukan fillable');
        $this->assertSame($agenda, $updated->fresh()->agenda_number, 'agenda immutable');

        $log = AuditLog::query()
            ->where('document_id', $letter->id)
            ->where('event', AuditLogger::EVENT_INCOMING_UPDATED)
            ->firstOrFail();
        $this->assertSame(77, $log->actor_user_id);
        $this->assertSame(['subject', 'number_external'], $log->payload['fields']);
    }

    #[Test]
    public function failure_after_scan_write_cleans_up_orphan_file_and_rethrows(): void
    {
        // Audit insert dipaksa gagal — terjadi SETELAH Storage::put sukses
        // (urutan register: reserve agenda → tulis scan → save → audit).
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('log')->willThrowException(new RuntimeException('audit insert gagal'));
        $service = new IncomingLetterService(audit: $audit);

        try {
            $service->register($this->registrationData(), '%PDF-1.4 akan gagal di audit', 'pdf', [], 77);
            $this->fail('Exception dari audit harus terlempar keluar.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit insert gagal', $e->getMessage(), 'exception ASLI yang rethrow, bukan yang lain');
        }

        // Scan yatim dibersihkan dari disk documents & DB konsisten.
        $this->assertSame(
            [],
            Storage::disk('documents')->allFiles(),
            'tidak boleh ada berkas tersisa setelah transaksi gagal',
        );
        $this->assertSame(0, IncomingLetter::query()->count(), 'transaksi rollback: tanpa baris surat masuk');
    }

    /**
     * Data registrasi sah (kolom fillable) — received_at 2026-10-20.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationData(array $overrides = []): array
    {
        return $overrides + [
            'company_id' => null,
            'year' => 2026,
            'number_external' => '100/EXT/2026',
            'letter_date' => '2026-10-18',
            'received_at' => '2026-10-20',
            'sender_party_id' => Party::factory()->create()->id,
            'subject' => 'Undangan rapat koordinasi triwulan',
            'sifat' => 'biasa',
            'classification' => 'umum',
            'attachments_note' => 'Lampiran 2 lembar',
            'created_by' => 77,
        ];
    }

    /** PNG kecil via GD (pola IssuanceApiTest::pngBase64). */
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 120, 200));

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    /**
     * Worker standalone: boot app pada APP_ENV=testing (koneksi
     * mysql_document → file sqlite WAL yang sama dengan parent), lalu
     * reserveAgenda(2026) sejumlah $times, hasil dicetak JSON per baris.
     * Berkas temp — tidak di-commit.
     */
    private function writeWorkerScript(int $times): string
    {
        $script = <<<PHP
        <?php

        require {$this->varExport(base_path('vendor/autoload.php'))};

        \$app = require {$this->varExport(base_path('bootstrap/app.php'))};
        \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        \Carbon\Carbon::setTestNow('2026-10-27');

        \$service = new App\Services\NumberingService();
        for (\$i = 0; \$i < {$times}; \$i++) {
            echo json_encode(\$service->reserveAgenda(2026)), PHP_EOL;
        }
        PHP;

        $path = sys_get_temp_dir().'/incoming_agenda_worker_'.uniqid().'.php';
        file_put_contents($path, $script);

        return $path;
    }

    private function varExport(string $value): string
    {
        return var_export($value, true);
    }
}
