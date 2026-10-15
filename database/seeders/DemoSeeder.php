<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\FinanceCategory;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\Role;
use App\Models\Rt;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Decimal;
use App\Services\FinanceService;
use App\Services\MeterService;
use App\Services\NumberService;
use App\Services\PaymentService;
use App\Services\SettingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoSeeder dilarang pada lingkungan production.');
        }
        $this->call(DatabaseSeeder::class);
        if (Customer::where('notes', 'Data demonstrasi PAM RW.10')->exists()) {
            $this->command?->info('Data demo telah tersedia; tidak dibuat ulang.');

            return;
        }
        $password = env('DEMO_PASSWORD') ?: Str::password(20);
        $credentials = "Kredensial demonstrasi lokal PAM RW.10\nPassword: ".$password."\n";
        DB::transaction(function () use ($password, &$credentials) {
            foreach (['admin', 'ketua', 'operator', 'bendahara'] as $slug) {
                $user = User::firstOrCreate(['email' => $slug.'@pam10.test'], ['name' => ucwords($slug).' Demo', 'password' => $password, 'role_id' => Role::where('slug', $slug)->value('id'), 'active' => true]);
                if (! $user->wasRecentlyCreated) {
                    throw new \RuntimeException('Akun demo sudah ada tanpa penanda data demo; gunakan database demonstrasi yang bersih.');
                }
                $credentials .= $slug.': '.$user->email."\n";
                if ($slug === 'operator') {
                    $user->rts()->sync(Rt::orderBy('id')->limit(3)->pluck('id')->all());
                }
            }
            $admin = User::where('email', 'admin@pam10.test')->firstOrFail();
            Auth::setUser($admin);
            $originalVerification = SettingService::get('reading_verification', true);
            SettingService::set('reading_verification', false);
            try {
                DB::transaction(function () use ($admin) {
                    $periods = collect();
                    for ($offset = 2; $offset >= 0; $offset--) {
                        $month = today()->startOfMonth()->subMonths($offset);
                        $periods->push(Period::firstOrCreate(['name' => $month->format('Y-m')], ['starts_on' => $month->toDateString(), 'ends_on' => $month->copy()->endOfMonth()->toDateString(), 'due_date' => $month->copy()->addMonth()->day(20)->toDateString()]));
                    }
                    $house = Category::where('name', 'Rumah')->firstOrFail();
                    $public = Category::where('name', 'Fasilitas Umum')->firstOrFail();
                    $names = ['Budi Santoso', 'Siti Aminah', 'Agus Setiawan', 'Dewi Lestari', 'Hendra Wijaya', 'Rina Wulandari', 'Joko Prasetyo', 'Yuni Kartika', 'Ahmad Fauzi', 'Sri Wahyuni', 'Dedi Kurniawan', 'Nur Aisyah', 'Bambang Sutrisno', 'Ratna Sari', 'Eko Saputra', 'Lina Marlina', 'Wahyu Hidayat', 'Fitri Handayani'];
                    $index = 0;
                    foreach (Rt::orderBy('code')->get() as $rt) {
                        for ($j = 0; $j < 3; $j++) {
                            $category = $j === 2 ? $public : $house;
                            $customer = Customer::create(['number' => NumberService::next('customer'), 'name' => $j === 2 ? 'Mushola RT.'.$rt->code : $names[$index++ % count($names)],
                                'address' => 'Jl. Melati No. '.($j + 1).', '.$rt->name.' RW.10', 'rt_id' => $rt->id, 'category_id' => $category->id,
                                'phone' => '0812'.str_pad((string) ($rt->id * 100 + $j), 8, '0', STR_PAD_LEFT), 'status' => 'active',
                                'joined_on' => $periods->first()->starts_on->copy()->subMonth()->day(1)->toDateString(), 'notes' => 'Data demonstrasi PAM RW.10']);
                            $meter = $customer->meters()->create(['serial' => 'DEMO-'.str_pad((string) $customer->id, 5, '0', STR_PAD_LEFT), 'initial_reading' => '100.000', 'installed_on' => $periods->first()->starts_on->copy()->subDay()->toDateString(), 'active' => true]);
                            $current = $meter->initial_reading;
                            foreach ($periods as $p => $period) {
                                $usage = (string) ($j === 2 ? 8 + $rt->id + $p : 15 + (($rt->id * 7 + $j * 13 + $p * 5) % 36));
                                if (($rt->id + $j + $p) % 4 === 0) {
                                    $usage .= '.500';
                                }
                                $current = Decimal::add($current, $usage);
                                $reading = app(MeterService::class)->save($admin, $customer, $period, ['current_reading' => $current, 'status' => 'submitted', 'notes' => 'Pencatatan demonstrasi']);
                                $invoice = app(BillingService::class)->generate($admin, $reading);
                                if (($rt->id + $j + $p) % 4 !== 0) {
                                    $amount = ($rt->id + $j + $p) % 3 === 0 ? intdiv($invoice->total, 2) : $invoice->total;
                                    $paidOn = $period->starts_on->copy()->addDays(min(10, today()->day - 1));
                                    app(PaymentService::class)->pay($admin, ['customer_id' => $customer->id, 'account_id' => Account::first()->id, 'payment_method_id' => PaymentMethod::first()->id, 'amount' => $amount, 'paid_on' => $paidOn->toDateString(), 'allocation_mode' => 'manual', 'allocations' => [$invoice->id => $amount], 'idempotency_key' => (string) Str::uuid(), 'reference' => 'DEMO-'.$invoice->number]);
                                }
                            }
                        }
                    }
                    $bank = Account::firstOrCreate(['name' => 'Bank PAM Demo'], ['type' => 'bank', 'number' => '000-DEMO', 'opening_balance' => 0, 'active' => true]);
                    foreach ($periods as $period) {
                        $date = $period->starts_on->copy()->addDays(min(12, today()->day - 1))->toDateString();
                        foreach (['Listrik pompa' => 325000, 'Perawatan dan perbaikan' => 150000, 'Honorarium petugas' => 200000] as $name => $amount) {
                            app(FinanceService::class)->record($admin, ['kind' => 'expense', 'account_id' => Account::first()->id, 'category_id' => FinanceCategory::where('name', $name)->value('id'), 'amount' => $amount, 'entry_date' => $date, 'description' => $name.' '.$period->name, 'reference' => 'DEMO-'.Str::upper(Str::random(6))]);
                        }
                    }
                    app(FinanceService::class)->record($admin, ['kind' => 'transfer', 'account_id' => Account::first()->id, 'target_account_id' => $bank->id, 'amount' => 250000, 'entry_date' => today()->toDateString(), 'description' => 'Setoran kas ke rekening PAM']);
                });
            } finally {
                SettingService::set('reading_verification', $originalVerification);
                Auth::forgetUser();
            }
        });
        if (! app()->environment('testing')) {
            file_put_contents(storage_path('app/demo-credentials.txt'), $credentials);
        }
        $this->command?->info('27 pelanggan, 3 periode, tagihan, pembayaran, pengeluaran dan transfer demo dibuat. Kredensial: storage/app/demo-credentials.txt');
    }
}
