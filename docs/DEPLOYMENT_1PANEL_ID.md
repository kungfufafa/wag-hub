# Deploy WhatsApp Gateway Hub dengan 1Panel

Panduan ini memakai fitur **Website**, **PHP Runtime**, **Database**, **Cron Job**, dan **Supervisor** dari 1Panel. Siapkan domain HTTPS, misalnya `gateway.example.com`.

## 1. Buat PHP Runtime

1. Masuk 1Panel → **Website** → **Runtime** → **Create Runtime Environment**.
2. Pilih PHP 8.3 atau versi yang sesuai `composer.lock` proyek.
3. Aktifkan ekstensi `mbstring`, `xml`, `curl`, `zip`, `pdo_mysql`, dan `bcmath`.
4. Simpan runtime, misalnya bernama `php83-gateway`.

## 2. Buat database

1. Buka **Database** → **MySQL/MariaDB** → **Create Database**.
2. Buat database `gateway_hub` dan user database khusus.
3. Catat host, port, nama database, user, dan passwordnya.
4. Aktifkan backup database terjadwal dari 1Panel.

## 3. Buat website dan pasang kode

1. Buka **Website** → **Create Website**.
2. Pilih tipe **Runtime** lalu pilih runtime `php83-gateway`.
3. Hubungkan domain `gateway.example.com`.
4. Catat direktori website yang dibuat 1Panel, selanjutnya disebut `<DIREKTORI_APP>`.
5. Dari terminal 1Panel atau SSH, ambil kode ke direktori tersebut:

```bash
cd <DIREKTORI_APP>
git clone <URL_REPOSITORY> .
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
chmod -R ug+rwx storage bootstrap/cache
```

Atur document root website ke `<DIREKTORI_APP>/public`, bukan ke root proyek.

## 4. Isi environment production

Edit `<DIREKTORI_APP>/.env` dari file manager/terminal 1Panel:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gateway.example.com
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gateway_hub
DB_USERNAME=gateway_hub
DB_PASSWORD=GANTI_PASSWORD_DATABASE

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=420

GATEWAY_PROVIDER_HTTPS_HOSTS=wa.example.com,api.fonnte.com
GATEWAY_PROVIDER_HTTP_HOSTS=
GATEWAY_PROVIDER_FAILURE_THRESHOLD=3
GATEWAY_PROVIDER_CIRCUIT_SECONDS=300

GATEWAY_SEED_ADMIN_NAME=Gateway Administrator
GATEWAY_SEED_ADMIN_EMAIL=admin@contoh.com
GATEWAY_SEED_ADMIN_PASSWORD=GANTI_PASSWORD_ADMIN_KUAT
```

`APP_KEY` harus disimpan aman dan ikut backup. Jangan pernah menggantinya setelah Hub mulai menyimpan pesan, karena isi pesan dan konfigurasi provider terenkripsi memakai key tersebut.

## 5. Inisialisasi aplikasi

Jalankan dari terminal yang memakai runtime PHP website:

```bash
cd <DIREKTORI_APP>
php artisan migrate --seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Masuk ke `https://gateway.example.com/admin`. Setelah login:

1. Lengkapi **Akun Provider** WAHA, Fonnte, GOWA, atau WABA.
2. Pastikan **Aturan Pengiriman** aktif serta urutan provider benar.
3. Terbitkan token di **Aplikasi Klien** untuk `web-sam`, `web-shelf`, `web-helpdesk`, dan `appscript-ft` sesuai kebutuhan.

Token hanya ditampilkan sekali saat dibuat. Simpan pada environment aplikasi sumber, bukan di repository.

## 6. Aktifkan HTTPS

Di halaman website 1Panel:

1. Buka tab **HTTPS**.
2. Pilih sertifikat Let's Encrypt atau upload sertifikat yang sudah ada.
3. Aktifkan redirect HTTP ke HTTPS.
4. Pastikan `APP_URL` di `.env` menggunakan `https://`.

## 7. Buat worker dengan Supervisor 1Panel

Pesan `async` tidak akan dikirim tanpa worker.

1. Instal lalu inisialisasi Supervisor dari **Toolbox** → **Supervisor** di 1Panel.
2. Pilih **Create daemon process**.
3. Isi command berikut dan ganti path serta binary PHP bila diperlukan:

```bash
php <DIREKTORI_APP>/artisan queue:work --tries=1 --timeout=360 --sleep=1
```

4. Set working directory ke `<DIREKTORI_APP>`.
5. Aktifkan auto-start dan auto-restart.
6. Setelah dibuat, pastikan status proses **Running**.

## 8. Buat scheduler dengan Cron Job 1Panel

1. Buka **Cron Job** → **Create Cron Job**.
2. Pilih tipe **Shell Script**.
3. Jadwal: setiap menit (`* * * * *`).
4. Isi perintah:

```bash
cd <DIREKTORI_APP> && php artisan schedule:run
```

5. Jalankan sekali secara manual dari 1Panel dan periksa log eksekusinya.

## 9. Sambungkan aplikasi sumber

Di environment setiap aplikasi sumber:

```dotenv
WHATSAPP_HUB_BASE_URL=https://gateway.example.com
WHATSAPP_HUB_TOKEN=wgh_TOKEN_KHUSUS_APLIKASI
WHATSAPP_HUB_PURPOSE=notification
WHATSAPP_HUB_MODE=async
WHATSAPP_HUB_ROUTE_KEY=default
```

- Untuk OTP, gunakan `purpose=otp`, `mode=sync`, dan sertakan `expires_at`.
- Aplikasi sumber tidak lagi memakai credential provider WhatsApp secara langsung.
- Setelah mengubah environment aplikasi sumber: `php artisan config:cache`.

## 10. Checklist go-live

```bash
cd <DIREKTORI_APP>
php artisan about
php artisan queue:failed
php artisan schedule:list
php artisan gateway:recover-stale --minutes=10 --limit=100 --dry-run
```

- Website HTTPS dapat dibuka.
- Supervisor worker berstatus Running.
- Cron Job berjalan setiap menit.
- Provider hostname sudah masuk allowlist `GATEWAY_PROVIDER_HTTPS_HOSTS`.
- Satu pesan uji dari masing-masing aplikasi sumber menghasilkan status **Diterima provider** di menu **Pesan**.
- Backup database dan file `.env` sudah aktif.

## Update rilis

Gunakan terminal 1Panel/SSH:

```bash
cd <DIREKTORI_APP>
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Sesudah update, cek log Laravel, status worker Supervisor, dan lakukan satu smoke test.
