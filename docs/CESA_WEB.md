# Integrasi service WhatsApp untuk CESA dan aplikasi lain

WAG Hub berjalan sebagai service HTTP terpisah. Setiap aplikasi mendapat
Client Application dan kredensial sendiri; aplikasi cukup menyimpan URL Hub
dan token. WAHA, koneksi Baileys, penyimpanan sesi WhatsApp, dan kredensial
provider dikelola di server Hub. Aplikasi baru tidak perlu terdaftar di source
code atau menjalankan Node WhatsApp sendiri.

| | Hub API | Engine API |
|---|---|---|
| Konfigurasi klien | `WAG_URL` + `WAG_TOKEN` | `WAG_ENGINE_URL` + `WAG_ENGINE_TOKEN` |
| Base URL | `https://gateway.example.com` | `https://gateway.example.com/api/v1/engine` |
| Ability | `messages:send`, `messages:read`; `numbers:check` bila diperlukan | `engine:use` |
| Pengirim | Akun provider dalam routing policy | Nomor yang ditautkan user lewat QR/pairing |
| Fallback | Provider berikutnya jika gagal definitif | Tetap pada sesi user tersebut |
| Contoh | Notifikasi sistem, pool pengirim, cek nomor | Rekrutmen/HR, CS, aplikasi lain dengan nomor per user |

Kredensial paket integrasi dipisahkan: token Hub tidak memiliki `engine:use`,
token Engine tidak memiliki ability API pesan. Simpan token pada backend.
Aplikasi tetap mengatur user mana yang boleh mengakses setiap sesi; token
Engine mengakses seluruh sesi milik aplikasi itu.

## Persiapan Hub sekali untuk semua aplikasi

1. Deploy Hub beserta database, worker, dan scheduler menurut
   [panduan deployment](DEPLOYMENT_ID.md).
2. Jalankan host WAHA sesuai [panduan engine](../engine/README.md). Engine API
   saat ini memakai WAHA; NOWEB adalah pilihan berbasis Baileys. Host harus
   mendukung beberapa sesi dengan nama berbeda.
3. Buat akun provider WAHA aktif dengan base URL dan API key. Isi
   `GATEWAY_ENGINE_WAHA_SLUG` dengan slug akun host ini, misalnya `waha-primary`.
   Akun ini menjadi host sesi aplikasi; session `default` bukan nomor pengirim
   wajib bagi aplikasi.
4. Tambahkan hostname host ke `GATEWAY_PROVIDER_HTTPS_HOSTS`, atau
   `GATEWAY_PROVIDER_HTTP_HOSTS` bila memakai HTTP internal. Gunakan hostname
   yang bisa di-resolve server Hub. Jalankan `php artisan config:cache` dan
   `php artisan queue:restart` setelah mengubah environment Hub.

Jalur Hub dapat memakai WAHA, Fonnte, GOWA, dan WABA sesuai routing policy.
Lifecycle QR/pairing Engine API saat ini tersedia melalui WAHA; mengganti
backend dengan API yang berbeda memerlukan adapter di Hub.

## Onboarding aplikasi apa pun

1. Buat Client Application aktif di `/panel/client-applications` dengan slug
   unik, misalnya `external-crm`. Tidak perlu memakai nama khusus CESA.
2. Buka **Kredensial API → Buat token Hub & Engine**. Simpan blok environment
   saat ditampilkan; plaintext token tidak dapat dilihat lagi setelah modal
   ditutup.
3. Konfigurasikan backend aplikasi dengan blok berikut:

```dotenv
WAG_URL=https://gateway.example.com
WAG_TOKEN=wgh_token_hub_aplikasi
WAG_ENGINE_URL=https://gateway.example.com/api/v1/engine
WAG_ENGINE_TOKEN=wgh_token_engine_aplikasi
```

Paket generik memberi token Hub ability `messages:send` dan `messages:read`.
Untuk pengecekan nomor, terbitkan credential dengan `numbers:check` melalui
panel. Paket `web-cesa` sudah mencakup ability tersebut. Jika hanya memerlukan
Engine, cukup terbitkan satu credential `engine:use`.

Setiap request Engine memakai header
`Authorization: Bearer <WAG_ENGINE_TOKEN>`. URL tidak memuat token.
Endpoint lama `/engine` (Bearer) dan `/engine/t/{token}` tetap tersedia untuk
kompatibilitas; integrasi baru memakai `/api/v1/engine` dengan Bearer agar token
tidak masuk URL/access log.

## Konfigurasi cesa-web

Gunakan blok generik di atas dan tambahkan:

