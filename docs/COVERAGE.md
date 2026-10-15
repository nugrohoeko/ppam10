# Cakupan implementasi dan verifikasi

Dokumen ini memetakan implementasi ke kebutuhan. Seluruh angka operasional berasal dari database. Lihat `ARCHITECTURE.md` untuk keputusan bisnis dan relasi data.

| Kebutuhan | Implementasi | Verifikasi otomatis |
|---|---|---|
| RW.10, RT.01–09 awal, wilayah dinamis | Migration domain, DatabaseSeeder, AdminController | SmokePagesTest dan seeding tes domain |
| Autentikasi, permission, RT operator | AuthController, ActiveUser, User, pemeriksaan server | AccessAndWorkflowTest, FinanceTest, ReportTest |
| Administrator terakhir tetap memiliki akses | Transaksi/row lock dan protectAdmin | AccessAndWorkflowTest |
| Pelanggan permanen dan riwayat | CustomerService, customer_histories, NumberService | AccessAndWorkflowTest |
| Meter terpisah dan penggantian | MeterService, meters, meter_replacements | CoreDomainTest |
| Template XLSX, pratinjau, baris gagal, impor transaksi | ImportController dan halaman impor | ReportTest, serial ganda dan commit ulang |
| Code 128 satu/terpilih/filter, PDF dan konfigurasi label | BarcodeController, Picqer PNG, label Blade | ReportTest, PDF valid dan RT di luar penugasan |
| Scanner kamera dan input manual | Pencatatan dan ZXing lokal | Browser smoke script; kamera fisik perlu diuji |
| Meter draft/ajuan/verifikasi/koreksi, duplikasi, periode terlewat | MeterService dan constraint readings | CoreDomainTest, AccessAndWorkflowTest |
| Tarif progresif, desimal, included/additive/minimum | TariffService, Decimal, simulator | CoreDomainTest |
| Seluruh contoh tarif dan batas 20/21/40/41 | Seed skema dan TariffService | CoreDomainTest::test_all_required_tariff_examples_and_decimal_rounding |
| Lapisan, versi efektif, snapshot tarif/pelanggan | TariffService dan BillingService | CoreDomainTest, AccessAndWorkflowTest |
| Invoice preview/terbit/batal/pengganti, individual/kolektif | InvoiceController, BillingService, revision/active_slot | CoreDomainTest, AccessAndWorkflowTest |
| Cicilan, beberapa periode, alokasi manual/tertua | PaymentService dan payment_allocations | FinanceTest |
| Idempotensi, kelebihan bayar, pembalik | PaymentService dan cash_entries | FinanceTest; skrip MySQL concurrency |
| Kuitansi PDF dan bukti privat | PaymentController dan disk local | FinanceTest |
| Pemasukan/pengeluaran, approval, transfer, saldo, rekonsiliasi | FinanceService dan FinanceController | FinanceTest |
| Tutup buku/pembukaan kembali | BillingService, FinanceService, PaymentService | CoreDomainTest, FinanceTest |
| Dashboard/grafik transaksi nyata | DashboardController dan Chart.js | SmokePagesTest; browser smoke script |
| Lima belas laporan | ReportService::TYPES | ReportTest menjalankan seluruh jenis |
| Periode invoice terpisah tanggal kas | period_id dan date_from/date_to | ReportTest dan FinanceTest |
| Sejarah RT/kategori pada pemakaian/invoice/penerimaan | Snapshot reading/invoice serta alokasi | ReportTest |
| XLSX numerik, formula aman, filter dan paginasi penuh | ExportService | ReportTest membaca ulang berkas dan tipe sel |
| PDF identitas/filter/total/halaman/header berulang | ExportService dan Blade | ReportTest, FinanceTest |
| Ekspor besar antrean, privat, pemilik dan izin | GenerateReportExport dan cache status | ReportTest memproses job sync dan menolak pengguna lain |
| Audit beralasan, sebelum/sesudah | AuditService dan audit_logs | CoreDomainTest, AccessAndWorkflowTest, ReportTest |
| Pengingat WhatsApp individual / kolektif, filter, gabungan tagihan | WhatsAppController, RecipientService, batch pratinjau | WhatsAppRecipientTest, WhatsAppAccessTest |
| Cloud API resmi, template dinamis, kredensial terenkripsi, webhook | CloudApiService, TemplateService, IntegrationService | WhatsAppProviderTest dengan HTTP tiruan |
| Antrean, saldo terbaru, idempotensi, jadwal, batas dan jeda | DeliveryService, SendWhatsAppReminder, whatsapp:dispatch | WhatsAppDeliveryTest termasuk alur HTTP sampai webhook |
| Permission WhatsApp, persetujuan nomor, scope RT | Pemeriksaan server/controller/service/worker dan UI | WhatsAppAccessTest, WhatsAppRecipientTest, WhatsAppDeliveryTest |
| Instalasi, deployment, backup/restore, panduan role | Dokumen INSTALLATION, ARCHITECTURE, USER_GUIDE | Prosedur operasional, bukan klaim restore otomatis |

## Menjalankan pemeriksaan

```powershell
php artisan test
npm run build
php tests/Support/verify-finance-concurrency.php
```

PHPUnit memakai SQLite dalam memori sesuai `phpunit.xml`. Jalankan skrip concurrency pada database MySQL pengembangan/staging yang telah dimigrasi; baca script dahulu karena ia membuat fixture dan menjalankan proses paralel. Script tersebut tidak menggantikan uji beban produksi.

Untuk browser smoke, jalankan web server lokal dan pastikan dependensi npm serta Chrome tersedia:

```powershell
node tests/browser-smoke.mjs
```

