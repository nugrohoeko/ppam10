<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->json('permissions');
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
        });
        Schema::create('rts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('rw')->default('10');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('operator_rt', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rt_id')->constrained()->restrictOnDelete();
            $t->unique(['user_id', 'rt_id']);
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value')->nullable();
            $t->timestamps();
        });
        Schema::create('sequences', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->unsignedBigInteger('value')->default(0);
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->string('name');
            $t->text('address');
            $t->foreignId('rt_id')->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->constrained()->restrictOnDelete();
            $t->string('phone')->nullable();
            $t->string('status')->default('active')->index();
            $t->date('joined_on');
            $t->string('location')->nullable();
            $t->string('photo_path')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('customer_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('before');
            $t->json('after');
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('meters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('serial')->unique();
            $t->decimal('initial_reading', 14, 3);
            $t->date('installed_on');
            $t->date('retired_on')->nullable();
            $t->decimal('final_reading', 14, 3)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('meter_replacements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('old_meter_id')->constrained('meters')->restrictOnDelete();
            $t->foreignId('new_meter_id')->constrained('meters')->restrictOnDelete();
            $t->decimal('old_final', 14, 3);
            $t->decimal('new_initial', 14, 3);
            $t->date('replaced_on');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->date('due_date');
            $t->timestamp('locked_at')->nullable();
            $t->foreignId('locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('tariff_schemes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->unsignedInteger('version');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('method')->default('progressive');
            $t->string('base_mode')->default('included');
            $t->bigInteger('base_amount')->default(0);
            $t->decimal('included_usage', 14, 3)->default(0);
            $t->bigInteger('admin_fee')->default(0);
            $t->bigInteger('additional_fee')->default(0);
            $t->string('discount_type')->default('nominal');
            $t->decimal('discount_value', 14, 3)->default(0);
            $t->bigInteger('penalty_amount')->default(0);
            $t->unsignedInteger('grace_days')->default(0);
            $t->unsignedInteger('rounding_unit')->default(1);
            $t->string('rounding_mode')->default('half_up');
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->unique(['category_id', 'version']);
            $t->unique(['category_id', 'effective_from']);
        });
        Schema::create('tariff_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tariff_scheme_id')->constrained()->cascadeOnDelete();
            $t->decimal('lower_bound', 14, 3);
            $t->decimal('upper_bound', 14, 3)->nullable();
            $t->bigInteger('rate');
            $t->unsignedInteger('position');
            $t->timestamps();
            $t->unique(['tariff_scheme_id', 'position']);
        });
        Schema::create('readings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('meter_id')->constrained()->restrictOnDelete();
            $t->foreignId('period_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->decimal('previous_reading', 14, 3);
            $t->decimal('current_reading', 14, 3);
            $t->decimal('usage', 14, 3);
            $t->string('status')->default('draft')->index();
            $t->string('photo_path')->nullable();
            $t->text('notes')->nullable();
            $t->text('warning')->nullable();
            $t->json('customer_snapshot');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['customer_id', 'period_id']);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('reading_id')->constrained()->restrictOnDelete();
            $t->foreignId('period_id')->constrained()->restrictOnDelete();
            $t->foreignId('tariff_scheme_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->json('customer_snapshot');
            $t->json('tariff_snapshot');
            $t->json('lines');
            $t->decimal('usage', 14, 3);
            $t->bigInteger('water_amount');
            $t->bigInteger('additional_amount')->default(0);
            $t->bigInteger('discount_amount')->default(0);
            $t->bigInteger('penalty_amount')->default(0);
            $t->bigInteger('total');
            $t->bigInteger('paid_amount')->default(0);
            $t->string('status')->default('draft')->index();
            $t->date('due_date');
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->foreignId('replacement_for')->nullable()->constrained('invoices')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['customer_id', 'period_id', 'revision']);
            $t->unique(['reading_id', 'revision']);
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->boolean('active_slot')->nullable()->default(true);
            $t->unique(['customer_id', 'period_id', 'active_slot']);
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type');
            $t->string('number')->nullable();
            $t->bigInteger('opening_balance')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount');
            $t->date('paid_on')->index();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('reference')->nullable();
            $t->string('proof_path')->nullable();
            $t->string('idempotency_key')->unique();
            $t->string('request_hash', 64)->nullable();
            $t->string('status')->default('posted');
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount');
            $t->timestamps();
            $t->unique(['payment_id', 'invoice_id']);
        });
        Schema::create('finance_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('type');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('cash_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('finance_categories')->restrictOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('entry_date')->index();
            $t->string('direction');
            $t->bigInteger('amount');
            $t->text('description');
            $t->string('reference')->nullable();
            $t->string('attachment_path')->nullable();
            $t->uuid('transfer_key')->nullable()->index();
            $t->string('status')->default('posted');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('cash_entries')->restrictOnDelete();
            $t->string('party')->nullable();
            $t->timestamps();
        });
        Schema::create('reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('reconciled_on');
            $t->bigInteger('statement_balance');
            $t->bigInteger('book_balance');
            $t->bigInteger('difference');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action')->index();
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'reconciliations', 'cash_entries', 'finance_categories', 'payment_allocations', 'payments', 'payment_methods', 'accounts', 'invoices', 'readings', 'tariff_tiers', 'tariff_schemes', 'periods', 'meter_replacements', 'meters', 'customer_histories', 'customers', 'sequences', 'settings', 'categories', 'operator_rt', 'rts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn('active');
        });
        Schema::dropIfExists('roles');
    }
};
