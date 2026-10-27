<?php

namespace App\Services;

use App\Exceptions\LinkTargetNotFoundException;
use App\Models\Document;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Rekonstruksi thread (runutan) surat dari tabel letter_links (fase 1b).
 *
 * Memuat SEMUA edge sekali, menyelesaikan node secara BATCH (dua query
 * whereIn: documents + incoming_letters dengan senderParty di-eager —
 * bukan satu query per node), lalu membangun thread in-memory:
 *
 * 1. Akar: dari node awal, naik ikuti edge parent (node sebagai child)
 *    hingga node tanpa parent — atau parent yang tak teresolusi (baris
 *    surat sudah tidak ada) diperlakukan sebagai batas akar.
 * 2. Keturunan: BFS dari akar; setiap node dikunjungi sekali (semantik
 *    pohon). Edge dengan child yang tak terresolusi dilewati.
 *
 * Urutan keluaran FLAT CHRONOLOGICAL: tanggal efektif naik (document
 * memakai date, incoming memakai letter_date ?? received_at; tanpa
 * tanggal ditaruh paling akhir; tie-break created_at lalu id). Dipilih
 * daripada DFS pre-order karena timeline korespondensi memang dibaca
 * kronologis lintas cabang — balasan dari cabang lain sering lebih
 * dahulu daripada cucu cabang pertama. Field `depth` (jarak dari akar)
 * tetap disertakan agar UI timeline bisa meng-indent ringan bila mau.
 */
class ThreadBuilder
{
    /** Tipe node surat yang sah. */
    private const NODE_TYPES = ['document', 'incoming_letter'];

    /**
     * Bangun thread yang memuat node $type/$id.
     *
     * @param  string  $type  'document' | 'incoming_letter'
     * @param  string  $id  uuid node awal (boleh node mana pun dalam thread)
     * @return array{root: array{type: string, id: string}, nodes: list<array<string, mixed>>, total: int}
     *
     * @throws InvalidArgumentException tipe node tidak dikenal
     * @throws LinkTargetNotFoundException node awal tidak ada pada tabel tipenya
     */
    public function build(string $type, string $id): array
    {
        if (! in_array($type, self::NODE_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                "Tipe node surat tidak dikenal: '%s' (yang sah: document|incoming_letter).",
                $type,
            ));
        }

        // Muat semua edge sekali, urut stabil (created_at, id) — menentukan
        // parent pertama bagi child dengan beberapa edge dan urutan antre BFS.
        $edges = LetterLink::query()->orderBy('created_at')->orderBy('id')->get();

        // Indeks: parent per child (naik ke akar) dan edge child per parent
        // (turun BFS). Kunci gabungan "type:id" karena node lintas jenis.
        $parentEdgeOf = [];
        $childEdgesOf = [];
        $wantedIds = ['document' => [], 'incoming_letter' => []];

        foreach ($edges as $edge) {
            [$parentType, $parentId] = $edge->parentKey();
            [$childType, $childId] = $edge->childKey();

            $parentEdgeOf[$childType.':'.$childId] ??= $edge;
            $childEdgesOf[$parentType.':'.$parentId][] = $edge;

            $wantedIds[$parentType][$parentId] = true;
            $wantedIds[$childType][$childId] = true;
        }

        $wantedIds[$type][$id] = true;

        // Resolusi batch: dua query untuk seluruh thread.
        $documents = Document::query()
            ->whereIn('id', array_keys($wantedIds['document']))
            ->get()
            ->mapWithKeys(fn (Document $doc) => [$doc->id => $doc]);
        $incomings = IncomingLetter::query()
            ->with('senderParty')
            ->whereIn('id', array_keys($wantedIds['incoming_letter']))
            ->get()
            ->mapWithKeys(fn (IncomingLetter $letter) => [$letter->id => $letter]);

        $resolve = function (string $nodeType, string $nodeId) use ($documents, $incomings): ?Model {
            return $nodeType === 'document'
                ? ($documents[$nodeId] ?? null)
                : ($incomings[$nodeId] ?? null);
        };

        if ($resolve($type, $id) === null) {
            throw new LinkTargetNotFoundException(sprintf(
                "Node thread tidak ditemukan: %s '%s' tidak ada pada tabel %s.",
                $type,
                $id,
                $type === 'document' ? 'documents' : 'incoming_letters',
            ));
        }