Lihat script untuk URL basis serta cara kredensial demo dibaca. Jangan menggunakan kredensial produksi pada skrip demo. Hasil browser bergantung pada browser dan server yang berjalan.

Verifikasi integrasi pada 15 Oktober 2026: suite otomatis lulus 74 tes; seluruh contoh tarif, alur HTTP, pembatasan akses, transaksi, dan ekspor tercakup. Uji dua proses bersamaan pada MySQL membuktikan satu kuitansi untuk pengiriman ulang yang sama dan penolakan pembayaran bersaing yang melebihi sisa tagihan. Browser QA memeriksa 20 halaman desktop, 7 halaman ponsel, dan 5 unduhan nyata tanpa page error atau overflow horizontal. Salah satu unduhan diproses oleh worker database nyata. Screenshot dan artefak lokal tersedia di `storage/app/qa`.

## Batas verifikasi dan operasi

- Kamera perlu diperiksa pada ponsel petugas melalui HTTPS, dengan pencahayaan dan label yang sesuai. Input manual tersedia.
- PDF diuji sebagai dokumen valid. Ukuran stiker/printer fisik memerlukan cetak uji skala 100%.
- Job ekspor diuji dengan eksekusi sync dan worker database lokal nyata. Worker sebagai service, restart, retry, scheduler, pembersihan berkas kedaluwarsa, serta kapasitas ekspor besar perlu diverifikasi pada server tujuan.
- Backup/restore, TLS, DNS, izin OS, serta monitoring adalah pekerjaan deployment. Instalasi lokal belum merupakan deployment produksi.
- Posisi piutang mengikuti data sah saat laporan diminta. Aplikasi tidak mengaktifkan saldo titipan, estimasi pemakaian bulan terlewat, pembagian biaya umum per RT, maupun buku besar akuntansi double-entry.
- Beberapa operator dapat ditugaskan pada RT sama. Jumlah pelanggan RT pada laporan operator adalah konteks; penjumlahan konteks antaroperator tidak berarti pelanggan unik RW.

## Penerimaan sebelum operasional

Pengurus meninjau identitas/format dokumen, wilayah, tarif, presisi meter, kebijakan prorata/nonaktif, role, akun/metode pembayaran, jatuh tempo, dan alur approval. Pada staging, lakukan satu siklus pencatatan–verifikasi–invoice–cicilan–pelunasan, kemudian pembatalan, penggantian meter, tutup buku, dan pemulihan backup. Simpan hasil penerimaan serta keputusan kebijakan.

## Pengujian tambah/hapus pelanggan

CustomerDeletionTest menguji permission pelanggan.create/pelanggan.delete yang terpisah, pengaturan role, penolakan URL/request langsung, pencabutan izin, wilayah operator, dialog ID/nama, alasan wajib, tunggakan penuh/sebagian, soft delete, audit, dan keunikan ID permanen. DeletedCustomerHistoryTest memastikan laporan serta dashboard keuangan tidak kehilangan transaksi, kuitansi/lampiran tetap dapat diakses sesuai hak, penggantian meter tetap tersimpan, serta piutang hasil pembalikan dapat dilunasi kembali.

Verifikasi fitur tambah/hapus: suite lengkap 94 tes / 815 assertions lulus. Browser menguji formulir tambah, konfirmasi ID/nama/alasan pada desktop dan ponsel, serta checkbox permission seluruh role tanpa page error. Browser memakai database SQLite sementara yang dibuat dan dibersihkan otomatis; tidak menghapus pelanggan pada database operasional. Jalankan ulang dengan node tests/customer-ui-smoke.mjs (Chrome dan pdo_sqlite diperlukan).

CustomerPermissionMigrationTest memverifikasi pemindahan grant lama tanpa memberi hak hapus ke role lain, serta penolakan rollback yang akan membuat arsip terlihat kembali. Penyimpanan impor juga diperiksa oleh CustomerPolicy agar izin import tidak dapat melewati pelanggan.create.

## Pengujian pengingat WhatsApp

Suite lengkap setelah fitur WhatsApp: **141 tes / 1.168 assertions lulus**. Sebanyak 47 tes WhatsApp memeriksa pemilihan lintas halaman/filter RT, gabungan beberapa periode, variabel dan data kosong, format nomor, persetujuan penerima, izin langsung dan pencabutannya, saldo lunas/sebagian yang berubah, masa berlaku dan pemilik batch, konfirmasi ganda, job ganda, antrean atomik, template yang berubah, jadwal dan batas pengulangan, jeda aktual, respons API tidak pasti, kredensial terenkripsi, serta webhook HMAC dengan status yang datang tidak berurutan.

Pengujian alur lengkap menjalankan request pratinjau → pembayaran sebagian/pelunasan → konfirmasi ulang → handler job → Cloud API tiruan → webhook bertanda tangan → riwayat dibaca. Tidak ada panggilan penyedia atau pengiriman pelanggan nyata dalam pengujian ini.

`node tests/whatsapp-ui-smoke.mjs` memeriksa pemilihan, pratinjau, konfirmasi satu pesan/satu job, penolakan konfirmasi ganda, riwayat, template, dan pengaturan pada desktop 1440 px serta ponsel 390 px. Database SQLite dan kata sandi dibuat sementara; worker tidak dijalankan. Hasil: tanpa kesalahan JavaScript, tanpa overflow halaman, dan nol request penyedia. Screenshot berada di `storage/app/qa/whatsapp-*.png`.

Migrasi WhatsApp telah diterapkan pada database lokal. Koneksi operasional tetap belum dikonfigurasi dan otomatisasi nonaktif. Penerimaan nyata memerlukan akun Meta, template resmi yang disetujui, opt-in pelanggan, worker/scheduler, serta webhook HTTPS publik sesuai [WHATSAPP.md](WHATSAPP.md).
