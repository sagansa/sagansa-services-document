# Sagansa Document Service (`document.sagansa.id`)

Microservice Laravel untuk surat perusahaan: pembuatan draft dari template, penomoran per unit, TTE visual + QR verifikasi, e-materai visual tanpa API pihak ketiga, arsip 10 tahun, dan halaman verifikasi publik `/verify/{uuid}` yang menjadi target pemindaian QR pada surat terbit.

## Arsitektur Auth

- **API domain surat** (`/api/v1/*`): dilindungi **Laravel Sanctum** (`auth:sanctum`) **+ gate `document.role`** — token saja tidak cukup, user harus punya baris signer aktif di DB `sagansa_document` (peran direktur/komisaris/pemegang saham).
- **Halaman verifikasi & unduhan publik** (`/verify/*`): tanpa auth — UUID surat yang acak adalah otorisasinya; dilindungi throttle per menit per IP.
- **Unduh pra-final**: tanpa header auth — URL signed 15 menit adalah otorisasinya.

### Token lintas-service
Service ini tidak menerbitkan token sendiri. Ia membaca tabel `personal_access_tokens` di database **`sagansa_user`** (koneksi `mysql_auth`, lihat `config/database.php`). Karena `services/api-ops` membaca tabel yang sama saat user login, **token yang dikeluarkan api-ops langsung valid di sini** — tidak perlu secret bersama, tidak perlu signed URL.

Setup auth meniru persis pola `services/img`:
- `app/Models/User.php`: `use HasApiTokens;` + koneksi `mysql_auth`.
- `config/auth.php`: guard `sanctum`.
- Migration `personal_access_tokens` **tidak ada di service ini** — sudah dikelola oleh `services/migration` di database `sagansa_user`.

## Domain

- **DB `sagansa_document`** (koneksi `mysql_document`, default): seluruh tabel domain surat (documents, issuances, attachments, signers, signing_requests, parties, letter_types, templates, audit_logs, verification_hits) — dimigrasikan oleh `services/migration`, service ini TIDAK menjalankan migration domain.
- **Disk `documents`** (`config/filesystems.php`): disk **private** (local driver, tanpa URL publik), root `storage/app/documents`. Berkas terbit disimpan sebagai `{yyyy}/{docId}/doc-{uuid}.pdf`, lampiran `{yyyy}/{docId}/att-{uuid}.{ext}`. Unduhan hanya lewat controller yang memeriksa status + issuance aktif.
- **Disk `local`** (`storage/app/private`): temp pra-final (`pre-final/{docId}-{ts}.pdf`) — URL signed 15 menit, dihapus setelah diunduh, dan disapu otomatis saat penulisan pra-final baru bila berumur > 15 menit (`PreFinalGarbageCollector`).

## Endpoint API v1 (auth: Sanctum + gate role signer)

| Method | Path | Deskripsi |
|--------|------|-----------|
| `GET` | `/api/user` | Info user token (debug; Sanctum saja, tanpa gate). |
| `GET` | `/api/v1/documents` | Daftar surat paginate 15; filter `?status=&letter_type=&year=&q=`. |
| `POST` | `/api/v1/documents` | Buat draft (`duplicate_from_id` opsional untuk salinan surat terbit). |
| `GET` | `/api/v1/documents/{id}` | Detail (termasuk lampiran, issuances, `body_html` untuk draft\|menunggu_ttd). |
| `PATCH` | `/api/v1/documents/{id}` | Ubah isi — hanya draft\|menunggu_ttd (selain itu 409). |
| `POST` | `/api/v1/documents/{id}/numbering/reserve` | Pesan nomor surat per unit (idempoten untuk draft yang sama). |
| `POST` | `/api/v1/documents/{id}/attachments` | Upload lampiran (multipart `file` pdf/jpg/png/xlsx max 20 MB, `label`). Hanya draft\|menunggu_ttd. |
| `DELETE` | `/api/v1/documents/{id}/attachments/{attachmentId}` | Hapus lampiran. |
| `POST` | `/api/v1/documents/{id}/signers` | Ajukan penandatangan (`{signer_id}`) → status surat `menunggu_ttd`. |
| `GET` | `/api/v1/signing-requests/mine` | Daftar permintaan tanda tangan untuk saya (`?status=diminta|disetujui|ditolak`). |
| `POST` | `/api/v1/signing-requests/{id}/approve` | Setujui (pemilik request saja; 403 selain itu). |
| `POST` | `/api/v1/signing-requests/{id}/reject` | Tolak dengan note. |
| `GET` | `/api/v1/parties` · `POST` · `PATCH /api/v1/parties/{id}` | Buku pihak eksternal (penerima surat). |
| `GET` | `/api/v1/letter-types` | Jenis surat aktif (wizard Buat Surat). |
| `GET` | `/api/v1/templates` | Template per jenis surat (default duluan). |
| `GET` | `/api/v1/me/signer` | Profil signer saya (status gate, `signature_ready`). |
| `GET` | `/api/v1/signers` | Direktori signer aktif (pilih signer komisaris milik user lain). |
| `POST` | `/api/v1/documents/{id}/pre-final` | Pratinjau PDF `{mode:'a'\|'b', pdf_base64?, overlays?}` → URL signed 15 menit. |
| `POST` | `/api/v1/documents/{id}/finalize` | Terbitkan `{method:'a'\|'b'\|'c'\|'d'}` (a render, b stempel, c PDF ttd resmi, d foto multipart `images[]`). |
| `POST` | `/api/v1/documents/{id}/reissue` | Terbitkan ulang surat terbit (`method` + `note` wajib; issuance lama dinonaktifkan). |
| `POST` | `/api/v1/documents/{id}/revoke` | Cabut — hanya jenis KUA + status terbit (`{reason, revoked_at}`). |
| `POST` | `/api/v1/documents/{id}/void` | Batalkan — draft\|menunggu_ttd\|terbit (`{reason}`). |

