# Panduan Integrasi Plug and Play WAG Hub (WhatsApp Gateway Hub)

Panduan ini ditujukan bagi developer backend yang ingin menghubungkan aplikasi baru (**`cesa-web`**, **`web-shelf`**, **`web-sam`**, **`web-helpdesk`**, CRM, POS, mobile API, atau microservice lainnya) ke **WAG Hub**.

WAG Hub dirancang sebagai **layanan WhatsApp mandiri (microservice terpisah)**. Aplikasi klien tidak perlu menjalankan Node.js, tidak perlu menginstal library Baileys, dan tidak perlu mengelola koneksi socket WhatsApp secara lokal.

---

## 1. Arsitektur: Hub API vs Engine API

WAG Hub menyediakan dua jalur layanan yang jelas:

| Fitur | 1. Hub API | 2. Engine API |
|---|---|---|
| **Tujuan** | Notifikasi sistem, OTP, broadcast, validasi nomor | Nomor dinas/pribadi per pengguna atau departemen |
| **Autentikasi** | `Authorization: Bearer <WAG_TOKEN>` | `Authorization: Bearer <WAG_ENGINE_TOKEN>` |
| **Ability Token** | `messages:send`, `messages:read`, `numbers:check` | `engine:use` |
| **Endpoint Base** | `https://gateway.example.com/api/v1` | `https://gateway.example.com/api/v1/engine` |
| **Pengirim** | Pool nomor provider (WAHA/Baileys, Fonnte, GOWA, WABA) | Nomor spesifik yang ditautkan via QR Code / Pairing Code |
| **Fallback** | Otomatis berganti provider jika gagal definitif | Pinned (tetap pada sesi nomor tersebut) |
| **Contoh Penggunaan** | Form transfer approval, exit clearance alert, OTP login | Rekruter HR menghubungi pelamar, CS chat dengan pelanggan |

---

## 2. Onboarding Aplikasi Baru (< 5 Menit)

1. Buka dashboard WAG Hub di browser: `https://gateway.example.com/admin`.
2. Masuk ke menu **Aplikasi Klien** (`Client Applications`) -> **Tambah Aplikasi Klien**.
3. Masukkan nama aplikasi (misalnya `CRM Marketing` atau `Helpdesk Internal`), lalu Simpan.
4. Pada detail aplikasi, klik tombol **Buat token Hub & Engine** (*Integration Pack*).
5. Modal akan menampilkan blok konfigurasi `.env` siap pakai:

```dotenv
# crm-marketing
WAG_URL=https://gateway.example.com
WAG_TOKEN=wgh_hub_xxxxxxxxxxxxxxxxxxxx
WAG_ENGINE_URL=https://gateway.example.com/api/v1/engine
WAG_ENGINE_TOKEN=wgh_engine_yyyyyyyyyyyyyyyyyyyy
```

> [!IMPORTANT]
> Salin dan simpan token saat pertama kali dimunculkan. WAG Hub hanya menyimpan hash token di database demi keamanan.

---

## 3. Menjalankan Engine Baileys di WAG Hub

WAG Hub menyediakan 2 pilihan hosting engine Baileys:

### Opsi A: Docker WAHA NOWEB (Standar Produksi)
WAHA menjalankan engine NOWEB berbasis Baileys dalam container terisolasi:
```bash
cp engine/.env.example engine/.env
# Sesuaikan WAHA_API_KEY di engine/.env
docker compose -f engine/docker-compose.yml up -d
```
Lalu di panel WAG Hub (**Provider Accounts**), daftarkan provider WAHA dengan URL `http://127.0.0.1:3000` dan masukkan API Key-nya.

### Opsi B: Standalone Baileys Runner (Ringan Tanpa Docker)
Tersedia di `engine/baileys-runner`:
```bash
cd engine/baileys-runner
npm ci --omit=dev
npm start
```
Engine akan mendengarkan di `http://127.0.0.1:3318`.

---

## 4. Contoh Integrasi di Aplikasi Klien

### A. PHP / Laravel

#### Mengirim Notifikasi via Hub API:
```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$response = Http::withToken(env('WAG_TOKEN'))
    ->withHeaders([
        'Idempotency-Key' => (string) Str::uuid(),
    ])
    ->post(rtrim(env('WAG_URL'), '/') . '/api/v1/messages', [
        'recipient' => [
            'type'  => 'phone',
            'value' => '081234567890',
        ],
        'message' => [
            'type' => 'text',
            'text' => 'Halo, ini notifikasi dari sistem.',
        ],
        'purpose' => 'notification',
        'mode'    => 'async', // atau 'sync' untuk OTP
        'route_key' => 'default',
        'client_reference' => 'inv-12345',
    ]);

$result = $response->json();
```