```dotenv
REKRUTMEN_WHATSAPP_ENGINE_DRIVER=wag_hub
REKRUTMEN_WHATSAPP_ENGINE_URL=${WAG_ENGINE_URL}
REKRUTMEN_WHATSAPP_ENGINE_TOKEN=${WAG_ENGINE_TOKEN}
REKRUTMEN_WHATSAPP_ENGINE_AUTO_START=false
```

`WhatsAppEngineClient` mengirim token lewat Authorization. Konfigurasi
Rekrutmen memakai `WAG_ENGINE_URL` dan `WAG_ENGINE_TOKEN` bila tidak ada override
Rekrutmen. Baris override di atas juga menggantikan URL Node lokal atau URL
`/engine/t/{token}` yang mungkin tersisa pada deployment lama. Perbarui baris
yang sudah ada, lalu jalankan `php artisan config:cache` dan restart worker
CESA. Driver `wag_hub` memakai service remote; mode lokal tetap bisa dipilih
melalui `REKRUTMEN_WHATSAPP_ENGINE_DRIVER=local` beserta konfigurasi lokalnya.

User menghubungkan sesi seperti `rekrutmen-12`, memindai QR atau memasukkan
pairing code, kemudian mengirim dari sesi itu. Sesi aplikasi lain terpisah
meskipun memilih ID publik yang sama.

## Kontrak Engine API

Semua path relatif terhadap `WAG_ENGINE_URL`. Kirim JSON dengan
`Content-Type: application/json` dan header Bearer pada setiap request.

| Method dan path | Payload / hasil |
|---|---|
| `GET /health` | Kesiapan konfigurasi host dan jumlah sesi milik aplikasi peminta. |
| `POST /sessions` | `{ "id": "hr-12", "mode": "qr" }`; mode `pairing` memerlukan `phone`. |
| `GET /sessions/{id}` | `id`, `status`, `mode`, `qr`, `pairing_code`, `phone`, `error`. |
| `DELETE /sessions/{id}` | Logout dan nonaktifkan sesi. Periksa `logout_confirmed`. |
| `POST /sessions/{id}/send` | `{ "phone": "6281234567890", "text": "Halo", "idempotency_key": "invitation-123" }`. |
| `GET /sessions/{id}/messages/{key}` | Status pesan dengan idempotency key tersebut. Encode key sebagai segmen URL. |

ID sesi terdiri dari 2–47 karakter huruf kecil, angka, dan tanda `-`; harus
diawali huruf, tanpa `--` atau `-` di akhir. Respons sesi memakai status
`qr`, `pairing`, `connecting`, `connected`, `disconnected`, atau `unknown`.
`qr` berisi data URI yang dapat ditampilkan sebagai gambar oleh aplikasi.

`/health` memeriksa konfigurasi Hub dan membaca status sesi yang tersimpan.
Hasilnya bukan probe konektivitas live ke WAHA atau jaminan WhatsApp sudah
terhubung. Verifikasi operasional dilakukan dengan membuat sesi, menyelesaikan
pairing, dan mengirim pesan uji ke nomor tujuan yang disiapkan.

Contoh shell dengan `WAG_ENGINE_URL` dan `WAG_ENGINE_TOKEN` sudah di-export:

```bash
curl "$WAG_ENGINE_URL/health" \
  -H "Authorization: Bearer $WAG_ENGINE_TOKEN"

curl -X POST "$WAG_ENGINE_URL/sessions" \
  -H "Authorization: Bearer $WAG_ENGINE_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"id":"hr-12","mode":"qr"}'

# Setelah scan QR, GET sesi sampai status connected.
curl "$WAG_ENGINE_URL/sessions/hr-12" \
  -H "Authorization: Bearer $WAG_ENGINE_TOKEN"

curl -X POST "$WAG_ENGINE_URL/sessions/hr-12/send" \
  -H "Authorization: Bearer $WAG_ENGINE_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"phone":"6281234567890","text":"Pesan uji integrasi","idempotency_key":"smoke-001"}'

curl "$WAG_ENGINE_URL/sessions/hr-12/messages/smoke-001" \
  -H "Authorization: Bearer $WAG_ENGINE_TOKEN"
```

Status pengiriman `sent` berarti provider menerima pesan, bukan konfirmasi
delivered/read. `failed` menunjukkan kegagalan; baca `error_code` dan
`retryable`. `unknown` berarti hasil belum dapat dipastikan: cek status dengan
key yang sama dan jangan otomatis mengirim ulang memakai key baru. Idempotency
key harus stabil per pesan bisnis; pemakaian key yang sama untuk payload
berbeda ditolak. Engine API saat ini hanya mengirim teks. Untuk lampiran dan
routing/fallback, gunakan kontrak [Hub API](../README.md#mengirim-pesan).
