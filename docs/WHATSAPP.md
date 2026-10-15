# Pengingat tagihan WhatsApp

Menu **Pengingat WhatsApp** menyediakan pemilihan pelanggan, pratinjau data sebenarnya, konfirmasi, antrean API, dan riwayat status. Tombol **Kirim WhatsApp** juga tersedia dari detail pelanggan dan daftar tagihan/tunggakan, sesuai permission. Integrasi menggunakan **Meta WhatsApp Cloud API**, bukan tautan `wa.me`.

## Pengaturan awal

1. Admin membuka **Pengaturan → Pengaturan WhatsApp**, atau **Pengingat WhatsApp → Pengaturan WhatsApp**. Status awal **Belum Terhubung** dan jadwal otomatis nonaktif.
2. Isi versi Graph API yang masih didukung Meta, Phone Number ID, WhatsApp Business Account ID, Access Token, App Secret, serta Verify Token webhook yang Anda tentukan sendiri. Gunakan akun WhatsApp Business dan token dengan izin yang sesuai untuk pengiriman serta pengelolaan template. Isi nama PAM, rekening/petunjuk pembayaran, dan kontak Bendahara.
3. Simpan, kemudian tekan **Uji koneksi API**. Pemeriksaan membaca identitas pengirim dan keanggotaannya dalam WABA; pemeriksaan ini tidak mengirim pesan. Perubahan kredensial atau identitas integrasi membatalkan koneksi, mematikan otomatisasi, dan mewajibkan sinkronisasi template kembali.
4. Pada Meta Developer, daftarkan callback HTTPS publik `https://domain-pam.example/whatsapp/webhook`, gunakan Verify Token yang sama, dan berlangganan `messages` serta `message_template_status_update`. POST webhook harus memiliki HMAC SHA-256 yang benar menggunakan App Secret. URL localhost tidak dapat menerima webhook dari Meta.
5. Buka **Template pesan**, buat pesan dengan variabel yang tersedia, lalu **Ajukan ke Meta** atau sinkronkan template yang sudah ada di WhatsApp Manager. Kategori pengajuan adalah `UTILITY`. Hanya template teks `BODY` yang didukung. Nama dan bahasa harus cocok; isi resmi yang telah disetujui harus persis sama dengan hasil konversi variabel lokal ke parameter bernomor. Status persetujuan dibaca dari Meta dan tidak dapat ditetapkan manual.
6. Catat persetujuan pelanggan melalui **Kelola persetujuan** pada daftar penerima. Nomor telepon saja tidak dianggap sebagai persetujuan. Simpan sumber/bukti persetujuan yang jelas. Persetujuan berlaku untuk nomor yang dinormalisasi saat dicatat; perubahan nomor mewajibkan persetujuan baru. Pencabutan manual atau pesan masuk `STOP` / `BERHENTI` menghentikan pengingat.

Kredensial memakai enkripsi Laravel di database dan tidak dikembalikan dalam formulir, JSON model, audit, atau pesan kesalahan penyedia. Kolom rahasia kosong mempertahankan nilai yang tersimpan. Lindungi dan backup `APP_KEY` instalasi bersama database; kehilangan kunci membuat kredensial terenkripsi tidak dapat dibaca. Identitas pengirim diambil dari hasil pemeriksaan API.

