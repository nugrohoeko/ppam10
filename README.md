# Sistem Informasi Pengelolaan Air PAM RW.10

Aplikasi Laravel 13 / PHP 8.3+ / MySQL untuk pelanggan dan meter, tarif bertingkat berversi, barcode, pencatatan/verifikasi, tagihan, pembayaran teralokasi, kas/bank, audit, dan 15 laporan Excel/PDF. Antarmuka Bahasa Indonesia, zona waktu Asia/Jakarta, uang rupiah bulat dan meter DECIMAL(14,3).

Pengingat tagihan WhatsApp mendukung pengiriman individual/kolektif melalui Meta Cloud API, template yang dikelola Admin, jadwal otomatis, pratinjau saldo terbaru, permission wilayah, dan riwayat webhook. Hubungkan akun Meta serta persetujuan template/penerima sebelum pengiriman; lihat [panduan WhatsApp](docs/WHATSAPP.md).

## Menjalankan lokal

Untuk checkout yang sudah memiliki `.env` lokal dan database:

```powershell
composer install
npm install
npm run build
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Pertahankan `.env` yang sudah tersedia. Untuk instalasi baru, salin `.env.example`, isi koneksi MySQL, jalankan `php artisan key:generate`, lalu migration/seeder. Laragon dapat melayani situs langsung dengan document root `public`. Buat administrator produksi secara interaktif:

```powershell
php artisan pam:admin admin@organisasi.example
```

Tidak ada kata sandi produksi tetap di source. Seeder produksi hanya memasang master. Pada database demo terpisah, `php artisan db:seed --class=DemoSeeder` membuat transaksi contoh melalui service yang sama dan menulis kredensial acak lokal ke `storage/app/demo-credentials.txt`. Lindungi berkas tersebut; DemoSeeder menolak lingkungan production.

Ekspor besar memerlukan worker. Jalankan di terminal/service terpisah:

```powershell
php artisan queue:work --sleep=2 --tries=2 --timeout=300
php artisan schedule:work
```

## Dokumentasi

- [Instalasi, konfigurasi, antrean, deployment, backup dan restore](docs/INSTALLATION.md)
- [Analisis, asumsi bisnis, schema/relasi dan arti laporan](docs/ARCHITECTURE.md)
- [Panduan tiap role dan matriks permission awal](docs/USER_GUIDE.md)
- [Pemetaan fitur, pengujian dan batas verifikasi](docs/COVERAGE.md)
- [Integrasi dan pengoperasian pengingat WhatsApp](docs/WHATSAPP.md)
- [Deployment GitHub dan Vercel Container Runtime](docs/VERCEL.md)

## Verifikasi

```powershell
php artisan test
npm run build
php tests/Support/verify-finance-concurrency.php
```

PHPUnit memakai SQLite memori; script concurrency memakai MySQL pengembangan/staging. Untuk browser smoke, sediakan server lokal dan Chrome lalu jalankan `node tests/browser-smoke.mjs`. Kamera dan ukuran label perlu diperiksa pada perangkat/printer operasional melalui HTTPS.
