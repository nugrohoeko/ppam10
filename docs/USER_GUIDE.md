# Panduan singkat pengguna

Pengingat tagihan melalui WhatsApp tersedia pada menu **Pengingat WhatsApp**, detail pelanggan, dan daftar tagihan/tunggakan sesuai permission. Admin mengatur koneksi, template, jadwal, serta persetujuan penerima; langkah operasional dan status pengiriman dijelaskan di [WHATSAPP.md](WHATSAPP.md).

## Pertama kali

Login menggunakan akun yang dibuat administrator. Ganti kata sandi melalui menu akun. Menu mengikuti permission; jika tindakan tidak tersedia, minta Admin meninjau role dan penugasan. Jangan berbagi akun, karena audit mencatat pelaksana masing-masing tindakan.

Dashboard menampilkan data periode dan wilayah yang dipilih. Nilai tagihan, kas diterima, dan sisa piutang memiliki arti berbeda; pembayaran tunggakan bulan lalu yang diterima hari ini masuk penerimaan kas hari ini.

## Matriks role awal

Daftar ini menggambarkan data awal. Admin dapat menyesuaikan permission setiap role melalui menu Hak Akses. Batas RT operator tetap diperiksa pada server meskipun operator diberi permission tambahan.

| Kelompok tindakan | Ketua | Admin | Operator | Bendahara |
|---|---|---|---|---|
| Dashboard | Seluruh wilayah | Seluruh wilayah | RT penugasan | Seluruh wilayah |
| Pelanggan dan meter | Lihat | Kelola, impor, ekspor | Lihat RT penugasan | Lihat |
| Pencatatan | Lihat, verifikasi | Catat, koreksi, verifikasi | Catat, ajukan, ubah draft | — |
| Wilayah, kategori dan tarif | Lihat | Kelola dan simulasi | — | — |
| Barcode | Lihat/cetak/ekspor | Lihat/cetak/ekspor | Lihat pada RT penugasan | — |
| Tagihan | Lihat/cetak/ekspor | Buat, terbitkan, batalkan | — | Lihat/cetak |
| Pembayaran | Lihat/cetak/ekspor | Seluruh tindakan | — | Terima, cetak, batalkan |
| Pemasukan/pengeluaran | Lihat, setujui, ekspor | Seluruh tindakan | — | Catat, ekspor |
| Kas/bank | Lihat | Kelola/rekonsiliasi | — | Kelola/rekonsiliasi |
| Laporan | Lihat dan ekspor | Lihat dan ekspor | Tambahkan izin bila diperlukan | Lihat dan ekspor |
| Pengguna/role/pengaturan | Lihat sesuai permission awal | Kelola | — | — |
| Audit | Lihat/ekspor | Lihat/ekspor | — | — |

Ketua memiliki seluruh permission `.view`, `.print`, `.export`, serta persetujuan pencatatan/pengeluaran pada seed awal. Karena itu Ketua juga dapat melihat halaman administratif tanpa mengubahnya. Operator secara default tidak memiliki izin cetak atau ekspor barcode; Admin dapat memberikannya jika cetak mandiri diperlukan.

## Admin

1. Isi Identitas PAM, alamat, kontak, pengurus, informasi pembayaran, format nomor baru, kebijakan verifikasi, dan parameter label.
2. Periksa RT aktif dan kategori. Tambahkan RT melalui Data Wilayah bila layanan bertambah; nonaktifkan master yang tidak lagi digunakan.
3. Buat pengguna, pilih role, dan tentukan RT operator. Jangan menonaktifkan administrator terakhir.
4. Masukkan pelanggan beserta meter awal. Nomor pelanggan dibuat otomatis dan tidak berubah ketika format penomoran baru disesuaikan.
5. Untuk impor, unduh template XLSX, hapus contoh, isi kode RT/kategori aktif dan serial meter unik. Unggah, periksa seluruh baris, unduh laporan kesalahan bila ada, lalu impor. Tidak ada baris disimpan sebelum konfirmasi impor; seluruh baris disimpan dalam satu transaksi.
6. Buat periode dan jatuh tempo. Periksa skema tarif efektif melalui Simulator Tarif sebelum menerbitkan tagihan.
7. Buat versi tarif baru untuk perubahan periode berikutnya. Versi yang sudah dipakai tidak diedit untuk menulis ulang sejarah.
8. Verifikasi pencatatan jika diaktifkan. Buat pratinjau invoice, lalu terbitkan individual atau kolektif. Pembatalan/koreksi memerlukan alasan.
9. Pantau Audit Log. Tutup periode setelah pencatatan/tagihan dan transaksi tertunda diselesaikan. Pembukaan kembali memerlukan permission dan alasan.

Label pelanggan dapat dicetak satu, beberapa yang dicentang, atau seluruh hasil filter RT/kategori/status. Atur ukuran label, kertas, margin, kolom dan salinan; lihat pratinjau lalu buka PDF. Gunakan skala cetak 100%. ID tetap sama pada cetak ulang.

## Operator

1. Buka Pencatatan Meter dan pilih periode yang benar.
2. Tekan kamera, izinkan akses, lalu arahkan ke barcode. Pemindai fisik dapat mengetik ID pada kolom pencarian. Masukkan ID/nama secara manual bila pemindaian gagal.
3. Cocokkan nama, RT, nomor meter, dan angka sebelumnya. Hanya pelanggan dalam RT penugasan dapat diakses.
4. Isi angka meter saat ini dengan titik untuk desimal. Periksa pemakaian serta estimasi tagihan. Selidiki peringatan perubahan pemakaian sebelum melanjutkan.
5. Lampirkan foto bila tersedia, tulis catatan, lalu simpan draft atau ajukan. Draft dapat diperbaiki; catatan diajukan/sah perlu mengikuti proses verifikasi/koreksi.
6. Pantau pelanggan belum dicatat dan kemajuan pencatatan. Jika ada bulan terlewat, selesaikan bulan tersebut lebih dahulu. Angka meter turun harus melalui penggantian/reset resmi.

