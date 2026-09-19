# WAG Hub: plug and play WhatsApp

A consuming application only needs three concepts:

**App → WhatsApp Connection → Message**

The application integrates with `WAG_URL`, `WAG_TOKEN`, and one send call.
Provider accounts, engine drivers, routing policies, route keys, and session
implementation stay inside WAG Hub.

## Happy path

1. Create or select an **App**.
2. Open **Hubungkan WhatsApp**.
3. Choose **Pakai nomor WhatsApp saya** or **Pakai provider**.
4. Scan QR / enter a pairing code, or enter provider credentials.
5. Send a test message.
6. Copy integration configuration.

```dotenv
WAG_URL=https://gateway.example.com
WAG_TOKEN=wgh_token-aplikasi-ini
```

```bash
curl -X POST "$WAG_URL/api/v1/messages" \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H "Idempotency-Key: order-1" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": {"type": "phone", "value": "081234567890"},
    "message": {"type": "text", "text": "Halo"}
  }'
```

The default connection is used when `connection_id` is omitted. Managed
numbers stay pinned to that sender. Routed connections may later add fallback
without changing application code.

Client copies: [PHP](examples/php/WagClient.php),
[JavaScript](examples/javascript/wag.js),
[Python](examples/python/wag.py).

Compatibility guarantees: [COMPATIBILITY.md](COMPATIBILITY.md).

## Operator journeys

### Managed WhatsApp number

Create/select App → Connect WhatsApp → Use my WhatsApp number → Scan QR or
pairing code → Ready → Send test → Copy configuration.

WAG Hub provisions the engine session and a pinned delivery path
automatically. You do not create a provider account, routing policy, or route
key for this path.

### External provider

Create/select App → Connect WhatsApp → Use provider → Select provider → Enter
credentials → Validate → Ready → Send test → Copy configuration.

A single-step default delivery strategy is created. Ordered fallback, extra
providers, and purpose-specific routes remain optional advanced configuration.

## Application-facing API

| Action | Request |
| --- | --- |
| List connections | `GET /api/v1/connections` |
| Create connection | `POST /api/v1/connections` |
| Connection status / QR | `GET /api/v1/connections/{id}` |
| Pair / reconnect | `POST /api/v1/connections/{id}/connect` |
| Retry setup | `POST /api/v1/connections/{id}/retry` |
| Send | `POST /api/v1/messages` |
| Send via connection | `POST /api/v1/connections/{id}/messages` |

Connection types: `managed_number`, `provider_route`.

Connection states: `setup_required`, `connecting`, `ready`, `degraded`,
`disconnected`, `error`. Each non-ready state includes `recommended_action`.

Capabilities (not provider names): `send_text`, `send_image`, `send_document`,
`send_video`, `send_audio`, `number_lookup`, `inbound_messages`,
`delivery_status`.

Application errors: `connection_not_ready`, `authentication_failed`,
`recipient_invalid`, `capability_not_supported`, `message_expired`,
`rate_limited`, `delivery_failed`, `delivery_outcome_unknown`.

## Advanced: engine runner (managed numbers)

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
`GATEWAY_ENGINE_WAHA_SLUG` yang menunjuk akun host WAHA.

## Advanced: existing engine and routing APIs

Existing `/api/v1/engine` and `/api/v1/messages` clients keep working. Engine
sends stay pinned to the selected session. Routed `/api/v1/messages` calls
that still send `route_key` and `purpose` use the routing engine unchanged.

Project existing sessions and default routes into connections:

```bash
php artisan gateway:sync-connections
```

## Status and retries

- `sent` / `provider_accepted`: WhatsApp/provider menerima pesan; bukan bukti sudah dibaca.
- `failed`: kegagalan definitif; ikuti `retryable` dan kode error.
- `delivery_outcome_unknown` / `outcome_unknown`: jangan membuat idempotency key baru.
- Pengiriman nomor terkelola tidak berpindah pengirim karena fallback.

## Cakupan provider bawaan

Tersedia: QR/pairing, status, logout, pemulihan sesi saat restart, pengiriman
teks dengan idempotensi, pemeriksaan status pesan, dan cek registrasi nomor.
Lampiran serta webhook pesan masuk belum diimplementasikan pada runner bawaan.
Gunakan provider eksternal yang sesuai untuk fitur tersebut.
