# Instalasi dan deployment

## Persyaratan

Aplikasi menggunakan Laravel 13, PHP minimal 8.3, MySQL 8 atau MariaDB 10.6+, dan Composer 2. Lingkungan pengembangan yang diperiksa: PHP 8.3.31, MySQL 8.0.30, Node.js 22.15. Aset memakai Bootstrap 5, Bootstrap Icons, Chart.js, dan ZXing; semuanya dibundel lokal melalui Vite.

Aktifkan ekstensi PHP `bcmath`, `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `zip`, `gd`, `ctype`, dan `tokenizer`. Untuk pengujian, aktifkan `pdo_sqlite`. Barcode PNG membutuhkan GD. Ekspor PDF menggunakan Dompdf, dan XLSX menggunakan PhpSpreadsheet. Versi tepat dipatok pada `composer.lock` dan `package-lock.json`.

Pastikan PHP CLI dan PHP web server memakai versi serta ekstensi yang sama:

```powershell
php -v
php -m
composer check-platform-reqs
node --version
npm --version
```

## Instalasi lokal dengan Laragon

Jalankan MySQL dan web server Laragon. Arahkan document root situs ke `C:/laragon/www/ppam10/public`. Direktori proyek tidak boleh menjadi document root.

```powershell
Set-Location C:\laragon\www\ppam10
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Perintah penyalinan `.env` hanya untuk instalasi baru. Pertahankan `.env` yang sudah berisi konfigurasi lokal. Buat basis data kosong dengan charset `utf8mb4`, misalnya `pam_rw10`, dan gunakan akun basis data khusus aplikasi. Isi `.env`:

```dotenv
APP_NAME="PAM RW.10"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://ppam10.test
APP_LOCALE=id
APP_FALLBACK_LOCALE=id
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pam_rw10
DB_USERNAME=pam_app
DB_PASSWORD=isi_di_lingkungan_lokal
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

Zona waktu aplikasi ditetapkan `Asia/Jakarta` di konfigurasi. Nilai uang adalah rupiah bulat, angka meter memakai maksimal tiga desimal.

```powershell
php artisan migrate --seed
php artisan pam:admin admin@organisasi.example --name="Administrator PAM"
npm install
npm run build
php artisan test
```

`pam:admin` meminta kata sandi melalui input rahasia; minimal 12 karakter. Tidak ada kata sandi produksi tetap. Seeder utama membuat wilayah, role, kategori, tarif, metode pembayaran, akun kas, dan pengaturan; seeder ini tidak membuat transaksi demonstrasi.

Gunakan `npm ci` pada deployment sesudah lockfile tersedia. Untuk pengembangan, jalankan `npm run dev` atau build ulang setelah mengubah aset. Alternatif web server lokal: `php artisan serve --host=127.0.0.1 --port=8000` dan sesuaikan `APP_URL`.

## Data demonstrasi terpisah

Gunakan basis data khusus demo; jangan jalankan DemoSeeder pada data produksi. Opsional, atur `DEMO_PASSWORD` sendiri pada lingkungan lokal; jika kosong, seeder membuat kata sandi acak. Lalu jalankan:

```powershell
php artisan db:seed --class=DemoSeeder
```

Master produksi tetap berasal dari `DatabaseSeeder`. Password demo berasal dari lingkungan atau generator acak, bukan kredensial yang ditanam pada source. Setelah seeding, kredensial lokal ditulis ke `storage/app/demo-credentials.txt`; lindungi dan hapus berkas ini bila tidak diperlukan. Seeder menolak lingkungan `production`.

## Antrean dan scheduler

Pengingat WhatsApp juga menggunakan worker database dan scheduler ini. Konfigurasi akun Meta, webhook HTTPS, persetujuan template/penerima, permission, serta batas pengiriman dijelaskan di [WHATSAPP.md](WHATSAPP.md). Fitur awal berstatus Belum Terhubung dengan pengiriman otomatis nonaktif. Gunakan antrean `database` untuk pengingat operasional agar jeda pengiriman dan pemeriksaan saldo berjalan di worker.

Ekspor lebih dari 2.000 baris otomatis diproses melalui job `GenerateReportExport`; pengguna juga dapat memilih pemrosesan latar belakang. Berkas disimpan pada disk privat `local`, ditautkan kepada pemohon, dan tautan unduhan kedaluwarsa satu jam. Worker harus aktif agar pekerjaan selesai.

```powershell
php artisan queue:work --sleep=2 --tries=2 --timeout=300
php artisan schedule:work
```

Untuk produksi, jalankan worker sebagai service (Supervisor/systemd pada Linux atau service manager pada Windows), dengan restart otomatis dan akun OS terbatas. Atur `retry_after` koneksi antrean lebih besar daripada timeout worker, misalnya 360 detik. Gunakan `php artisan queue:restart` setelah pembaruan source. Pantau `php artisan queue:failed` dan log aplikasi; pekerjaan gagal dapat diulang setelah penyebabnya diperbaiki.

Scheduler produksi cukup menjalankan `php artisan schedule:run` sekali per menit. Contoh cron:

```cron
* * * * * cd /srv/pam-rw10 && php artisan schedule:run >> /dev/null 2>&1
```

Pada Windows, buat Task Scheduler yang menjalankan `php.exe artisan schedule:run` setiap menit dengan direktori kerja proyek. Jangan menjalankan `schedule:work` dan cron bersamaan pada instalasi yang sama.

## Deployment produksi

1. Pasang dependensi dari lockfile: `composer install --no-dev --prefer-dist --optimize-autoloader` dan `npm ci && npm run build` pada mesin build yang tepercaya.
2. Gunakan HTTPS dengan sertifikat valid. Kamera pemindai membutuhkan konteks aman; sediakan input ID manual ketika kamera tidak tersedia.
3. Atur `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://domain-pam.example`, `SESSION_SECURE_COOKIE=true`, dan kredensial DB khusus aplikasi. Simpan `.env` di server, di luar version control.
4. Gunakan `APP_KEY` tetap dari instalasi tersebut. Jangan menjalankan ulang `key:generate` pada pembaruan rutin.
5. Backup database serta berkas privat sebelum migration. Jalankan `php artisan down`, `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`, lalu `php artisan up`.
6. Beri izin tulis hanya pada `storage` dan `bootstrap/cache` untuk akun web/worker. Source, `.env`, dan backup tidak boleh dapat diunduh melalui web.
7. Jalankan worker dan scheduler sebagai service. Pantau ruang disk, failed jobs, error log, sertifikat TLS, dan backup.
8. Uji login tiap role, satu siklus meter–tagihan–pembayaran, pembatalan, kuitansi PDF, impor, label, dan ekspor melalui domain HTTPS produksi/staging.

