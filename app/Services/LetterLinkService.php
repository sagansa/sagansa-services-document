<?php

namespace App\Services;

use App\Exceptions\LinkCycleException;
use App\Exceptions\LinkTargetNotFoundException;
use App\Models\Document;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pembuat edge runutan antar surat lintas jenis (fase 1b).
 *
 * letter_links TIDAK memakai FK ke node tabel (polymorphic manual) —
 * seluruh guard keabsahan edge dijalankan DI SINI, berurutan murah-ke-
 * mahal: argumen (tipe/relasi/self) → duplikat (idempoten, tanpa error)
 * → keberadaan target → siklus. Urutan ini membuat kasus tolak paling
 * umum gagal sebelum menyentuh DB lebih dalam.
 *
 * Cycle guard: himpunan leluhur parent dihitung dengan BFS NAIK — satu
 * query memuat SEMUA edge (tabel append-only dan kecil: paling banyak
 * satu baris per pasangan parent/child/relasi) lalu berjalan in-memory
 * mengikuti edge yang child-nya = node sekarang. Menambahkan P→C hanya
 * sah bila C BUKAN leluhur P — dengan kata lain tidak ada path C ⇝ P
 * yang bisa digabung dengan edge baru menjadi lingkar.
 *
 * Idempotensi duplikat: unique 5 kolom `letter_links_edge_unique`.
 * Pembacaan awal menangkap kasus umum; race dua insert paralel ditangkap
 * unique constraint → insert kalah melempar QueryException → baris
 * di-fetch ulang dan dikembalikan (insert menang sudah menulis audit;
 * yang kalah TIDAK menulis audit — jejak tetap tepat satu per edge).
 */
class LetterLinkService
{
    /** Tipe node surat yang sah untuk edge. */
    private const NODE_TYPES = ['document', 'incoming_letter'];

    /** Relasi runutan yang sah. */
    private const RELATIONS = ['balasan', 'tindak_lanjut', 'referensi'];

    public function __construct(private readonly AuditLogger $audit = new AuditLogger) {}

    /**
     * Tautkan child sebagai keturunan parent.
     *
     * @param  string  $parentType  'document' | 'incoming_letter'
     * @param  string  $parentId  uuid node parent pada tabel tipenya
     * @param  string  $childType  'document' | 'incoming_letter'
     * @param  string  $childId  uuid node child pada tabel tipenya
     * @param  string  $relation  'balasan' | 'tindak_lanjut' | 'referensi'
     * @param  int|null  $actorId  user pelaku; null = auth()->id()
     *
     * @throws InvalidArgumentException tipe/relasi tidak dikenal, atau parent dan child surat yang sama
     * @throws LinkTargetNotFoundException parent/child tidak ada pada tabel tipenya
     * @throws LinkCycleException edge akan membentuk siklus runutan
     */
    public function link(string $parentType, string $parentId, string $childType, string $childId, string $relation, ?int $actorId = null): LetterLink
    {
        if (! in_array($parentType, self::NODE_TYPES, true) || ! in_array($childType, self::NODE_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                "Tipe node surat tidak dikenal (yang sah: document|incoming_letter): parent '%s', child '%s'.",
                $parentType,
                $childType,
            ));
        }

