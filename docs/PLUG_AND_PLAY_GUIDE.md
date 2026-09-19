# WAG Hub: provider WhatsApp dan router mandiri

WAG Hub mengurus koneksi WhatsApp, QR/pairing, logout, penyimpanan sesi, dan
pengiriman. CESA, DND, atau aplikasi lain cukup memanggil HTTP API dari backend.
Masing-masing aplikasi mempunyai token sendiri; ID sesi yang sama pada dua
aplikasi tetap mengarah ke perangkat dan jurnal pesan yang berbeda.

WAG Hub memiliki provider bawaan berbasis Baileys. Provider ini memakai alur
pengiriman, ledger, dan routing yang sama dengan WAHA, GOWA, Fonnte, dan WABA.
UI CESA menggunakan API Hub untuk mengelola nomor; aplikasi lain dapat memakai
kontrak yang sama tanpa bergantung pada modul Rekrutmen.

## 1. Siapkan service sekali di WAG Hub

Siapkan provider bawaan di server WAG Hub:

```bash
cd engine/baileys-runner
cp .env.example .env
# Isi WAG_BAILEYS_TOKEN dengan secret acak: openssl rand -hex 32
npm ci --omit=dev
npm start
```

Gunakan Node.js 22.14+ dan Git. Runner mendengarkan `127.0.0.1:3318` dan
membutuhkan Bearer token untuk semua endpoint. Isi `.env` Laravel WAG Hub:

```dotenv
GATEWAY_ENGINE_DRIVER=wag_hub
WAG_BAILEYS_URL=http://127.0.0.1:3318
WAG_BAILEYS_TOKEN=secret-yang-sama-dengan-runner
```

Jalankan `php artisan config:clear`. Jalankan runner sebagai service yang
restart otomatis di server WAG Hub, dengan direktori `sessions` dan
`whatsapp-messages` yang persisten. Satu proses runner per direktori data.
Jika runner berada di host lain, atur host/URL internal dan koneksi jaringan
privatnya. Secret runner berbeda dari token aplikasi dan hanya dipakai Hub.

`GATEWAY_ENGINE_DRIVER` hanya menentukan provider untuk **sesi aplikasi baru**.
Default-nya `wag_hub`; nilai lama `baileys` masih diterima sebagai alias.
Sesi WAHA yang sudah ada tetap memakai WAHA, termasuk setelah default berubah.
Untuk sesi aplikasi baru melalui WAHA, gunakan `GATEWAY_ENGINE_DRIVER=waha` dan
`GATEWAY_ENGINE_WAHA_SLUG` yang menunjuk akun host WAHA. Provider bawaan tetap
bisa dipakai untuk routing pada saat yang sama; pilihan ini tidak mematikan
provider lain. Sesi lama tidak dipindahkan otomatis antarengine.

## 2. Hubungkan setiap aplikasi

1. Buka **Aplikasi Klien** di dashboard WAG Hub, buat `cesa-web` atau `dnd-web`.
2. Pada kredensial aplikasi, klik **Hubungkan aplikasi**.
3. Salin blok yang ditampilkan ke backend aplikasi:

```dotenv
WAG_URL=https://gateway.example.com
WAG_TOKEN=token-khusus-aplikasi-ini
```

Token memberi izin pengelolaan sesi, pengiriman, pembacaan status, dan validasi
nomor. Token hanya ditampilkan sekali. Token lama tetap berlaku; kredensial
custom dengan izin terbatas masih dapat dibuat bila diperlukan.

**CESA:** jalankan `php artisan config:clear`, `php artisan queue:restart`, lalu
`php artisan wag:status`. Hubungkan nomor di pengaturan WhatsApp Rekrutmen.
Hapus override engine lama yang tidak dipakai; lihat [migrasi CESA](CESA_WEB.md).

**DND/aplikasi baru:** panggil endpoint berikut dari backend dengan
`Authorization: Bearer <WAG_TOKEN>`, `Accept: application/json`, dan
`Content-Type: application/json`. Base URL: `<WAG_URL>/api/v1/engine`.

| Aksi | Metode dan path | JSON |
| --- | --- | --- |
| Cek layanan | `GET /health` | — |
| Login QR | `POST /sessions` | `{"id":"support-1","mode":"qr"}` |
| Login pairing | `POST /sessions` | `{"id":"support-1","mode":"pairing","phone":"6281234567890"}` |
| Status/QR/pairing | `GET /sessions/support-1` | — |
| Logout | `DELETE /sessions/support-1` | `{"logout":true}` |
| Kirim | `POST /sessions/support-1/send` | `{"phone":"6281234567890","text":"Halo","idempotency_key":"order-123"}` |
| Status kirim | `GET /sessions/support-1/messages/order-123` | — |