### Unduhan pra-final (URL signed = otorisasi)

| Method | Path | Deskripsi |
|--------|------|-----------|
| `GET` | `/api/v1/documents/{id}/pre-final/download` | Unduh hasil pra-final TERBARU; tanda tangan hilang/salah/kedaluwarsa → 403; berkas dihapus setelah terkirim. |

### Endpoint publik (tanpa auth, throttle `verify`)

| Method | Path | Deskripsi |
|--------|------|-----------|
| `GET` | `/up` | Health check. |
| `GET` | `/verify/{uuid}` | Halaman verifikasi surat (target QR): status, nomor, metode, SHA-256, hit counter. Non-uuid → 404 netral di route. |
| `GET` | `/verify/{uuid}/file` | Unduh PDF issuance AKTIF (hanya status terbit). |
| `GET` | `/verify/{uuid}/attachments/{attachment}` | Unduh lampiran surat terbit. |

### Contoh curl

```bash
# Terbitkan surat metode a (token bearer = token login dari api-ops)
curl -X POST https://document.sagansa.id/api/v1/documents/<uuid>/finalize \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"method":"a"}'
# → {"success":true,"data":{"issuance":{...},"verify_url":"https://document.sagansa.id/verify/<uuid>","number":"001/SKK/DIR/X/2026"}}

# Verifikasi publik (tanpa token — pindai QR surat)
curl https://document.sagansa.id/verify/<uuid>
```

## Urutan Alur C (TTE resmi + e-materai)

Alur C dipakai bila surat harus ditandatangani secara elektronik resmi (mis. via BSrE) dan dibubuhi e-materai: **stempel dulu, upload terakhir**. Klien memanggil `pre-final` mode b untuk menempelkan QR verifikasi pada PDF (atau `finalize` metode b bila tanpa ttd resmi); hasil pra-final diunduh lewat URL signed, lalu ditandatangani elektronik dan dibubuhi e-materai **di luar sistem** (e-materai di sini adalah gambar visual pada PDF — tanpa integrasi API Peruri), dan baru PDF yang sudah final diunggah kembali via `finalize` metode `c` (`pdf_base64`, **tanpa overlay** — men-stamp setelah tanda tangan resmi ditolak 409 `ALREADY_SIGNED`). Metode `d` adalah varian foto/scan: beberapa gambar dikomposisi menjadi satu PDF. Riwayat metode tercatat per issuance; terbit ulang lewat `reissue` + note alasan.

## Environment Variables (`.env`)