        // 1. Akar: naik selama ada edge parent DAN parent teresolusi.
        //    Parent yang barisnya sudah tidak ada = batas thread di atas.
        //    Kumpulan seen menjaga bila data edge ternyata melingkar
        //    (invariant dijaga LetterLinkService; ini hanya defensif).
        $root = [$type, $id];
        $climbed = [$type.':'.$id => true];
        while (isset($parentEdgeOf[$root[0].':'.$root[1]])) {
            $candidate = $parentEdgeOf[$root[0].':'.$root[1]]->parentKey();
            $key = $candidate[0].':'.$candidate[1];

            if (isset($climbed[$key]) || $resolve($candidate[0], $candidate[1]) === null) {
                break;
            }

            $climbed[$key] = true;
            $root = $candidate;
        }

        // 2. BFS keturunan dari akar — tiap node sekali, bawa edge yang
        //    dipakai mencapainya (relation_to_parent) dan kedalaman.
        $entries = [];
        $seen = [$root[0].':'.$root[1] => true];
        $queue = [[$root, 0, null]];

        while ($queue !== []) {
            [[$nodeType, $nodeId], $depth, $reachedVia] = array_shift($queue);
            $model = $resolve($nodeType, $nodeId);

            $children = [];
            foreach ($childEdgesOf[$nodeType.':'.$nodeId] ?? [] as $edge) {
                $child = $edge->childKey();
                $key = $child[0].':'.$child[1];

                if (isset($seen[$key]) || $resolve($child[0], $child[1]) === null) {
                    continue;
                }

                $seen[$key] = true;
                $children[] = [$child, $depth + 1, $edge];
            }

            $entries[] = [
                'node' => $this->shapeNode($nodeType, $model, $depth, $reachedVia, count($children)),
                'sort' => $this->sortKey($nodeType, $model),
            ];

            foreach ($children as $entry) {
                $queue[] = $entry;
            }
        }

        // 3. Flat chronological: tanggal efektif naik, tanpa tanggal di
        //    akhir ('9999-…'), tie-break created_at lalu id. Spaceship
        //    pada array membandingkan elemen per elemen.
        usort($entries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $nodes = array_map(fn (array $entry): array => $entry['node'], $entries);

        return [
            'root' => ['type' => $root[0], 'id' => $root[1]],
            'nodes' => $nodes,
            'total' => count($nodes),
        ];
    }

    /**
     * Bentuk node thread yang ringkas untuk UI.
     *
     * @param  LetterLink|null  $reachedVia  edge yang dipakai mencapai node
     *                                       ini dari parent-nya (null = akar)
     * @return array<string, mixed>
     */
    private function shapeNode(string $type, Model $model, int $depth, ?LetterLink $reachedVia, int $childrenCount): array
    {
        if ($type === 'document') {
            /** @var Document $model */
            $number = $model->number !== null && $model->number !== '' ? $model->number : '—';
            $effectiveDate = $model->date;
            $metadata = is_array($model->metadata) ? $model->metadata : [];
            $partyName = $metadata['recipient']['name'] ?? null;
        } else {
            /** @var IncomingLetter $model */
            $number = $model->agenda_number !== null && $model->agenda_number !== '' ? $model->agenda_number : '—';
            $effectiveDate = $model->letter_date ?? $model->received_at;
            $partyName = $model->senderParty?->name;
        }

        return [
            'type' => $type,
            'id' => (string) $model->getKey(),
            'number' => $number,
            'date' => $effectiveDate?->format('Y-m-d'),
            'title' => $model->subject,
            'party_name' => is_string($partyName) ? $partyName : null,
            'status' => $model->status,
            'relation_to_parent' => $reachedVia?->relation,
            'children_count' => $childrenCount,
            'depth' => $depth,
        ];
    }

    /**
     * Kunci urut node: tanggal efektif (null = paling akhir), lalu
     * created_at baris surat, lalu id — deterministik penuh.
     *
     * @return list<string>
     */
    private function sortKey(string $type, Model $model): array
    {
        $effectiveDate = $type === 'document'
            ? $model->date
            : ($model->letter_date ?? $model->received_at);

        return [
            $effectiveDate?->format('Y-m-d') ?? '9999-12-31',
            $model->created_at?->format('Y-m-d H:i:s') ?? '',
            (string) $model->getKey(),
        ];
    }
}
