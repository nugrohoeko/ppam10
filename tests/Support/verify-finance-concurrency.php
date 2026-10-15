<?php

/**
 * Run with development MySQL credentials: php tests/Support/verify-finance-concurrency.php
 * Uses a fresh random database and two real PHP processes; always drops only that database.
 * Never migrates, seeds, truncates, or writes the application's configured database.
 */
use App\Models\Account;
use App\Models\CashEntry;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Role;
use App\Models\Rt;
use App\Models\TariffScheme;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$applicationDatabase = DB::connection('mysql')->getDatabaseName();
$assertTemporaryDatabase = static function (string $database) use ($applicationDatabase): void {
    if ($database === $applicationDatabase || ! preg_match('/^ppam10_finrace_[a-f0-9]{12}$/D', $database)) {
        throw new RuntimeException('Refusing a database that is not the isolated concurrency test database.');
    }
};

$switch = function (string $database) {
    $configuration = DB::connection('mysql')->getConfig();
    $configuration['database'] = $database;
    $configuration['url'] = null;
    config(['database.default' => 'mysql', 'database.connections.mysql' => $configuration]);
    DB::purge('mysql');
};
if (($argv[1] ?? '') === 'worker') {
    $assertTemporaryDatabase($argv[2] ?? '');
    $switch($argv[2]);
    $slept = false;
    DB::listen(function ($query) use (&$slept) {
        if (! $slept && str_contains($query->sql, 'periods') && str_contains(strtolower($query->sql), 'for update')) {
            $slept = true;
            usleep(700000); // Hold the acquired lock while the other process attempts to post.
        }
    });
    while (microtime(true) < (float) $argv[5]) {
        usleep(10000);
    }
    try {
        $payment = app(PaymentService::class)->pay(User::firstOrFail(), [
            'customer_id' => Customer::firstOrFail()->id, 'account_id' => Account::firstOrFail()->id,
            'payment_method_id' => PaymentMethod::firstOrFail()->id, 'amount' => (int) $argv[4],
            'paid_on' => today()->toDateString(), 'idempotency_key' => $argv[3],
        ]);
        echo json_encode(['status' => 'posted', 'id' => $payment->id]);
    } catch (ValidationException $e) {
        echo json_encode(['status' => 'validation', 'fields' => array_keys($e->errors())]);
    }
    exit;
}
$database = 'ppam10_finrace_'.bin2hex(random_bytes(6));
$assertTemporaryDatabase($database);
$created = false;
try {
    try {
        DB::connection('mysql')->selectOne('SELECT 1 AS available');
    } catch (Throwable $e) {
        echo "SKIP: MySQL unavailable. No application database was modified.\n";
        exit(0);
    }
    DB::connection('mysql')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $switch($database);
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--force' => true]);
    $actor = User::create(['name' => 'Concurrency test', 'email' => 'race@example.test', 'password' => bin2hex(random_bytes(12)), 'role_id' => Role::where('slug', 'admin')->value('id'), 'active' => true]);
    $customer = Customer::create(['number' => 'RACE-000001', 'name' => 'Race customer', 'address' => 'Test', 'rt_id' => Rt::first()->id, 'category_id' => Category::where('name', 'Rumah')->value('id'), 'status' => 'active', 'joined_on' => today()->subYear()->toDateString()]);
    $meter = Meter::create(['customer_id' => $customer->id, 'serial' => 'RACE-METER', 'initial_reading' => '0', 'installed_on' => today()->subYear()->toDateString(), 'active' => true]);
    foreach ([2 => 45000, 1 => 75000] as $months => $amount) {
        $date = today()->startOfMonth()->subMonths($months);
        $period = Period::create(['name' => $date->format('Y-m'), 'starts_on' => $date->toDateString(), 'ends_on' => $date->copy()->endOfMonth()->toDateString(), 'due_date' => $date->copy()->day(20)->toDateString()]);
        $reading = Reading::create(['customer_id' => $customer->id, 'meter_id' => $meter->id, 'period_id' => $period->id, 'user_id' => $actor->id, 'previous_reading' => '0', 'current_reading' => '20', 'usage' => '20', 'status' => 'locked', 'customer_snapshot' => ['number' => $customer->number, 'name' => $customer->name, 'rt_id' => $customer->rt_id, 'category_id' => $customer->category_id]]);
        Invoice::create(['number' => 'RACE-'.$months, 'customer_id' => $customer->id, 'reading_id' => $reading->id, 'period_id' => $period->id, 'tariff_scheme_id' => TariffScheme::where('category_id', $customer->category_id)->value('id'), 'customer_snapshot' => $reading->customer_snapshot, 'tariff_snapshot' => [], 'lines' => [], 'usage' => '20', 'water_amount' => $amount, 'total' => $amount, 'paid_amount' => 0, 'status' => 'issued', 'due_date' => $period->due_date, 'user_id' => $actor->id]);
    }
    $now = today()->startOfMonth();
    Period::create(['name' => $now->format('Y-m'), 'starts_on' => $now->toDateString(), 'ends_on' => $now->copy()->endOfMonth()->toDateString(), 'due_date' => $now->copy()->day(20)->toDateString()]);
    $run = function (array $keys, int $amount) use ($database) {
        $start = (string) (microtime(true) + 2);
        $processes = array_map(fn ($key) => new Process([PHP_BINARY, __FILE__, 'worker', $database, $key, (string) $amount, $start], dirname(__DIR__, 2)), $keys);
        foreach ($processes as $process) {
            $process->setTimeout(30);
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Worker failed: '.$process->getErrorOutput());
            }
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $results[] = $result;
        }

        return $results;
    };
    $key = 'same-key-'.bin2hex(random_bytes(12));
    $same = $run([$key, $key], 100000);
    if ($same[0]['status'] !== 'posted' || $same[1]['status'] !== 'posted' || $same[0]['id'] !== $same[1]['id'] || Payment::count() !== 1 || CashEntry::count() !== 1 || PaymentAllocation::sum('amount') != 100000 || Invoice::sum('paid_amount') != 100000) {
        throw new RuntimeException('Same-key invariant failed: '.json_encode($same));
    }
    echo "PASS: two concurrent same-key payments returned one receipt, one cash entry, exact allocations.\n";
    $different = $run(['different-a-'.bin2hex(random_bytes(8)), 'different-b-'.bin2hex(random_bytes(8))], 20000);
    $statuses = array_count_values(array_column($different, 'status'));
    if (($statuses['posted'] ?? 0) !== 1 || ($statuses['validation'] ?? 0) !== 1 || Payment::count() !== 2 || CashEntry::count() !== 2 || PaymentAllocation::sum('amount') != 120000 || Invoice::sum('paid_amount') != 120000) {
        throw new RuntimeException('Distinct-key overpayment invariant failed: '.json_encode($different));
    }
    echo "PASS: concurrent different-key final payments serialized; one accepted, one overpayment rejected.\n";
} finally {
    if ($created) {
        $assertTemporaryDatabase($database);
        DB::connection('mysql')->statement('DROP DATABASE `'.$database.'`');
        echo "Temporary test database removed. Application database unchanged.\n";
    }
}
