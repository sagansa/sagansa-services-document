<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="author" content="PT SAGANSA">
    <title>Surat {{ $document->number }}</title>
    <style>
        /*
         | Template dasar surat resmi (dompdf) — hanya tag/CSS yang
         | didukung dompdf: tabel, blok, float tidak dipakai, tanpa
         | flexbox/grid. Font base-14 Helvetica agar teks tetap
         | ter-embed ringan (tidak subset TTF).
         */
        @page { margin: 16mm 15mm 20mm 15mm; }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11pt;
            color: #1a1a1a;
            line-height: 1.45;
        }

        /* ---------- Kop surat ---------- */
        .kop { text-align: center; }
        .kop-name { font-size: 16pt; font-weight: bold; letter-spacing: 1pt; }
        .kop-sub { font-size: 9pt; color: #444444; }
        .kop-line-bold { border-bottom: 0.8mm solid #1a1a1a; }
        .kop-line-thin { border-bottom: 0.3mm solid #1a1a1a; margin-top: 0.6mm; margin-bottom: 5mm; }

        /* ---------- Kepala surat ---------- */
        table.head { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        td.head-label { width: 22mm; }
        td.head-sep { width: 3mm; }
        td.head-date { width: 40mm; text-align: right; vertical-align: top; }

        /* ---------- Isi surat ---------- */
        .body { text-align: justify; }

        /* ---------- Blok tanda tangan ---------- */
        table.ttd { width: 100%; border-collapse: collapse; margin-top: 10mm; }
        td.ttd-qr { width: 40mm; vertical-align: top; }
        td.ttd-gap { width: 35mm; }
        td.ttd-signers { width: 105mm; vertical-align: top; }
        .ttd-city { text-align: right; margin-bottom: 2mm; }
        .qr-img {
            width: 25mm;
            height: 25mm;
            border: 0.3mm solid #666666;
        }
        .qr-label { font-size: 7pt; color: #444444; width: 32mm; margin-top: 1mm; }
        table.signers { border-collapse: collapse; width: 100%; }
        td.signer { padding: 0 3mm; text-align: center; vertical-align: top; }
        td.slot {
            width: 25mm;
            height: 25mm;
            border: 0.3mm solid #999999;
            text-align: center;
            vertical-align: middle;
        }
        td.slot img.ttd-img { vertical-align: middle; }
        .signer-name { font-weight: bold; margin-top: 1.5mm; }
        .signer-title { font-size: 10pt; }
    </style>
</head>
<body>

    {{-- ================= KOP SURAT ================= --}}
    {{-- TODO: ganti dengan kop resmi (aset resmi per PRD §14) --}}
    <div class="kop">
        <div class="kop-name">PT SAGANSA</div>
        <div class="kop-sub">Jl. Contoh Alamat No. 123, Jakarta 10110, Indonesia</div>
        <div class="kop-sub">corporate@sagansa.id &middot; www.sagansa.id</div>
    </div>
    <div class="kop-line-bold"></div>
    <div class="kop-line-thin"></div>

    {{-- ================= KEPALA SURAT ================= --}}
    <table class="head">
        <tr>
            <td>
                <table>
                    <tr>
                        <td class="head-label">Nomor</td>
                        <td class="head-sep">:</td>
                        <td>{{ $document->number }}</td>
                    </tr>
                    <tr>
                        <td class="head-label">Lampiran</td>
                        <td class="head-sep">:</td>
                        <td>{{ $attachmentsCount }} berkas</td>
                    </tr>
                    <tr>
                        <td class="head-label">Hal</td>
                        <td class="head-sep">:</td>
                        <td>{{ $document->subject }}</td>
                    </tr>
                </table>
            </td>
            <td class="head-date">
                {{-- locale id eksplisit: bulan harus "Oktober" meski
                     APP_LOCALE di .env masih en --}}
                {{ $document->date->locale('id')->translatedFormat('d F Y') }}
            </td>
        </tr>
    </table>

    {{-- ================= ISI SURAT ================= --}}
    {{-- Raw: konten dari editor internal aplikasi sendiri --}}
    <div class="body">
        {!! $document->body_html !!}
    </div>

    {{-- ================= BLOK TANDA TANGAN ================= --}}
    {{-- TODO: ganti kota placeholder dengan kota domisili resmi --}}
    <table class="ttd">
        <tr>
            <td class="ttd-qr">
                <img class="qr-img" src="data:image/png;base64,{{ $qrPng }}" alt="QR verifikasi">
                <div class="qr-label">Pindai untuk verifikasi keaslian surat</div>
            </td>
            <td class="ttd-gap"></td>
            <td class="ttd-signers">
                <div class="ttd-city">Jakarta, {{ $document->date->locale('id')->translatedFormat('d F Y') }}</div>
                <table class="signers">
                    {{-- Kotak TTD baris atas, nama+jabatan baris bawah: kolom
                         satu tabel selalu sejajar, multiple signer berdampingan --}}
                    <tr>
                        @foreach($signers as $signer)
                            @php($signature = $signer->signaturePreview())
                            <td class="slot">
                                @if($signature !== null)
                                    {{-- Aset TTD tersimpan (fase 1c): rasio asli
                                         dipertahankan di dalam kotak 25×25mm. --}}
                                    <img
                                        class="ttd-img"
                                        src="{{ $signature['uri'] }}"
                                        alt="Tanda tangan {{ $signer->display_name }}"
                                        style="width: {{ $signature['width'] }}mm; height: {{ $signature['height'] }}mm;"
                                    >
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        @foreach($signers as $signer)
                            <td class="signer">
                                <div class="signer-name">{{ $signer->display_name }}</div>
                                @if($signer->title)
                                    <div class="signer-title">{{ $signer->title }}</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </table>

</body>
</html>
