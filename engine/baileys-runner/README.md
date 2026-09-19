# Standalone Baileys Runner untuk WAG Hub

Runner ini adalah engine Node.js mandiri berbasis **@whiskeysockets/baileys** (WhatsApp Web multi-device) yang berjalan sebagai microservice ringan di sisi `wag-hub`.

Cocok digunakan untuk lingkungan pengujian (development/staging) atau deployment bare metal/VPS kecil tanpa Docker WAHA.

## Cara Menjalankan

```bash
cd engine/baileys-runner
npm ci --omit=dev
npm start
```

Engine akan mendengarkan request HTTP di `http://127.0.0.1:3318` secara default.

## Variabel Environment Opsional

| Variabel | Default | Keterangan |
|---|---|---|
| `WAG_BAILEYS_PORT` | `3318` | Port listener HTTP |
| `WAG_BAILEYS_HOST` | `127.0.0.1` | Host listener HTTP |
| `WAG_BAILEYS_SESSION_ROOT` | `./sessions` | Direktori penyimpanan auth/kredensial WhatsApp |
| `WAG_BAILEYS_LOG_LEVEL` | `info` | Level log pino (`info`, `debug`, `error`) |

## Kontrak Endpoint

- `GET /health`: Cek kesiapan engine
- `POST /sessions`: Memulai sesi baru (`{ id: string, mode: "qr"|"pairing", phone?: string }`)
- `GET /sessions/:id`: Status polling sesi (mengembalikan QR data URI atau pairing code)
- `DELETE /sessions/:id`: Logout dan nonaktifkan sesi
- `POST /sessions/:id/send`: Kirim pesan teks dengan idempotency key
- `GET /sessions/:id/messages/:key`: Cek status pengiriman pesan
