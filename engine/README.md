# Engine self-hosted (WAHA / Baileys NOWEB)

Selain provider pihak ketiga (Fonnte, GOWA, WABA), Hub bisa memakai **engine
WhatsApp milik sendiri**: WAHA menjalankan engine **NOWEB** yang berbasis
**Baileys** (WhatsApp Web multi-device). Anda menautkan nomor sendiri dengan
memindai QR dari panel Hub (**Konfigurasi → Perangkat WhatsApp**), lalu kirim/
terima lewat **Inbox** yang sama seperti provider lain.

Karena driver `waha` di Hub sudah lengkap (kirim, inbox reader, webhook),
"engine sendiri" = satu **Akun Provider** dengan driver **WAHA** yang menunjuk
ke WAHA milik Anda.

## Produksi — jalankan WAHA (Baileys)

```bash
cp engine/.env.example engine/.env      # isi WAHA_API_KEY & dashboard creds
docker compose -f engine/docker-compose.yml up -d
```

Lalu di Hub:

1. **Konfigurasi → Akun Provider → Tambah**, driver **WAHA**:
   - Base URL: `http://<host>:3000`
   - Session: `default`
   - API key: nilai `WAHA_API_KEY`
2. Tambahkan host engine ke allowlist di `.env` Hub:
   `GATEWAY_PROVIDER_HTTP_HOSTS=<host>` (atau `GATEWAY_PROVIDER_HTTPS_HOSTS` jika HTTPS),
   lalu `php artisan config:cache`.
3. Buka **Perangkat WhatsApp**, klik **Hubungkan**, dan pindai QR dengan
   WhatsApp (Setelan → Perangkat tertaut → Tautkan perangkat).

Webhook pesan masuk dikonfigurasi otomatis oleh Hub saat sesi dimulai
(`{APP_URL}/webhooks/whatsapp/{uuid}`).

> Baileys/WA Web bersifat tidak resmi. Cocok untuk percakapan bisnis/CS, bukan
> trafik OTP/volume tinggi (risiko banned). OTP tetap disarankan lewat WABA resmi.

Panel yang ingin **nomor ditautkan user** (Rekrutmen/HR) memakai `/engine`
dengan token `engine:use`, bukan `WAG_URL`/`WAG_TOKEN`. Satu akun user = satu
sesi WAHA. Panduan dua jalur: [docs/CESA_WEB.md](../docs/CESA_WEB.md).

## Dev / test — mock engine

`mock-waha/` adalah engine tiruan kompatibel-WAHA untuk mengembangkan dan
mendemokan alur pairing **tanpa** Docker atau WhatsApp asli.

```bash
cd engine/mock-waha
npm install
PORT=3999 npm start
```

Endpoint yang ditiru: `POST /api/sessions`, `/api/sessions/{name}/{start,stop,logout,restart}`,
`GET /api/sessions/{name}`, `GET /api/{name}/auth/qr`, `POST /api/sendText`,
plus helper demo `POST /_mock/scan/{name}` (mensimulasikan HP memindai QR →
sesi `WORKING` dan mengirim satu pesan masuk).