        if (! in_array($relation, self::RELATIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                "Relasi link tidak dikenal: '%s' (yang sah: balasan|tindak_lanjut|referensi).",
                $relation,
            ));
        }

        if ($parentType === $childType && $parentId === $childId) {
            throw new InvalidArgumentException(sprintf(
                "Parent dan child tidak boleh surat yang sama (%s '%s').",
                $parentType,
                $parentId,
            ));
        }

        $actorId ??= auth()->id();

        return LetterLink::query()->getConnection()->transaction(function () use ($parentType, $parentId, $childType, $childId, $relation, $actorId): LetterLink {
            // Duplikat idempoten: edge identik sudah ada → kembalikan
            // barisnya tanpa error dan tanpa audit baru.
            $existing = $this->findEdge($parentType, $parentId, $childType, $childId, $relation);
            if ($existing !== null) {
                return $existing;
            }

            $this->assertTargetExists('parent', $parentType, $parentId);
            $this->assertTargetExists('child', $childType, $childId);

            if ($this->isAncestor($childType, $childId, $parentType, $parentId)) {
                throw new LinkCycleException(sprintf(
                    'Link ditolak karena akan membentuk siklus: child %s %s sudah merupakan leluhur parent %s %s.',
                    $childType,
                    $childId,
                    $parentType,
                    $parentId,
                ));
            }

            $link = new LetterLink;
            $link->id = (string) Str::uuid();
            $link->parent_type = $parentType;
            $link->parent_id = $parentId;
            $link->child_type = $childType;
            $link->child_id = $childId;
            $link->relation = $relation;
            $link->created_by = $actorId;

            try {
                $link->save();
            } catch (QueryException $e) {
                // Dua link() paralel edge sama: unique constraint memenangkan
                // satu insert. Bila baris kini ada, kembalikan itu (idempoten);
                // bila tidak, ini kegagalan asli yang bukan duplikat — lempar ulang.
                $winner = $this->findEdge($parentType, $parentId, $childType, $childId, $relation);
                $winner !== null ? $winner : throw $e;
            }

            $this->audit->log(
                $childId,
                AuditLogger::EVENT_LETTER_LINKED,
                $actorId,
                [
                    'parent' => ['type' => $parentType, 'id' => $parentId],
                    'child' => ['type' => $childType, 'id' => $childId],
                    'relation' => $relation,
                ],
            );

            return $link;
        }, attempts: 3);
    }

    /**
     * Cari edge persis (5 kolom unique) — null bila belum ada.
     */
    private function findEdge(string $parentType, string $parentId, string $childType, string $childId, string $relation): ?LetterLink
    {
        /** @var LetterLink|null */
        return LetterLink::query()
            ->where('parent_type', $parentType)
            ->where('parent_id', $parentId)
            ->where('child_type', $childType)
            ->where('child_id', $childId)
            ->where('relation', $relation)
            ->first();
    }

    /**
     * Pastikan node ada pada tabel tipenya (Eloquent whereKey) — tanpa FK
     * di letter_links, inilah satu-satunya penjaga referensial edge.
     *
     * @throws LinkTargetNotFoundException
     */
    private function assertTargetExists(string $role, string $type, string $id): void
    {
        $query = $type === 'document' ? Document::query() : IncomingLetter::query();

        if (! $query->whereKey($id)->exists()) {
            throw new LinkTargetNotFoundException(sprintf(
                "Target link tidak ditemukan: %s %s '%s' tidak ada pada tabel %s.",
                $role,
                $type,
                $id,
                $type === 'document' ? 'documents' : 'incoming_letters',
            ));
        }
    }

    /**
     * Apakah node $candidateType/$candidateId merupakan leluhur (secara
     * langsung maupun tak langsung) dari node $type/$id?
     *
     * BFS naik: mulai dari node, ikuti SEMUA edge yang menjadikannya child
     * (edge child = node sekarang) lalu naik ke parent masing-masing,
     * hingga himpunan leluhur habis. Tabel dimuat sekali lalu ditelusuri
     * in-memory — letter_links append-only dan kecil, satu query lebih
     * murah dan lebih deterministik daripada query per level.
     */
    private function isAncestor(string $candidateType, string $candidateId, string $type, string $id): bool
    {
        // Indeks parent per child: "type:id" => list<"type:id" parent>.
        $parentsOf = [];
        foreach (LetterLink::query()->get() as $edge) {
            [$childType, $childId] = $edge->childKey();
            [$parentType, $parentId] = $edge->parentKey();
            $parentsOf[$childType.':'.$childId][] = $parentType.':'.$parentId;
        }

        $candidate = $candidateType.':'.$candidateId;
        $queue = [$type.':'.$id];
        $seen = [$type.':'.$id => true];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($parentsOf[$current] ?? [] as $parent) {
                if ($parent === $candidate) {
                    return true;
                }

                if (! isset($seen[$parent])) {
                    $seen[$parent] = true;
                    $queue[] = $parent;
                }
            }
        }

        return false;
    }
}
