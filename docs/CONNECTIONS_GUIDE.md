# WAG Hub Connections — panduan integrasi singkat

WAG Hub kini memakai model mental **App → WhatsApp Connection → Message**.

Aplikasi klien cukup memahami:

- `WAG_URL`
- `WAG_TOKEN`
- (opsional) `WAG_CONNECTION_ID` untuk koneksi default

## Happy path operator

1. Buka **Aplikasi Klien** → buat/pilih aplikasi.
2. Buka **Koneksi WhatsApp** → **Hubungkan WhatsApp**.
3. Pilih **Gunakan nomor WhatsApp saya** atau **Gunakan provider eksternal**.
4. Selesaikan QR/pairing atau isi kredensial provider.
5. Kirim **pesan uji** dari halaman detail koneksi.
6. Salin blok integrasi (`WAG_URL`, `WAG_TOKEN`, `WAG_CONNECTION_ID`).

Provider account, routing policy, dan route key dibuat otomatis di belakang layar.

## Happy path developer

**PHP (in-repo helper)**

```php
use App\Support\WagClient;

$wag = WagClient::fromEnv();

$wag->messages()->send(
    recipient: '6281234567890',
    text: 'Halo dari WAG Hub',
    idempotencyKey: 'order-123',
);
```

**Node.js** — lihat `sdk/node/README.md`

```javascript
import { WagClient } from '@wag-hub/client';

const wag = WagClient.fromEnv();
await wag.messages().send({
  recipient: '6281234567890',
  text: 'Halo dari WAG Hub',
  idempotencyKey: 'order-123',
});
```

Tanpa `WAG_CONNECTION_ID`, set header `X-WAG-Use-Default-Connection: true` atau kirim `connection_id` eksplisit.

## API

| Endpoint | Deskripsi |
| --- | --- |
| `GET /api/v1/connections` | Daftar koneksi aplikasi |
| `POST /api/v1/connections` | Buat koneksi (managed / provider) |
| `GET /api/v1/connections/{id}` | Detail + status + setup (QR/pairing) |
| `POST /api/v1/connections/{id}/setup` | Mulai QR/pairing atau validasi provider |
| `POST /api/v1/connections/{id}/test` | Kirim pesan uji |
| `GET /api/v1/connections/{id}/integration` | Contoh konfigurasi integrasi |
| `GET /api/v1/connections/{id}/fallbacks` | Daftar urutan provider (lanjutan) |
| `POST /api/v1/connections/{id}/fallbacks` | Tambah provider fallback |
| `POST /api/v1/messages` | Kirim pesan (`connection_id` opsional) |

## Migrasi deployment lama

Jika Anda sudah punya provider account dan routing policy dari setup sebelumnya:

```bash
php artisan gateway:backfill-connections
php artisan gateway:backfill-connections --dry-run
php artisan gateway:backfill-connections --application=3
```

## Backward compatibility

- `/api/v1/engine/*` tetap tersedia untuk sesi per-nomor.
- `/api/v1/messages` dengan `route_key` tetap berperilaku sama bila `connection_id` tidak dikirim.
- Semua invariant keamanan dan reliabilitas (idempotency, outcome_unknown, circuit breaker, audit) tetap berlaku.

## Status koneksi

`setup_required` · `connecting` · `ready` · `degraded` · `disconnected` · `error`

Setiap status non-ready menyertakan `next_action` yang dapat ditindaklanjuti aplikasi klien.

## Error aplikasi

`connection_not_ready` · `authentication_failed` · `recipient_invalid` · `capability_not_supported` · `message_expired` · `rate_limited` · `delivery_failed` · `delivery_outcome_unknown`