Foto meter dan bukti transaksi disimpan privat. Tidak perlu `storage:link` untuk membukanya. Akses melalui controller berautentikasi dan otorisasi, bukan URL berkas publik.

## Backup dan restore

Backup harus mencakup database, `storage/app/private` (lokasi disk lokal mengikuti `config/filesystems.php`), dan salinan terenkripsi `.env`/`APP_KEY`. Simpan backup di lokasi terpisah dari server aplikasi. Cadangkan secara harian, sebelum pembaruan, dan sebelum perubahan data massal. Gunakan retensi yang disepakati pengurus, misalnya 7 harian dan 12 bulanan, serta uji pemulihan berkala.

Gunakan `--result-file` pada Windows agar dump SQL tidak melewati pengodean ulang redirection PowerShell. Perintah berikut meminta password; jangan menaruh password di command line:

```powershell
mysqldump --host=127.0.0.1 --user=pam_backup --password --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file="D:\backup-pam\pam-2026-10-15.sql" pam_rw10
Compress-Archive -LiteralPath "C:\laragon\www\ppam10\storage\app\private" -DestinationPath "D:\backup-pam\berkas-2026-10-15.zip"
Get-FileHash -LiteralPath "D:\backup-pam\pam-2026-10-15.sql" -Algorithm SHA256
```

Buat direktori tujuan backup terlebih dahulu dan berikan izin hanya kepada petugas backup. Backup berkas ketika unggahan/transaksi dibekukan sebentar, atau gunakan snapshot konsisten agar database dan berkas sesuai. `--single-transaction` mengandalkan tabel InnoDB.

Pemulihan dilakukan ke database kosong yang disiapkan untuk restore. Aktifkan maintenance, hentikan worker, periksa checksum, salin kembali `.env` beserta kunci aslinya, pulihkan berkas privat, lalu impor SQL melalui client MySQL:

```powershell
mysql --host=127.0.0.1 --user=pam_restore --password pam_rw10_restore
```

Di prompt MySQL:

```sql
SOURCE D:/backup-pam/pam-2026-10-15.sql;
```

Untuk mengembalikan ZIP, ekstrak ke direktori sementara dan periksa struktur sebelum menyalin berkas ke disk privat. Arahkan `.env` ke database hasil restore, jalankan `php artisan optimize:clear`, periksa `php artisan migrate:status`, dan jalankan migration baru hanya jika versi source memang lebih baru daripada backup. Pastikan saldo akun, jumlah tagihan, piutang, alokasi pembayaran, serta bukti privat sesuai. Baru aktifkan worker dan buka maintenance. Simpan catatan pelaksana, waktu, checksum, dan hasil uji restore.

## Pemecahan masalah

| Gejala | Pemeriksaan |
|---|---|
| Halaman meminta build Vite | Jalankan `npm install` / `npm ci` lalu `npm run build`. |
| Barcode PNG gagal | Pastikan GD aktif pada PHP web dan CLI. |
| XLSX/PDF gagal | Jalankan `composer check-platform-reqs`; periksa ZIP/XML/GD, izin storage, dan log. |
| Ekspor terus menunggu | Pastikan `QUEUE_CONNECTION=database`, worker aktif, cache/DB terjangkau; periksa failed jobs. |
| Kamera tidak muncul | Buka HTTPS, izinkan kamera, dan gunakan input manual jika perangkat tidak mendukung. |
| Operator tidak melihat pelanggan | Periksa role aktif, permission, dan penugasan RT. Filter UI tidak memberi akses tambahan. |
| Transaksi ditolak setelah tutup buku | Buka kembali periode dengan hak akses dan alasan, atau gunakan transaksi pembalik pada periode terbuka sesuai prosedur. |
| Unggahan ditolak walaupun di bawah batas aplikasi | Periksa `upload_max_filesize`, `post_max_size`, dan batas reverse proxy. |

Pengujian otomatis memakai SQLite memori agar tidak mengubah database operasional. Uji integrasi MySQL pada staging tetap diperlukan untuk verifikasi deployment, isolasi transaksi, dan beban bersamaan.
