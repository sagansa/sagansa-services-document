<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IncomingLockedException;
use App\Http\Controllers\Controller;
use App\Models\DispositionRecord;
use App\Models\IncomingLetter;
use App\Models\Signer;
use App\Services\AuditLogger;
use App\Services\IncomingLetterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * API disposisi surat masuk (fase 1b):
 *
 *   POST /incoming-letters/{id}/dispositions  {to_user_id, instruction}
 *        — penerima WAJIB signer aktif (modul tertutup bagi non-signer,
 *          422 pesan Indonesia); surat otomatis naik `diproses` bila masih
 *          `terdaftar` (sudah `diproses` tetap boleh menambah; `selesai`
 *          = arsip final → 409 INCOMING_LOCKED).
 *   POST /dispositions/{id}/complete
 *        — tandai selesai + acted_at (SATU-SATUNYA kolom baris disposisi
 *          yang boleh berubah — append-only); complete ulang → 409
 *          INCOMING_LOCKED (dipilih idempoten-friendly: code sama dengan
 *          lock surat masuk agar klien cukup satu penanganan 409; bukan
 *          200-diam supaya jejak "selesai dua kali" tidak tersamar).
 *
 * Baris ditulis per-atribut dalam SATU transaksi bersama audit dan
 * kenaikan status surat (gagal apa pun → rollback penuh).
 */
class DispositionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly IncomingLetterService $incoming = new IncomingLetterService,
    ) {}

    /**
     * POST /incoming-letters/{id}/dispositions — tambah catatan pengarahan.
     */
    public function store(Request $request, string $letterId): JsonResponse
    {
        $validated = $request->validate(
            [
                'to_user_id' => ['required', 'integer'],
                'instruction' => ['required', 'string', 'max:65535'],
            ],
            $this->messages(),
            $this->fieldNames(),
        );

        // users hidup di DB sagansa_user (lintas database) — keaktifan
        // signer diperiksa lewat tabel signers lokal, bukan relasi user.
        $signerAktif = Signer::query()
            ->where('user_id', $validated['to_user_id'])
            ->where('is_active', true)
            ->exists();

        if (! $signerAktif) {
            throw ValidationException::withMessages([
                'to_user_id' => 'Penerima disposisi harus signer aktif.',
            ]);
        }

        /** @var IncomingLetter $letter */
        $letter = IncomingLetter::query()->findOrFail($letterId);

        // `selesai` = arsip final (keputusan #9 fase 1b): tidak ada
        // pengarahan baru pada surat yang sudah selesai — 409
        // INCOMING_LOCKED, code sama dengan lock PATCH surat masuk
        // agar klien cukup satu penanganan 409.
        if ($letter->status === 'selesai') {
            throw new IncomingLockedException(
                "Surat masuk {$letter->agenda_number} sudah selesai — disposisi tidak dapat ditambahkan (arsip final).",
            );
        }

        $actorId = (int) $request->user()->id;

        $record = DispositionRecord::query()->getConnection()->transaction(
            function () use ($validated, $letter, $actorId, $request): DispositionRecord {
                // Assignment per-atribut (model guarded): id uuid, penerima,
                // instruksi, status awal open, pencatat.
                $record = new DispositionRecord;
                $record->id = (string) Str::uuid();
                $record->incoming_letter_id = (string) $letter->id;
                $record->to_user_id = $validated['to_user_id'];
                $record->instruction = $validated['instruction'];
                $record->status = 'open';
                $record->created_by = $actorId;
                $record->save();

                $this->audit->log(
                    (string) $letter->id,
                    AuditLogger::EVENT_DISPOSITION_ADDED,
                    $actorId,
                    ['disposition_id' => $record->id, 'to_user_id' => $record->to_user_id],
                    $request->ip(),
                    $this->userAgent($request),
                );

                // Disposisi pertama = surat sedang diproses. markStatus
                // me-lock ulang baris surat di transaksinya sendiri
                // (savepoint) — mundur/berulang tak mungkin dari sini
                // (hanya dipanggil saat masih `terdaftar`).
                if ($letter->status === 'terdaftar') {
                    $this->incoming->markStatus($letter, 'diproses', $actorId);
                }

                return $record;
            },
            attempts: 5,
        );

        return response()->json([
            'success' => true,
            'data' => $this->resource($record),
        ], 201);
    }

    /**
     * POST /dispositions/{id}/complete — selesaikan disposisi penerima.
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        /** @var DispositionRecord $record */
        $record = DispositionRecord::query()->findOrFail($id);
        $actorId = (int) $request->user()->id;

        $updated = DispositionRecord::query()->getConnection()->transaction(
            function () use ($record, $actorId, $request): DispositionRecord {
                // Re-read DALAM lock: keputusan "sudah selesai?" dibuat
                // atas kondisi terkini (pola IncomingLetterService) — dua
                // complete paralel disposisi sama tepat satu yang menang.
                /** @var DispositionRecord $locked */
                $locked = DispositionRecord::query()
                    ->whereKey($record->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === 'selesai') {
                    throw new IncomingLockedException(sprintf(
                        'Disposisi sudah diselesaikan pada %s — tidak dapat diselesaikan dua kali.',
                        $locked->acted_at?->format('Y-m-d H:i:s') ?? '-',
                    ));
                }

                // Hanya status + acted_at yang berubah (semantik append-only
                // disposition_records); isi instruksi/penerima beku.
                $locked->status = 'selesai';
                $locked->acted_at = now();
                $locked->save();

                $this->audit->log(
                    (string) $locked->incoming_letter_id,
                    AuditLogger::EVENT_DISPOSITION_COMPLETED,
                    $actorId,
                    ['disposition_id' => (string) $locked->id, 'to_user_id' => $locked->to_user_id],
                    $request->ip(),
                    $this->userAgent($request),
                );

                return $locked;
            },
            attempts: 5,
        );

        return response()->json([
            'success' => true,
            'data' => $this->resource($updated),
        ]);
    }

    /**
     * Bentuk disposisi untuk response — label penerima lintas-DB
     * "Pengguna #id" (konsisten fase 1: nama user tidak dibaca di sini).
     *
     * @return array<string, mixed>
     */
    private function resource(DispositionRecord $record): array
    {
        return [
            'id' => $record->id,
            'incoming_letter_id' => $record->incoming_letter_id,
            'to_user_id' => $record->to_user_id,
            'to_user_label' => "Pengguna #{$record->to_user_id}",
            'instruction' => $record->instruction,
            'status' => $record->status,
            'acted_at' => $record->acted_at?->toIso8601String(),
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /**
     * Pesan validasi bahasa Indonesia.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'integer' => ':attribute harus berupa angka bulat.',
            'string' => ':attribute harus berupa teks.',
            'max.string' => ':attribute tidak boleh lebih dari :max karakter.',
        ];
    }

    /**
     * Nama field ramah untuk pesan validasi.
     *
     * @return array<string, string>
     */
    private function fieldNames(): array
    {
        return [
            'to_user_id' => 'penerima disposisi',
            'instruction' => 'instruksi disposisi',
        ];
    }

    /** User agent dipotong agar muat kolom string audit_logs. */
    private function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null || $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}
