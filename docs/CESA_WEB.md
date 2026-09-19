# Integrasi CESA dan WAG Hub

Ikuti [panduan plug and play](PLUG_AND_PLAY_GUIDE.md) untuk menyiapkan service.
CESA cukup diberi konfigurasi dari **Aplikasi Klien → Hubungkan aplikasi**:

```dotenv
WAG_URL=https://gateway.example.com
WAG_TOKEN=token-aplikasi-cesa
```

Jalankan di CESA:

```bash
php artisan config:clear
php artisan queue:restart
php artisan wag:status
```

Di pengaturan WhatsApp Rekrutmen, hubungkan nomor lewat QR/pairing, pilih nomor
pengirim, lalu uji kirim. Login/logout dan socket dikerjakan di WAG Hub; CESA
menyimpan referensi akun dan pilihan pengirim. Node.js tidak diperlukan untuk
WhatsApp di server CESA.

## Migrasi instalasi lama

- Hapus override `REKRUTMEN_WHATSAPP_ENGINE_*` yang sudah tidak dipakai.
- Hapus `WAG_ENGINE_URL` dan `WAG_ENGINE_TOKEN` jika memakai token baru terpadu.
  Nilai override yang masih terisi tetap didahulukan; nilai kosong diabaikan.
- Token Hub lama mungkin belum memiliki `engine:use`. Buat token melalui
  **Hubungkan aplikasi**, atau pertahankan token engine lama sebagai
  `WAG_ENGINE_TOKEN`. Token lama tidak dicabut otomatis.
- Engine lokal CESA hanya berjalan jika dipilih eksplisit dengan
  `REKRUTMEN_WHATSAPP_ENGINE_DRIVER=local`. Default baru memakai WAG Hub.
- Sesi dan jurnal lokal tidak disalin otomatis. Selesaikan pengiriman yang
  statusnya belum pasti sebelum migrasi, lalu tautkan ulang nomor di Hub.

`rekrutmen:whatsapp-engine --ensure` tetap tersedia untuk kompatibilitas dan
hanya mengecek layanan pada mode WAG Hub. Pemeriksaan umum untuk modul mana pun
adalah `wag:status`.

Aplikasi DND nantinya memakai aplikasi klien/token sendiri dan endpoint yang
sama. Pemisahan sesi mengikuti identitas aplikasi di Hub, bukan slug atau nama
plugin CESA.
