<?php

namespace App\Support;

final class Permissions
{
    public static function all(): array
    {
        $resources = ['dashboard' => ['Dashboard', 'view'], 'regions' => ['Wilayah', 'view create update deactivate'], 'customers' => ['Pelanggan', 'view update deactivate export import'], 'pelanggan' => ['Pelanggan', 'create delete'], 'meters' => ['Meter', 'view create update'], 'barcodes' => ['Barcode', 'view print export'], 'readings' => ['Pencatatan', 'view create update submit approve'], 'tariffs' => ['Tarif', 'view create update'], 'invoices' => ['Tagihan', 'view create issue cancel print export'], 'payments' => ['Pembayaran', 'view create cancel print export'], 'finance' => ['Keuangan', 'view create approve export'], 'accounts' => ['Kas / Bank', 'view create update reconcile'], 'periods' => ['Periode', 'view create close reopen'], 'reports' => ['Laporan', 'view export'], 'users' => ['Pengguna', 'view create update deactivate'], 'roles' => ['Hak akses', 'view update'], 'settings' => ['Pengaturan', 'view update'], 'audit' => ['Audit', 'view export']];
        $actions = ['view' => 'Lihat', 'create' => 'Tambah', 'update' => 'Ubah', 'deactivate' => 'Nonaktifkan', 'delete' => 'Hapus', 'export' => 'Ekspor', 'import' => 'Impor', 'print' => 'Cetak', 'submit' => 'Ajukan', 'approve' => 'Setujui', 'issue' => 'Terbitkan', 'cancel' => 'Batalkan', 'reconcile' => 'Rekonsiliasi', 'close' => 'Tutup buku', 'reopen' => 'Buka kembali'];
        $result = [];
        foreach ($resources as $key => [$label,$list]) {
            foreach (explode(' ', $list) as $action) {
                $result[$key.'.'.$action] = $label.' · '.$actions[$action];
            }
        }

        $result['pelanggan.create'] = 'Tambah Pelanggan (pelanggan.create)';
        $result['pelanggan.delete'] = 'Hapus Pelanggan (pelanggan.delete)';
        $result['whatsapp.view'] = 'WhatsApp · Lihat penerima dan riwayat';
        $result['whatsapp.send'] = 'WhatsApp · Kirim pengingat';
        $result['whatsapp.templates'] = 'WhatsApp · Kelola template';
        $result['whatsapp.configure'] = 'WhatsApp · Kelola integrasi';
        $result['whatsapp.schedule'] = 'WhatsApp · Kelola jadwal otomatis';
        $result['whatsapp.consent'] = 'WhatsApp · Catat persetujuan pelanggan';

        return $result;
    }

    public static function forRole(string $role): array
    {
        $all = array_keys(self::all());

        return match ($role) {
            'admin' => $all,
            'ketua' => array_values(array_filter($all, fn ($p) => str_ends_with($p, '.view') || str_ends_with($p, '.export') || str_ends_with($p, '.print') || in_array($p, ['readings.approve', 'finance.approve']))),
            'operator' => ['dashboard.view', 'customers.view', 'meters.view', 'barcodes.view', 'readings.view', 'readings.create', 'readings.update', 'readings.submit'],
            'bendahara' => ['dashboard.view', 'customers.view', 'invoices.view', 'invoices.print', 'payments.view', 'payments.create', 'payments.cancel', 'payments.print', 'payments.export', 'finance.view', 'finance.create', 'finance.export', 'accounts.view', 'accounts.create', 'accounts.update', 'accounts.reconcile', 'reports.view', 'reports.export', 'periods.view'],
            default => [],
        };
    }
}