#### Validasi Nomor WhatsApp Terdaftar:
```php
$response = Http::withToken(env('WAG_TOKEN'))
    ->post(rtrim(env('WAG_URL'), '/') . '/api/v1/numbers/check', [
        'phone' => '081234567890',
    ]);

$isRegistered = $response->json('data.registered') === true;
```

#### Memulai Sesi Baileys (QR Code / Pairing Code) via Engine API:
```php
// 1. Memulai sesi baru untuk user atau departemen
$response = Http::withToken(env('WAG_ENGINE_TOKEN'))
    ->post(rtrim(env('WAG_ENGINE_URL'), '/') . '/sessions', [
        'id'   => 'cs-rekrutmen-01',
        'mode' => 'qr', // atau 'pairing' dengan field 'phone'
    ]);

// $response->json('qr') berisi Data URI SVG/PNG untuk ditampilkan di browser
$qrDataUri = $response->json('qr');

// 2. Polling status sesi
$statusResponse = Http::withToken(env('WAG_ENGINE_TOKEN'))
    ->get(rtrim(env('WAG_ENGINE_URL'), '/') . '/sessions/cs-rekrutmen-01');

if ($statusResponse->json('status') === 'connected') {
    // Sesi siap digunakan untuk kirim pesan!
}

// 3. Kirim pesan pinned dari sesi tersebut
$sendResponse = Http::withToken(env('WAG_ENGINE_TOKEN'))
    ->post(rtrim(env('WAG_ENGINE_URL'), '/') . '/sessions/cs-rekrutmen-01/send', [
        'phone'           => '6281234567890',
        'text'            => 'Halo dari nomor HR kami!',
        'idempotency_key' => 'undangan-interview-001',
    ]);
```

---

### B. Node.js / JavaScript (Fetch)

```javascript
// Mengirim notifikasi
const response = await fetch(`${process.env.WAG_URL}/api/v1/messages`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${process.env.WAG_TOKEN}`,
        'Idempotency-Key': crypto.randomUUID(),
    },
    body: JSON.stringify({
        recipient: { type: 'phone', value: '081234567890' },
        message: { type: 'text', text: 'Pesan dari Node.js' },
        purpose: 'notification',
        mode: 'async',
    }),
});
const data = await response.json();
```

---

### C. Python

```python
import os
import uuid
import requests

url = f"{os.getenv('WAG_URL')}/api/v1/messages"
headers = {
    "Authorization": f"Bearer {os.getenv('WAG_TOKEN')}",
    "Idempotency-Key": str(uuid.uuid4()),
    "Content-Type": "application/json"
}
payload = {
    "recipient": {"type": "phone", "value": "081234567890"},
    "message": {"type": "text", "text": "Pesan dari Python service"},
    "purpose": "notification",
    "mode": "async"
}

response = requests.post(url, json=payload, headers=headers)
print(response.json())
```

---

### D. cURL

```bash
# Kirim Pesan Cepat (Hub API)
curl -X POST "https://gateway.example.com/api/v1/messages" \
  -H "Authorization: Bearer $WAG_TOKEN" \
  -H "Idempotency-Key: msg-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": {"type": "phone", "value": "081234567890"},
    "message": {"type": "text", "text": "Halo melalui cURL"},
    "purpose": "notification",
    "mode": "async"
  }'

# Mulai Sesi Baileys (Engine API)
curl -X POST "https://gateway.example.com/api/v1/engine/sessions" \
  -H "Authorization: Bearer $WAG_ENGINE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id": "helpdesk-01", "mode": "qr"}'
```

---

## 5. Idempotency & Safety

- **Wajib menyertakan `Idempotency-Key`**: Menjamin pesan transaksi/OTP yang di-retry oleh network tidak akan pernah terkirim dobel ke nomor tujuan WhatsApp.
- **Handling Status Pengiriman**:
  - `sent` / `provider_accepted`: Provider menerima pesan untuk dikirimkan.
  - `failed`: Pengiriman gagal definitif; periksa `error_code` dan `retryable`.
  - `unknown`: Status belum dapat dipastikan karena timeout jaringan. **Jangan membuat request baru dengan key baru**; lakukan polling status dengan key yang sama.