Kamera memerlukan HTTPS dan dukungan perangkat. Login dan otorisasi tetap berlaku setelah barcode berhasil terbaca. Foto dan bukti tidak dapat dibuka lewat tautan publik.

## Bendahara

1. Buka Pembayaran, pilih pelanggan, dan periksa invoice belum lunas beserta periode asal.
2. Pilih akun kas/bank dan metode penerimaan, tanggal pembayaran sebenarnya, nominal, serta referensi/bukti.
3. Gunakan alokasi tertua atau tentukan invoice dan nominal manual. Pembayaran dapat sebagian atau meliputi beberapa periode. Jumlah alokasi harus sama dengan penerimaan.
4. Simpan pembayaran satu kali; mekanisme idempoten melindungi permintaan ulang. Sistem menolak nominal melebihi piutang yang tersedia.
5. Cetak kuitansi PDF yang merinci alokasi invoice. Periksa riwayat sebelum membatalkan pembayaran salah. Pembatalan menyimpan alasan dan transaksi pembalik, bukan menghapusnya.
6. Catat pemasukan lain/pengeluaran dengan kategori, pihak, akun, nomor bukti, serta lampiran. Jika persetujuan aktif, kas belum berubah sampai transaksi disetujui.
7. Gunakan Transfer antar-akun untuk pemindahan kas/bank; transfer tidak dihitung sebagai pemasukan atau pengeluaran operasional.
8. Rekonsiliasikan saldo buku terhadap rekening/uang fisik. Catat selisih dan alasannya; rekonsiliasi tidak membuat uang tambahan secara otomatis.

## Ketua PAM

Pilih periode dan wilayah dashboard, tinjau pemakaian, nilai tagihan, penerimaan, tunggakan, dan saldo akun. Gunakan laporan Lunas/Belum Bayar/Sebagian untuk tindak lanjut penagihan. Gunakan laporan umur piutang untuk prioritas. Jika alur persetujuan aktif, tinjau alasan dan bukti sebelum menyetujui.

## Membaca dan mengekspor laporan

Pilih salah satu dari 15 jenis laporan, RT satu/beberapa/kosong untuk semua, periode tagihan, rentang tanggal transaksi, dan filter relevan lainnya. Tekan Tampilkan laporan. Tabel 50 baris per halaman; subtotal/total dan ekspor mencakup seluruh hasil.

Contoh: untuk melihat penerimaan Februari atas rekening Januari, pilih jenis Penerimaan, periode `2026-01`, tanggal `2026-02-01` s.d. `2026-02-28`. Pembayaran rekening Januari pada Maret tidak masuk hasil itu. Jika penerimaan Februari dibatalkan pada Oktober, penerimaan Februari tetap ditampilkan dan pengembalian Oktober bernilai negatif.

“Sisa kini” dihitung dari seluruh pembayaran sah, sedangkan “Pembayaran terpilih” memakai filter transaksi. Untuk laporan saldo akun sebenarnya, jangan membatasi RT/kategori/periode tagihan; biaya umum memang tidak memiliki pembagian RT.

Pilih Excel untuk mengolah angka, atau PDF untuk mencetak. Lebih dari 2.000 baris otomatis masuk antrean. Halaman proses menampilkan tombol Periksa hasil; berkas siap hanya dapat diunduh pemohon selama satu jam dengan izin yang masih sesuai.

## Tambah dan hapus pelanggan

Admin mengatur dua permission terpisah pada menu Role & Permission: `pelanggan.create` untuk menambah pelanggan dan `pelanggan.delete` untuk menghapus pelanggan. Role lain tidak mendapat hak hapus secara otomatis. Hak lama `customers.create` pada instalasi sebelumnya dipindahkan ke `pelanggan.create` melalui migration, tanpa mengubah hak lainnya. Policy memeriksa izin dan RT operator pada server. Menyimpan hasil impor juga memerlukan pelanggan.create bersama customers.import; izin impor saja tidak dapat melewati pembatasan tambah pelanggan.

Tombol Tambah Pelanggan membuka formulir pendaftaran beserta meter awal. Tombol Hapus Pelanggan tersedia pada daftar dan detail pelanggan jika diizinkan. Dialog penghapusan menampilkan ID serta nama; periksa keduanya, isi alasan, lalu konfirmasi. Jika ada tagihan belum lunas, aplikasi menolak penghapusan dan menampilkan total sisa tagihan.

Penghapusan menggunakan soft delete: pelanggan tidak muncul pada daftar, barcode, atau pencatatan operasional. Meter, tagihan, kuitansi, pembayaran, laporan, dan audit tetap tersimpan. Nomor pelanggan tidak dapat dipakai kembali. Penghapusan beserta alasan, pelaksana, waktu, dan identitas pelanggan dicatat pada Audit Log. Jika pembatalan pembayaran kemudian membuka kembali piutang pelanggan arsip, bendahara dapat melunasinya melalui riwayat pembayaran pelanggan tersebut; tagihan baru tidak diterbitkan untuk pelanggan terhapus.
