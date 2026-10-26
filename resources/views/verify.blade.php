<!DOCTYPE html>
{{-- Halaman verifikasi publik (Task 17) — target pemindaian QR
     "{domain}/verify/{uuid}". Mandiri: satu berkas, CSS inline, nol JS,
     tanpa framework, ringan untuk ponsel. Tiga state: found / not_found
     (404 netral) / forbidden (403 unduhan rahasia). --}}
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@if ($state === 'found') Verifikasi Dokumen — {{ $badge['label'] }} @elseif ($state === 'forbidden') Unduhan Tidak Tersedia @else Dokumen Tidak Ditemukan @endif · PT Sagansa</title>
    <style>
        :root {
            --ink: #1e293b;
            --muted: #64748b;
            --line: #e2e8f0;
            --bg: #f1f5f9;
            --card: #ffffff;
            --accent: #0f4c81;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .wrap {
            max-width: 680px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            color: var(--muted);
            font-size: 14px;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .brand .mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            font-size: 15px;
            letter-spacing: 0;
        }
        h1 {
            margin: 0 0 20px;
            font-size: 22px;
            line-height: 1.3;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }
        .badge {
            display: inline-block;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: .04em;
        }
        .badge--sah { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge--dibatalkan { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .badge--dicabut { background: #ffedd5; color: #c2410c; border: 1px solid #fdba74; }
        .badge--belum { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 18px; }
        .status-note {
            margin: 12px 0 0;
            color: var(--ink);
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 14px 20px;
        }
        .grid dt {
            margin: 0;
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
        }
        .grid dd {
            margin: 2px 0 0;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .card h2 {
            margin: 0 0 12px;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
        }
        .metode { margin: 0; font-weight: 600; }
        .terbit-row { margin: 6px 0 0; color: var(--ink); }
        .hash {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            color: var(--muted);
            cursor: help;
        }
        .btn {
            display: inline-block;
            margin-top: 14px;
            padding: 9px 16px;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }
        .btn:hover { background: #0d3f6b; }
        ul.riwayat, ul.lampiran { list-style: none; margin: 0; padding: 0; }
        .riwayat-item {
            padding: 10px 0;
            border-top: 1px solid var(--line);
        }
        .riwayat-item:first-child { border-top: 0; padding-top: 0; }
        .riwayat-item .ket { color: var(--muted); font-size: 14px; }
        .chip {
            display: inline-block;
            padding: 1px 10px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .chip--berlaku { background: #dcfce7; color: #15803d; }
        .chip--digantikan { background: #f1f5f9; color: #64748b; }
        .lampiran-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 9px 0;
            border-top: 1px solid var(--line);
        }
        .lampiran-item:first-child { border-top: 0; padding-top: 0; }
        .lampiran-item a { color: var(--accent); font-weight: 600; text-decoration: none; white-space: nowrap; }
        .lampiran-item a:hover { text-decoration: underline; }
        .kosong { margin: 0; color: var(--muted); }
        .rahasia {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 10px;
            border-radius: 6px;
            background: #fef9c3;
            border: 1px solid #fde047;
            color: #854d0e;
            font-size: 13.5px;
            font-weight: 600;
        }
        footer {
            margin-top: 26px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }
        .ikon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 12px;
        }
        .ikon--netral { background: #f1f5f9; color: #64748b; }
        .ikon--larang { background: #ffedd5; color: #c2410c; }
    </style>
</head>
<body>
<main class="wrap">
    <div class="brand"><span class="mark">S</span> PT Sagansa · Verifikasi Dokumen</div>

    @if ($state === 'not_found')
        <h1>Dokumen tidak ditemukan</h1>
        <div class="card">
            <div class="ikon ikon--netral" aria-hidden="true">&#8263;</div>
            <p class="status-note">
                Tautan verifikasi tidak valid atau dokumen tidak tersedia.
                Pastikan Anda memindai QR pada dokumen resmi PT Sagansa
                secara utuh, atau hubungi pengeluar surat untuk tautan yang benar.
            </p>
        </div>

    @elseif ($state === 'forbidden')
        <h1>Unduhan tidak tersedia</h1>
        <div class="card">
            <div class="ikon ikon--larang" aria-hidden="true">&#9940;</div>
            <p class="status-note">
                Dokumen ini terklasifikasi <strong>rahasia</strong> sehingga
                berkasnya tidak dapat diunduh melalui halaman publik.
                Silakan hubungi PT Sagansa untuk salinan resmi.
            </p>
        </div>

    @else
        <h1>Hasil Verifikasi Dokumen</h1>

        {{-- Kartu status — hal pertama yang dibaca pemindai QR --}}
        <section class="card" aria-label="Status dokumen">
            <span class="badge badge--{{ $badge['class'] }}">{{ $badge['label'] }}</span>
            <p class="status-note">{{ $badge['note'] }}</p>
        </section>

        {{-- Informasi umum --}}
        <section class="card" aria-label="Informasi dokumen">
            <h2>Informasi Dokumen</h2>
            <dl class="grid">
                <div>
                    <dt>Nomor Surat</dt>
                    <dd>{{ $number ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Jenis Surat</dt>
                    <dd>{{ $typeName ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Tanggal Surat</dt>
                    <dd>{{ $letterDate }}</dd>
                </div>
                <div>
                    <dt>Perihal</dt>
                    <dd>
                        @if ($secret)
                            <span class="rahasia">Dokumen terklasifikasi rahasia</span>
                        @elseif ($subject === null)
                            —
                        @else
                            {{ $subject }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>Penandatangan</dt>
                    <dd>@foreach ($signers as $signer){{ $loop->first ? '' : ', ' }}{{ $signer }}@endforeach</dd>
                </div>
            </dl>
        </section>

        {{-- Terbitan aktif: metode jujur + tanggal + hash pendek --}}
        @if ($activeIssuance !== null)
            <section class="card" aria-label="Terbitan berlaku">
                <h2>Terbitan Berlaku</h2>
                <p class="metode">{{ $activeIssuance['methodText'] }}</p>
                <p class="terbit-row">Diterbitkan: {{ $activeIssuance['issuedAt'] }}</p>
                <p class="terbit-row">
                    Sidik jari berkas:
                    <code class="hash" title="{{ $activeIssuance['hashFull'] }}">{{ $activeIssuance['hashShort'] }}…</code>
                </p>
                @if ($fileUrl !== null)
                    <a class="btn" href="{{ $fileUrl }}">Unduh salinan (PDF)</a>
                @endif
            </section>
        @endif

        {{-- Riwayat penerbitan (terbaru duluan) --}}
        @if ($history !== [])
            <section class="card" aria-label="Riwayat penerbitan">
                <h2>Riwayat Penerbitan</h2>
                <ul class="riwayat">
                    @foreach ($history as $item)
                        <li class="riwayat-item">
                            <strong>{{ $item['date'] }}</strong>
                            — {{ $item['methodText'] }}
                            — <span class="chip chip--{{ $item['state'] }}">{{ $item['state'] === 'berlaku' ? 'berlaku' : 'digantikan' }}</span>
                            @if ($item['note'] !== null && $item['note'] !== '')
                                <span class="ket">Alasan penggantian: {{ $item['note'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Lampiran --}}
        @if (! $secret)
            @if ($attachments !== [])
                <section class="card" aria-label="Lampiran">
                    <h2>Lampiran</h2>
                    <ul class="lampiran">
                        @foreach ($attachments as $item)
                            <li class="lampiran-item">
                                <span>{{ $item['label'] }}</span>
                                @if ($item['url'] !== null)
                                    <a href="{{ $item['url'] }}">Unduh</a>
                                @else
                                    <span class="ket">Tidak tersedia</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @else
            <section class="card" aria-label="Lampiran">
                <h2>Lampiran</h2>
                <p class="kosong">Unduhan tidak tersedia untuk dokumen terklasifikasi rahasia.</p>
            </section>
        @endif
    @endif

    <footer>Diverifikasi otomatis oleh sistem surat PT Sagansa — document.sagansa.id</footer>
</main>
</body>
</html>