| Var | Wajib | Contoh | Keterangan |
|-----|-------|--------|------------|
| `APP_URL` | ya | `https://document.sagansa.id` | Basis URL service. |
| `APP_ENV` / `APP_DEBUG` | ya | `production` / `false` | Harus begitu di server. |
| `APP_KEY` | ya | (generate) | `php artisan key:generate`. |
| `DB_CONNECTION` | ya | `mysql_document` | Koneksi default (cache/session/queue → `sagansa_document`). |
| `DB_DOCUMENT_*` | ya | — | Koneksi domain surat: host/port/database/username/password → `sagansa_document`. |
| `DB_AUTH_*` | ya | — | Koneksi `sagansa_user` untuk validasi token Sanctum. |
| `DOCUMENT_DOMAIN` | ya | `https://document.sagansa.id` | Basis URL payload QR (`{domain}/verify/{uuid}`) — **tercetak permanen di surat, jangan pernah berubah**. |
| `DOCUMENT_DISK` | tidak | `documents` | Disk penyimpanan berkas (default `documents`). |
| `VERIFY_RATE_LIMIT` | tidak | `30` | Limit request/menit/IP untuk endpoint `/verify/*`. |
| `QPDF_BINARY` | tidak | `qpdf` | Binary qpdf (nama di PATH atau path absolut) untuk normalisasi PDF 1.5+. |

## Dependensi Server

- **PHP ^8.3** dengan ekstensi **`gd`** (WAJIB — `QrBuilder` membuat QR PNG via `GDLibRenderer` bacon/bacon-qr-code; `composer.json` sudah mendeklarasikan `ext-gd` sehingga `composer install` fail-loud bila absen). Cek: `php -m | grep gd`.
- **`qpdf`** (opsional untuk dev, **WAJIB di produksi**) — fallback normalisasi PDF sumber ber-xref stream/object stream (PDF 1.5+) yang tak didukung parser gratis FPDI: `apt install qpdf` (Debian/Ubuntu) atau `brew install qpdf`. Tanpa qpdf, PDF modern ditolak 422 `PDF_UNSUPPORTED`.
- MySQL/MariaDB untuk `sagansa_document` + `sagansa_user` (hanya koneksi — schema dikelola `services/migration`).

## Deploy (Hostinger) & DNS

1. Set DNS `document.sagansa.id` → server; vhost mengarah ke `services/document/public`.
2. `composer install --no-dev --optimize-autoloader`, salin `.env.example` → `.env`, isi tabel env di atas, `php artisan key:generate`.
3. **JANGAN `php artisan migrate` untuk tabel domain** — schema `sagansa_document` dikelola `services/migration`. Migration lokal hanya tabel cache/session/queue di DB default.
4. `php artisan config:cache && php artisan route:cache`.
5. Pastikan `storage/app/documents` TIDAK bisa diakses web (disk private, di luar `public/`) dan eksekusi PHP di-disable di bawah `storage/`.
6. **`/verify/{uuid}` adalah URL PERMANEN yang tercetak pada QR setiap surat terbit** — domain `document.sagansa.id` tidak boleh berpindah/break setelah surat pertama terbit, karena QR lama tetap menunjuk ke sana.

### Queue

Fase 1 memakai `QUEUE_CONNECTION=sync` — satu-satunya job saat ini (`RecordVerificationHitJob`) dieksekusi inline dalam request; volume verifikasi fase 1 masih rendah sehingga worker tidak diperlukan. Bila volume naik: ganti `QUEUE_CONNECTION=database`, pastikan tabel `jobs` ter-migrate (migration lokal service ini, bukan domain `services/migration`), lalu jalankan `php artisan queue:work` di supervisor (autorestart, `--tries=3`).

## Backup

- **Disk `storage/app/documents` WAJIB ikut backup** — berkas PDF terbitan + lampiran TIDAK bisa diregenerasi dari DB (metode c/d menyimpan bytes hasil upload/tanda tangan pihak luar).
- **Retensi 10 tahun** (PRD surat perusahaan); riwayat penerbitan tidak pernah dihapus — issuance lama hanya dinonaktifkan saat reissue, dan revoke/void tidak menghapus berkas. Simpan backup immutable (append-only/object lock) sesuai kebijakan arsip.

## Testing

```bash
php artisan test   # 148 passed, 1 skipped (sqlite in-memory — tanpa MySQL nyata)
```

Koneksi `mysql_auth` & `mysql_document` otomatis ditukar ke sqlite in-memory saat `APP_ENV=testing` (`config/database.php`), jadi CI tidak butuh server MySQL. Test yang butuh binary `qpdf` otomatis skipped bila binary tidak ada (itulah 1 skipped di mesin tanpa qpdf).
