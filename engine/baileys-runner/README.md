# Runner WhatsApp untuk WAG Hub

Runner Node.js ini berjalan di server WAG Hub. Aplikasi klien mengakses API
Laravel WAG Hub, bukan port runner secara langsung.

```bash
cp .env.example .env
# Isi WAG_BAILEYS_TOKEN dengan secret acak yang juga dipasang di Laravel Hub.
npm ci --omit=dev
npm start
```

Memerlukan Node.js 22.14+ dan Git. `npm start` membaca `.env` di folder ini.
Setiap request wajib menggunakan `Authorization: Bearer <WAG_BAILEYS_TOKEN>`;
runner tidak dapat dinyalakan tanpa token. Token ini bukan token aplikasi.

Konfigurasi Laravel Hub:

```dotenv
GATEWAY_ENGINE_DRIVER=wag_hub
WAG_BAILEYS_URL=http://127.0.0.1:3318
WAG_BAILEYS_TOKEN=secret-yang-sama
```

Jalankan `php artisan config:clear`. Endpoint `/api/v1/engine` di Hub kini
mengelola akun provider bawaan dengan ID sesi yang terpisah per aplikasi.
Akun provider **WAG Hub (bawaan)** juga tersedia pada routing `/api/v1/messages`,
bersama WAHA, GOWA, Fonnte, dan WABA. Keduanya menggunakan jurnal runner dan
ledger pengiriman Hub yang sama.

| Variabel runner | Default |
| --- | --- |
| `WAG_BAILEYS_HOST` | `127.0.0.1` |
| `WAG_BAILEYS_PORT` | `3318` |
| `WAG_BAILEYS_TOKEN` | wajib diisi |
| `WAG_BAILEYS_SESSION_ROOT` | `./sessions` |
| `WAG_BAILEYS_JOURNAL_ROOT` | `./whatsapp-messages` |
| `WAG_BAILEYS_LOG_LEVEL` | `info` |

Jalankan satu proses runner per direktori data, sebagai service dengan restart
otomatis. Pertahankan direktori sesi dan jurnal antar-deploy. Jangan menjalankan
beberapa replica di direktori yang sama. Simpan port runner di jaringan privat.

Tes socket, jurnal idempotensi, logout, pemulihan, HTTP, dan autentikasi memakai
fake socket tanpa menghubungi WhatsApp:

```bash
npm test
```

Lihat [panduan aplikasi klien](../../docs/PLUG_AND_PLAY_GUIDE.md).
