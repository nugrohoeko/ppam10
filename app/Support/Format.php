<?php

namespace App\Support;

final class Format
{
    public static function status(?string $s): string
    {
        return ['active' => 'Aktif', 'inactive' => 'Nonaktif', 'disconnected' => 'Diputus sementara', 'draft' => 'Draft', 'submitted' => 'Diajukan', 'verified' => 'Diverifikasi', 'rejected' => 'Ditolak', 'locked' => 'Dikunci', 'issued' => 'Belum bayar', 'partial' => 'Sebagian', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan', 'posted' => 'Tercatat', 'reversed' => 'Dibalik', 'pending' => 'Menunggu persetujuan', 'in' => 'Masuk', 'out' => 'Keluar', 'income' => 'Pemasukan', 'expense' => 'Pengeluaran', 'cash' => 'Kas', 'bank' => 'Bank'][$s] ?? ($s ?? '—');
    }
}
