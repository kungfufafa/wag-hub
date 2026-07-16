# Panduan Deploy WhatsApp Gateway Hub

Panduan ini memakai Linux, Nginx, PHP-FPM, MySQL/MariaDB, dan Supervisor. Ganti semua placeholder sebelum menjalankan perintah di production.

## 1. Siapkan server

Pasang Git, PHP beserta ekstensi Laravel (`mbstring`, `xml`, `curl`, `zip`, `pdo_mysql`, `bcmath`), Composer, Node.js LTS, Nginx, MySQL/MariaDB, dan Supervisor.

Buat database dan user khusus Hub:

```sql
CREATE DATABASE gateway_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gateway_hub'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_DATABASE';
GRANT ALL PRIVILEGES ON gateway_hub.* TO 'gateway_hub'@'localhost';
FLUSH PRIVILEGES;
```

## 2. Ambil kode

```bash
git clone <URL_REPOSITORY> gateway-hub
cd gateway-hub
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
chmod -R ug+rwx storage bootstrap/cache
```

## 3. Isi `.env` production

Contoh minimum:

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

# Hanya hostname provider yang boleh dihubungi Hub.
GATEWAY_PROVIDER_HTTPS_HOSTS=wa.example.com,api.fonnte.com
GATEWAY_PROVIDER_HTTP_HOSTS=
GATEWAY_PROVIDER_FAILURE_THRESHOLD=3
GATEWAY_PROVIDER_CIRCUIT_SECONDS=300

# Ganti nilai ini sebelum menjalankan seed.
GATEWAY_SEED_ADMIN_NAME=Gateway Administrator
GATEWAY_SEED_ADMIN_EMAIL=admin@contoh.com
GATEWAY_SEED_ADMIN_PASSWORD=GANTI_PASSWORD_ADMIN_KUAT
```

`APP_KEY` wajib dipertahankan dan dibackup. Mengganti key setelah data tersimpan membuat isi pesan serta konfigurasi provider terenkripsi tidak dapat dibaca.

Nilai `GATEWAY_SEED_WAHA_*`, `GATEWAY_SEED_FONNTE_*`, dan `GATEWAY_SEED_*_TOKEN` bersifat opsional. Lebih aman membuat token aplikasi melalui panel admin setelah deploy.

## 4. Inisialisasi Hub

```bash
php artisan migrate --seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Seeder membuat aplikasi `appscript-ft`, `web-helpdesk`, `web-sam`, dan `web-shelf`, serta route awal. Masuk ke `https://gateway.example.com/admin`, lalu:

1. Lengkapi **Akun Provider** WAHA, Fonnte, GOWA, atau WABA.
2. Pastikan **Aturan Pengiriman** aktif dengan urutan fallback yang benar.
3. Terbitkan token di **Aplikasi Klien** untuk setiap aplikasi sumber.

Simpan token ketika pertama kali tampil. Hub hanya menyimpan hash token dan tidak dapat menampilkan token plaintext kembali.

## 5. Konfigurasi Nginx

```nginx
server {
    listen 80;
    server_name gateway.example.com;
    root /var/www/gateway-hub/public;
    index index.php;
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Sesuaikan lokasi aplikasi dan socket PHP-FPM, aktifkan HTTPS dengan sertifikat valid, lalu reload Nginx.

## 6. Jalankan worker antrian

Notifikasi dengan mode `async` tidak diteruskan tanpa worker. Buat `/etc/supervisor/conf.d/gateway-hub-worker.conf`:

```ini
[program:gateway-hub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/gateway-hub/artisan queue:work --tries=1 --timeout=360 --sleep=1
directory=/var/www/gateway-hub
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/gateway-hub/storage/logs/worker.log
stopwaitsecs=370
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start gateway-hub-worker:*
```

## 7. Jalankan scheduler

Tambahkan satu cron job untuk memulihkan pesan stale dan menjalankan jadwal Laravel:

```cron
* * * * * cd /var/www/gateway-hub && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Sambungkan aplikasi sumber

Setiap aplikasi sumber memakai tokennya sendiri:

```dotenv
WHATSAPP_HUB_BASE_URL=https://gateway.example.com
WHATSAPP_HUB_TOKEN=wgh_TOKEN_KHUSUS_APLIKASI
WHATSAPP_HUB_PURPOSE=notification
WHATSAPP_HUB_MODE=async
WHATSAPP_HUB_ROUTE_KEY=default
```

- OTP: gunakan `purpose=otp`, `mode=sync`, dan sertakan `expires_at` pada request.
- Notifikasi: gunakan `mode=async`; worker Hub harus aktif.
- Aplikasi sumber tidak lagi menyimpan credential provider WhatsApp.
- Setelah mengubah `.env` aplikasi sumber, jalankan `php artisan config:cache` pada aplikasi tersebut.

## 9. Verifikasi deploy

```bash
php artisan about
php artisan queue:failed
php artisan schedule:list
php artisan gateway:recover-stale --minutes=10 --limit=100 --dry-run
```

Lakukan satu smoke test dari setiap aplikasi sumber. Di menu **Pesan**, pastikan aplikasi, status, provider, dan riwayat attempt benar. Buka detail record untuk melihat isi pesan saat audit.

## 10. Update rilis berikutnya

```bash
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

Backup database sebelum migrasi. Setelah update, cek log `storage/logs/laravel.log`, status Supervisor, dan lakukan satu smoke test.