Setelah login, polling status hingga `connected`. Tampilkan `qr` (data URI)
atau `pairing_code` pada UI aplikasi. Simpan ID sesi sebagai pilihan nomor
pengirim di aplikasi. ID harus 2–47 karakter, diawali huruf kecil, hanya huruf
kecil/angka/hyphen, tanpa dua hyphen berturut-turut atau hyphen di akhir.
`logout_confirmed=false` berarti pengguna perlu mengecek Perangkat Tertaut di HP.

## 3. Gunakan WAG Hub sebagai router

1. Di **Akun Provider**, pilih **WAG Hub (bawaan)** dan simpan akun. Tidak perlu
   URL atau token provider tambahan. Tautkan nomor di **Perangkat WhatsApp**.
2. Tambahkan akun WAHA, GOWA, Fonnte, atau WABA jika diperlukan. Daftarkan
   hostname eksternalnya pada allowlist provider sesuai README.
3. Buat **Aturan Rute** untuk aplikasi dan susun prioritas, misalnya WAG Hub
   bawaan → Fonnte, atau WAHA → WAG Hub bawaan.
4. Kirim ke `/api/v1/messages` dengan `Idempotency-Key`, `route_key`, dan
   `purpose`. Contoh payload lengkap ada di [README](../README.md#mengirim-pesan).

Sesi yang dibuat dari UI aplikasi juga tercatat sebagai akun provider milik
aplikasi tersebut. Kirim melalui `/api/v1/engine/sessions/{id}/send` selalu
terikat ke nomor yang dipilih dan tidak berpindah nomor akibat fallback.
Administrator mengatur pool provider untuk pengiriman lewat `/api/v1/messages`.

Cek nomor memakai aturan rute terpisah dengan jenis **Cek nomor WhatsApp**,
melalui `/api/v1/number-checks` (kontrak lengkap) atau `/api/v1/numbers/check`
(alias ringkas). Provider bawaan mendukung pengecekan dari sesi yang terhubung.

## 4. Contoh Laravel untuk aplikasi apa pun

Simpan env hanya di file konfigurasi agar tetap bekerja dengan `config:cache`:

```php
// config/wag.php
return ['url' => env('WAG_URL'), 'token' => env('WAG_TOKEN')];
```

```php
use Illuminate\Support\Facades\Http;

$base = rtrim(config('wag.url'), '/').'/api/v1/engine';
$session = Http::withToken(config('wag.token'))->acceptJson()->timeout(25)
    ->post($base.'/sessions', ['id' => 'support-1', 'mode' => 'qr'])
    ->throw()->json();
```

CESA sudah menyediakan `App\Services\WhatsApp\WagHubClient` dan
`WagHubEngineClient` yang tidak bergantung pada Rekrutmen. Aplikasi lain dapat
mengikuti contoh HTTP yang sama; tidak perlu memasang engine atau plugin CESA.

## Status dan pengiriman ulang

- `sent`: WhatsApp/provider menerima pesan; bukan bukti sudah dibaca penerima.
- `failed`: kegagalan definitif; ikuti `retryable` dan `error_code`.
- `unknown` atau timeout: cek status memakai sesi dan idempotency key yang sama.
  Jangan membuat key baru untuk mengulang pesan yang hasilnya belum pasti.
- `404 message_not_found`: jurnal belum ditemukan, bukan bukti pesan pasti gagal.

Kontrak dan jurnal mempertahankan hasil kirim dengan key yang sama. Simpan key
per pesan bisnis. Pengiriman dari suatu sesi selalu menggunakan nomor sesi itu.
Jika runner terlambat mengonfirmasi kiriman, pembacaan status merekonsiliasi
ledger Hub dari jurnal runner tanpa mengirim ulang. Hasil ambigu menghentikan
fallback; hanya kegagalan pasti sebelum terkirim yang boleh lanjut ke provider
berikutnya.

## Cakupan provider bawaan

Tersedia: QR/pairing, status, logout, pemulihan sesi saat restart, pengiriman
teks dengan idempotensi, pemeriksaan status pesan, dan cek registrasi nomor.
Lampiran serta webhook pesan masuk belum diimplementasikan pada runner bawaan.
Gunakan provider eksternal yang sesuai untuk fitur tersebut. Ini bukan klaim
kesetaraan seluruh fitur WAHA/GOWA. Uji scan QR dan pengiriman pada nomor uji
sebelum mengaktifkan nomor operasional.
