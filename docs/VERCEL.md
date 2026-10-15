# Deployment GitHub dan Vercel

Proyek ini memakai **Vercel Container Runtime (Beta)** melalui `Dockerfile.vercel`, bukan deployment HTML statis. Image menjalankan Laravel dengan PHP 8.3 dan Apache, serta membangun aset Vite menggunakan Node 22. Vercel mendeteksi Dockerfile tersebut melalui preset `container` dalam `vercel.json` dan meneruskan request ke port 80.

Repository tujuan: [nugrohoeko/ppam10](https://github.com/nugrohoeko/ppam10). Keberadaan repository tidak berarti deployment Vercel sudah terhubung atau tersedia.

Referensi platform: [Container Runtime resmi](https://vercel.com/docs/functions/runtimes/container), [filesystem Functions](https://vercel.com/docs/functions/runtimes#file-system-support), dan [batas Cron Jobs](https://vercel.com/docs/cron-jobs/usage-and-pricing).

## Layanan yang harus tersedia

- Repository GitHub milik pengguna dan project Vercel yang terhubung dengannya.
- MySQL/MariaDB eksternal yang dapat diakses Vercel. Database Laragon di `127.0.0.1` tidak dapat diakses dari Vercel.
- Bucket privat S3 atau layanan kompatibel S3 untuk foto, bukti pembayaran, lampiran kas, dan hasil ekspor. Jangan mengaktifkan akses publik pada bucket ini.
- Worker antrean PHP dan scheduler yang berjalan di server/layanan worker terpisah, memakai commit, database, bucket, dan `APP_KEY` yang sama dengan aplikasi web.

Image menolak startup jika environment penting belum diisi, database menunjuk localhost, atau file privat diarahkan ke disk sementara. Pemeriksaan ini hanya memvalidasi konfigurasi; keberhasilan koneksi database/bucket tetap harus diuji pada lingkungan deployment.

## Environment Vercel

Atur nilai berikut di **Project Settings → Environment Variables**. Pisahkan database/bucket Preview dari Production. Jangan menyimpan nilai rahasia dalam Git, Dockerfile, command history, atau `vercel.json`.

| Variabel | Nilai/penggunaan |
| --- | --- |
| `APP_KEY` | Kunci Laravel AES-256 yang dibuat satu kali di lingkungan terpercaya dan disimpan tetap. Mengganti kunci dapat memutus akses kredensial WhatsApp yang terenkripsi. |
| `APP_URL` | URL HTTPS/domain project Vercel yang sebenarnya. |
| `DB_CONNECTION` | `mysql` atau `mariadb`. |
| `DB_HOST`, `DB_PORT` | Host database eksternal dan portnya. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database dan akun khusus aplikasi. |
| `DB_URL` | Alternatif URL koneksi lengkap; jika diisi, Laravel memprioritaskannya. Jangan mengisi URL SQLite/lokal. |
| `MYSQL_ATTR_SSL_CA` | Path sertifikat CA jika penyedia MySQL mewajibkannya; sediakan sertifikat CA publik sesuai instruksi penyedia. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Kredensial yang hanya memiliki akses ke bucket privat aplikasi. |
| `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Region dan nama bucket. Untuk layanan kompatibel, ikuti region yang diwajibkan penyedia. |
| `AWS_ENDPOINT` | Opsional, URL HTTPS endpoint layanan kompatibel S3. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Opsional, `true` jika penyedia mensyaratkannya. |

Default aman sudah ada di image: `APP_ENV=production`, `APP_DEBUG=false`, log stderr, session terenkripsi dengan cookie HTTPS, cache/session/antrean database, `PRIVATE_FILESYSTEM_DISK=s3`, dan `PORT=80`. `FILESYSTEM_DISK` tidak menentukan tempat penyimpanan dokumen privat; gunakan `PRIVATE_FILESYSTEM_DISK`.

Apache mengenali HTTPS dari header proxy Vercel. Access log hanya menyimpan path tanpa query string agar token verifikasi webhook tidak ikut tercatat.

Laravel storage, Blade hasil kompilasi, manifest runtime, upload sementara, dan cache font berada di `/tmp/pam-storage`. Folder ini boleh hilang kapan saja. Dokumen operasional harus berada di bucket, sedangkan data transaksi, session, dan antrean berada di database.

## Menyiapkan database dan administrator

Jalankan proses rilis dari mesin/runner terpercaya dengan environment Production yang sama dan akses database. Cadangkan database sebelum migrasi pada instalasi yang sudah berisi data.

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force
php artisan pam:admin email-administrator@domain-anda.example
```

Seeder dasar memasang konfigurasi/role; jangan menjalankan `DemoSeeder` untuk Production. Perintah administrator meminta kata sandi secara interaktif. Jangan mengunggah database Laragon atau `storage/app/demo-credentials.txt` ke repository/deployment.

Image web tidak membuat kunci, menjalankan migrasi/seeder, membuat akun, atau memulai worker/scheduler saat boot. Hal ini mencegah cold start dan penskalaan menjalankan perubahan database berulang kali.

## Build dan deployment

1. Push source beserta lockfile, Dockerfile, konfigurasi, dan dokumentasi ke repository GitHub yang dituju. File rahasia/data operasional harus tetap dikecualikan.
2. Impor repository tersebut melalui **New Project** di Vercel, atau tautkan menggunakan Vercel CLI yang sudah login. Project root adalah root repository ini. Gunakan preset **Container** yang ditentukan `vercel.json`; jangan memilih Vite sebagai aplikasi statis.
3. Isi environment di atas sebelum deployment. Jangan memasukkan `APP_KEY` atau token ke build arguments image.
4. Jalankan deployment; Dockerfile memasang dependensi sesuai `composer.lock`, memeriksa ekstensi PHP, dan membangun aset sesuai `package-lock.json`.
5. Periksa log startup, `/up`, halaman login dan login sesungguhnya, aset `/build/...`, operasi database, upload/download privat, serta ekspor. Status build berhasil belum membuktikan operasi database atau penyimpanan berfungsi.

Untuk memeriksa image di mesin yang memiliki Docker:

```sh
docker build -f Dockerfile.vercel -t pam-rw10:vercel .
docker run --rm --env-file /path/aman/pam-production.env -p 8080:80 pam-rw10:vercel
```

File environment contoh pada command di atas harus berada di luar repository dan berisi konfigurasi layanan yang benar. Scaffold ini tidak menyertakan akun Vercel, database/bucket cloud, atau kredensial pengguna.

## Worker dan scheduler

Worker dan aplikasi web harus memakai database antrean dan cache yang sama. Contoh command pada layanan worker PHP:

```sh
php artisan queue:work --queue=whatsapp,default --sleep=1 --tries=1 --timeout=300
```

Atur proses manager agar worker otomatis dimulai ulang dan direstart setelah setiap rilis. `DB_QUEUE_RETRY_AFTER` harus lebih lama dari timeout worker; default image adalah 360 detik. Gunakan konfigurasi retry dan penanganan hasil tidak pasti WhatsApp yang sudah dijelaskan dalam [panduan WhatsApp](WHATSAPP.md).

Jalankan scheduler Laravel setiap menit pada layanan scheduler eksternal:

```cron
* * * * * cd /path/aplikasi && php artisan schedule:run >> /path/log-scheduler 2>&1
```

Vercel Hobby Cron dibatasi sekali sehari dengan ketepatan per jam, sehingga tidak memenuhi jadwal pengingat setiap menit. Vercel Cron juga memanggil endpoint HTTP, bukan menjalankan Artisan secara langsung. Proyek ini tidak menyediakan endpoint publik untuk menjalankan Artisan atau memulai worker dari request web.

## Verifikasi dan status rilis

Saat menyiapkan scaffold ini, Docker tidak tersedia di workspace sehingga build image belum dijalankan. Jangan menyatakan deployment berhasil sebelum tersedia URL deployment, build selesai, dan pemeriksaan aplikasi yang relevan lulus. Integrasi WhatsApp tetap **Belum Terhubung** sampai kredensial provider, template yang disetujui, serta persetujuan penerima dikonfigurasi melalui aplikasi.
