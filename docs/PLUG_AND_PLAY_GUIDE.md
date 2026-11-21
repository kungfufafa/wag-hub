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

Every request goes to the v2 Connection API with the same token. List the
application's connections once and keep the `id` of the one to send from:

```bash
curl "$WAG_URL/api/v2/connections" \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H "Accept: application/json"
```

Send through that connection:

```bash
curl -X POST "$WAG_URL/api/v2/connections/$CONNECTION_ID/messages" \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H "Idempotency-Key: order-1" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"to": "6281234567890", "type": "text", "text": "Halo"}'
```

- **Managed number** (`managed_number`): `202 Accepted`. The message is queued
  on the connection's v2 engine session, exactly like
  `POST /api/v2/sessions/{session}/messages`: pinned to that number, never
  switched to another sender, and followed through `GET /api/v2/messages/{id}`
  and the v2 events/webhook. Text, `image`, `document`, and `voice` (with a
  `media_id` from `POST /api/v2/media`) are supported.
- **Provider route** (`provider_route`): sent synchronously through the
  connection's routing policy, including any fallback the operator adds later.
  It never falls back to the application's default or another connection's
  policy: while its own policy is disabled the send is `409
  connection_not_ready`. `201 Created` once a provider accepted it; a delivery
  failure returns the error object together with `data`. Provider routes
  accept `text` only here.

Repeating a request with the same `Idempotency-Key` returns the original
message (even if the connection is no longer ready, or a managed number was
reconnected onto a new session); reusing the key with a different body is
`409 idempotency_conflict`. When the outcome is unknown,
retry with the **same** key.

Client copies: [PHP](examples/php/WagClient.php),
[JavaScript](examples/javascript/wag.js),
[Python](examples/python/wag.py).

Compatibility guarantees: [COMPATIBILITY.md](COMPATIBILITY.md). The full
schema is in [openapi-v2.yaml](openapi-v2.yaml).

## Operator journeys

### Managed WhatsApp number

Create/select App → Connect WhatsApp → Use my WhatsApp number → Scan QR or
pairing code → Ready → Send test → Copy configuration.

A managed number **is** a v2 engine session: it uses one slot of the
application's v2 session capacity and appears in `GET /api/v2/sessions`. WAG
Hub creates no provider account, routing policy, or route key for it. When the
application has no free slot, setup reports `capacity_exceeded`.

### External provider

Create/select App → Connect WhatsApp → Use provider → Select provider → Enter
credentials → Validate → Send test → Ready → Copy configuration.

Each connection gets its own single-step delivery policy. Ordered fallback,
extra providers, and purpose-specific routes remain optional advanced
configuration and are never attached automatically. Provider endpoints must be
public hosts; internal and metadata addresses are refused at setup and again at
send time.

## Application-facing API

All routes live under `/api/v2` (`Authorization: Bearer <WAG_TOKEN>`) and use
the v2 envelope `{"data": ...}`; errors are
`{"message": "...", "error": {"code": "...", "retryable": true|false}}`.

| Action | Request | Ability | Success |
| --- | --- | --- | --- |
| List connections | `GET /api/v2/connections` | `sessions:read` | 200 |
| Create connection | `POST /api/v2/connections` (`Idempotency-Key` required) | `sessions:manage` | 201 |
| Status (QR / pairing code with `sessions:manage`) | `GET /api/v2/connections/{id}` | `sessions:read` | 200 |
| Pair / reconnect | `POST /api/v2/connections/{id}/connect` | `sessions:manage` | 202 managed, 200 provider |
| Retry setup | `POST /api/v2/connections/{id}/retry` | `sessions:manage` | 202 managed, 200 provider |
| Send | `POST /api/v2/connections/{id}/messages` (`Idempotency-Key` required) | `messages:send` | 202 managed, 201 provider |

Create a managed number (QR by default, or `"auth_method": "pairing"` with
`"phone"`):

```json
{"type": "managed_number", "name": "Support", "auth_method": "qr"}
```

Create a provider route:

```json
{"type": "provider_route", "name": "Fonnte",
 "provider": {"driver": "fonnte", "configuration": {"token": "..."}}}
```

Creation is idempotent per `Idempotency-Key`, like `POST /api/v2/sessions`
(a managed number passes the same key to the session it creates). `connect`
and `retry` on a managed number issue a v2 session `start` command; the
response carries the pending `operation` (`retryable: true`) until the runner
confirms it. Poll `GET /api/v2/connections/{id}` for `status`, `qr`, or
`pairing_code`; QR and pairing codes are never stored, are only returned
for the connection's own session, and only to credentials with
`sessions:manage` (like `GET /api/v2/sessions/{id}/auth/qr`): a
`sessions:read` token sees the status without them. A pending `stop`,
`logout` or `delete` reports the connection as `disconnected`.

Connection types: `managed_number`, `provider_route`.

Connection states: `setup_required`, `connecting`, `ready`, `degraded`,
`disconnected`, `error`. Each non-ready state includes `recommended_action`.
A managed number is `ready` only while its session is `WORKING`, freshly
observed, and has no pending operation.

Capabilities (not provider names): `send_text`, `send_image`, `send_document`,
`send_video`, `send_audio`, `number_lookup`, `inbound_messages`,
`delivery_status`.

Error codes: `connection_not_found` (404, also for another application's
connection), `connection_not_ready` (retryable; `error.internal_code` keeps the
engine cause such as `engine_unreachable` or `session_not_working`),
`capability_not_supported`, `capacity_exceeded`, `idempotency_conflict`,
`invalid_idempotency_key`, `invalid_phone`, `providers_failed`,
`provider_outcome_unknown`, `message_expired`.

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

## Advanced: legacy APIs and migration

`/api/v1/*` and `/engine/*` are legacy: they keep their original contracts
until retired (then `410`). `/api/v1/messages` still requires `purpose`,
`mode`, and `route_key` and has no connection fields; a provider route's
`route_key` is its routing policy key if a legacy client must keep using it.

Map legacy native numbers to v2 sessions, then project every v2 session and
default route into connections (safe to repeat):

```bash
php artisan engine:migrate-legacy
php artisan gateway:sync-connections
```

A connection created before the migration for a native number is linked to
its migrated session rather than duplicated. Sessions created directly with
`POST /api/v2/sessions` also appear as managed-number connections.

## Status and retries

- `sent` / `provider_accepted`: WhatsApp/provider menerima pesan; bukan bukti sudah dibaca.
- `failed`: kegagalan definitif; ikuti `retryable` dan kode error.
- `delivery_outcome_unknown` / `outcome_unknown`: jangan membuat idempotency key baru.
- Pengiriman nomor terkelola tidak berpindah pengirim karena fallback.

## Cakupan provider bawaan

Nomor terkelola memakai engine v2: QR/pairing, status, logout, pemulihan sesi
saat restart, pengiriman teks, gambar, dokumen, dan voice dengan idempotensi,
status pesan, serta event/webhook v2. Lihat [ENGINE_V2.md](ENGINE_V2.md).