Kebijakan persetujuan penerima dan penggunaan template mengikuti [WhatsApp Business Messaging Policy](https://whatsappbusiness.com/policy/). Format API mengacu pada [dokumentasi resmi Meta Cloud API](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api) dan [referensi template Meta](https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/messages/template/). Persetujuan template tetap merupakan keputusan Meta; aplikasi tidak menjamin pengajuan diterima.

## Permission dan wilayah

| Permission | Akses |
|---|---|
| `whatsapp.view` | Daftar penerima dan riwayat pengiriman |
| `whatsapp.send` | Pratinjau, konfirmasi, dan pengiriman pengingat |
| `whatsapp.templates` | Membuat, mengubah, menonaktifkan, mengajukan, dan menyinkronkan template |
| `whatsapp.configure` | Kredensial, identitas PAM, koneksi, dan petunjuk pembayaran |
| `whatsapp.schedule` | Jadwal, frekuensi, batas pengingat, dan jeda pesan |
| `whatsapp.consent` | Pencatatan/pencabutan persetujuan pelanggan |

Admin mengatur permission melalui **Role & Permission**. Migrasi menambahkan permission fitur baru pada role Admin tanpa mengganti grant yang sudah ada. Role lain harus diberi akses secara eksplisit. Pengaktifan otomatisasi memerlukan `whatsapp.schedule` dan `whatsapp.send`; pengiriman otomatis memakai pengguna yang menyimpan jadwal. Pengguna tersebut harus tetap aktif, berizin, dan berhak mengakses wilayah pelanggan saat job diproses. Operator dibatasi ke RT penugasannya pada daftar, pratinjau, konfirmasi, riwayat, persetujuan, dan worker. Pemeriksaan server juga berlaku untuk request langsung.

## Pemilihan dan pengiriman

Filter RT, periode, status belum membayar/dibayar sebagian, dan minimal hari terlambat menentukan **pelanggan yang memenuhi syarat**. Sesudah pelanggan terpilih, semua tagihannya yang telah terbit dan masih bersisa digabung dalam **satu pesan**. Tagihan draft, dibatalkan, lunas, serta pelanggan arsip tidak masuk daftar aktif. Minimum hari terlambat dihitung dari jatuh tempo tagihan; nilai kosong/0 tidak membatasi keterlambatan.

Nomor Indonesia `08…`, `8…`, `62…`, dan `+62…` dinormalisasi menjadi `628…`. Nomor tidak valid ditandai pada pratinjau. Daftar menampilkan nama, ID, RT, nomor, periode, sisa masing-masing tagihan, dan total sisa pembayaran. Jumlah total mencakup tagihan terbit yang belum jatuh tempo bila pelanggan mempunyai beberapa tagihan terbuka.

Pratinjau menampilkan isi pesan sebenarnya dan alasan setiap penerima yang dikeluarkan. Variabel yang tidak dikenal ditolak saat template disimpan; data wajib yang belum tersedia diberi penanda dan mencegah pengiriman. Variabel yang didukung:

```
{nama_pelanggan} {nama_pam} {id_pelanggan} {rt}
{rincian_tunggakan} {total_tunggakan}
{rekening_pembayaran} {kontak_bendahara}
```

Rincian beberapa periode dalam parameter disambungkan dengan titik koma agar sesuai parameter teks Cloud API; baris baru ditempatkan pada isi template. Mengubah isi, bahasa, atau nama resmi membatalkan persetujuan lokal dan memerlukan sinkronisasi ulang. Template nonaktif tidak dapat digunakan oleh pengiriman manual maupun otomatis.

Konfirmasi memakai batch milik pengguna dengan masa berlaku 30 menit. Checkbox pemeriksaan wajib dicentang; perubahan template mewajibkan pratinjau baru. Pengiriman ulang konfirmasi yang sama tidak membuat pesan baru. Saldo diperiksa lagi saat konfirmasi dan saat worker memproses job. Bila sebagian dibayar setelah pratinjau, pesan memakai saldo terakhir; bila lunas, pesan dilewati. Persetujuan, nomor, scope RT, dan izin yang berubah juga diperiksa ulang.

## Antrean dan jadwal otomatis

Gunakan antrean database dan worker yang berjalan sebagai service:

```dotenv
QUEUE_CONNECTION=database
```

```powershell
php artisan queue:work --sleep=2 --tries=2 --timeout=300
php artisan schedule:work
```

Pada produksi, jalankan `schedule:run` setiap menit melalui cron/Windows Task Scheduler sebagai pengganti `schedule:work`. Restart worker sesudah deployment menggunakan `php artisan queue:restart`. Job WhatsApp mempunyai satu percobaan API; pengaturan `--tries` worker tidak mengizinkan pengulangan POST WhatsApp yang hasilnya belum pasti.

Admin dapat mengaktifkan pengingat sebelum jatuh tempo, saat jatuh tempo, dan setelah terlambat; menetapkan jam WIB, pengulangan dalam hari, batas pengingat per pelanggan untuk tagihan yang masih terutang, serta jeda antar-pesan dalam detik. Nilai hari sebelum/sesudah 0 menonaktifkan tahap tersebut. Jadwal dipilih pada menit yang ditentukan; bila scheduler tidak berjalan saat itu, pemilihan berikutnya menunggu jadwal hari berikutnya. Pesan terpilih mendapat slot antrean berjarak sesuai pengaturan; worker juga menjaga jeda aktual ketika beberapa proses atau antrean tertunda berjalan bersamaan.

Pelanggan dengan saldo terutang yang sama tidak menerima pengingat sebelum frekuensi pengulangan terpenuhi atau sesudah batas pengingat tercapai. Batas dihitung dari riwayat pengingat yang berkaitan dengan tagihan yang masih terbuka; pembayaran sebagian tidak mereset batas. Batas ini juga berlaku pada pengiriman manual. Pelunasan menghentikan pengingat, termasuk pesan yang telah berada di antrean. Otomatisasi yang dimatikan atau izin pengguna jadwal yang dicabut membatalkan kelayakan job otomatis yang belum dikirim.

## Riwayat dan penanganan kegagalan

Riwayat menyimpan identitas pelanggan, tujuan, isi pesan terakhir yang diproses, ID tagihan, total saldo, pengguna/jadwal pemicu, waktu, template, ID pesan penyedia, dan status. Snapshot identitas tetap tersedia setelah pelanggan diarsipkan. Audit mencatat perubahan persetujuan, pengaturan/template, pengantrean, dan hasil pengiriman.

| Status | Arti |
|---|---|
| Dalam antrean | Job tersimpan; belum dikirim ke API |
| Sedang diproses | Worker telah mengambil pengiriman |
| Diterima penyedia | API mengembalikan ID pesan; belum merupakan bukti diterima pelanggan |
| Dikirim / Tersampaikan / Dibaca | Pembaruan webhook Meta yang terverifikasi |
| Dilewati | Saldo lunas, data/izin/persetujuan berubah, atau batas pengingat berlaku |
| Gagal | Penyedia menolak atau antrean gagal sebelum pengiriman |
| Perlu diperiksa | Respons API atau proses terputus sehingga hasil pengiriman belum pasti |

Status dari webhook tidak boleh menurunkan status yang sudah lebih lanjut. Respons HTTP yang berhasil tanpa ID pesan juga diperlakukan sebagai hasil belum pasti. Pengiriman **Perlu diperiksa** menahan pengiriman lain untuk pelanggan tersebut dan tidak dicoba ulang otomatis; cocokkan status melalui WhatsApp Manager dan webhook untuk menghindari pesan ganda. Proses yang terhenti dipulihkan statusnya oleh scheduler tanpa mengirim POST ulang.

Pengembangan menggunakan respons API/webhook tiruan dan database browser sementara. Tidak ada pengiriman WhatsApp nyata saat pengujian otomatis. Uji nomor penerima yang disetujui pada akun Meta Anda diperlukan setelah konfigurasi operasional lengkap, dengan persetujuan pengurus untuk pengiriman tersebut.

```powershell
php artisan test --filter=WhatsApp
node tests/whatsapp-ui-smoke.mjs
```
