<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\FinanceCategory;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Rt;
use App\Models\Setting;
use App\Models\TariffScheme;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Production master data only. Accounts are provisioned through pam:admin. */
    public function run(): void
    {
        foreach (['admin' => 'Admin', 'ketua' => 'Ketua PAM', 'operator' => 'Operator', 'bendahara' => 'Bendahara'] as $slug => $name) {
            Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'permissions' => Permissions::forRole($slug)]);
        }
        for ($i = 1; $i <= 9; $i++) {
            $code = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            Rt::firstOrCreate(['code' => $code], ['name' => 'RT.'.$code, 'rw' => '10', 'active' => true]);
        }
        $house = Category::firstOrCreate(['name' => 'Rumah'], ['active' => true]);
        $public = Category::firstOrCreate(['name' => 'Fasilitas Umum'], ['active' => true]);
        foreach ([[$house, 'Rumah progresif', 'included', 45000, '20', [['20', '40', 3000], ['40', null, 3500]]], [$public, 'Fasilitas umum', 'additive', 0, '0', [['0', null, 1500]]]] as [$category,$name,$mode,$base,$quota,$tiers]) {
            $scheme = TariffScheme::firstOrCreate(['category_id' => $category->id, 'version' => 1], [
                'name' => $name, 'effective_from' => '2000-01-01', 'method' => 'progressive', 'base_mode' => $mode, 'base_amount' => $base,
                'included_usage' => $quota, 'admin_fee' => 0, 'additional_fee' => 0, 'discount_type' => 'nominal', 'discount_value' => '0',
                'penalty_amount' => 0, 'grace_days' => 0, 'rounding_unit' => 1, 'rounding_mode' => 'half_up',
            ]);
            if ($scheme->wasRecentlyCreated) {
                foreach ($tiers as $position => [$lower,$upper,$rate]) {
                    $scheme->tiers()->create(['lower_bound' => $lower, 'upper_bound' => $upper, 'rate' => $rate, 'position' => $position + 1]);
                }
            }
        }
        Account::firstOrCreate(['name' => 'Kas PAM'], ['type' => 'cash', 'opening_balance' => 0, 'active' => true]);
        foreach (['Tunai', 'Transfer bank'] as $name) {
            PaymentMethod::firstOrCreate(['name' => $name], ['active' => true]);
        }
        foreach (['income' => ['Sambungan baru', 'Bantuan / subsidi', 'Pemasukan lain'], 'expense' => ['Listrik pompa', 'Perawatan dan perbaikan', 'Alat dan perlengkapan', 'Honorarium petugas', 'Administrasi', 'Pengeluaran lain']] as $type => $names) {
            foreach ($names as $name) {
                FinanceCategory::firstOrCreate(['name' => $name], ['type' => $type, 'active' => true]);
            }
        }
        $defaults = [
            'pam_name' => 'PAM RW.10', 'pam_address' => 'Lingkungan RW.10', 'pam_contact' => '', 'pam_management' => '', 'payment_info' => '', 'invoice_note' => '',
            'meter_precision' => 3, 'reading_verification' => true, 'usage_spike_percent' => 100, 'usage_drop_percent' => 70,
            'inactive_policy' => 'block', 'midperiod_policy' => 'full', 'payment_allocation_mode' => 'oldest', 'expense_approval_required' => false,
            'number.customer.prefix' => 'PAM10-', 'number.customer.digits' => 6, 'number.invoice.prefix' => 'TAG-{YYYY}{MM}-', 'number.invoice.digits' => 6,
            'number.receipt.prefix' => 'KWT-{YYYY}{MM}-', 'number.receipt.digits' => 6, 'default_due_day' => 20,
            'label_width_mm' => 62, 'label_height_mm' => 35, 'label_columns' => 3, 'label_margin_mm' => 8, 'label_paper' => 'A4', 'label_copies' => 1,
        ];
        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
