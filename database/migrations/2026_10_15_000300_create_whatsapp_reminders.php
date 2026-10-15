<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('whatsapp_consent_at')->nullable();
            $table->text('whatsapp_consent_source')->nullable();
            $table->string('whatsapp_consent_phone', 20)->nullable();
            $table->foreignId('whatsapp_consent_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('provider_name', 100);
            $table->string('language', 20)->default('id');
            $table->text('body');
            $table->json('variables');
            $table->boolean('active')->default(true);
            $table->string('status', 30)->default('DRAFT');
            $table->string('provider_template_id', 100)->nullable();
            $table->string('synced_body_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_name', 'language']);
        });
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('meta');
            $table->string('graph_version', 20)->default('v25.0');
            $table->string('phone_number_id', 100)->nullable();
            $table->string('waba_id', 100)->nullable();
            $table->string('sender_name')->nullable();
            $table->text('access_token')->nullable();
            $table->text('app_secret')->nullable();
            $table->text('verify_token')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->string('pam_name')->default('PAM RW.10');
            $table->text('payment_account')->nullable();
            $table->string('treasurer_contact')->nullable();
            $table->boolean('auto_enabled')->default(false);
            $table->string('send_time', 5)->default('09:00');
            $table->unsignedInteger('before_days')->default(3);
            $table->boolean('on_due')->default(true);
            $table->unsignedInteger('after_days')->default(3);
            $table->unsignedInteger('repeat_days')->default(7);
            $table->unsignedInteger('max_reminders')->default(3);
            $table->unsignedInteger('interval_seconds')->default(30);
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->restrictOnDelete();
            $table->foreignId('run_as_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('whatsapp_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('template_id')->constrained('whatsapp_templates')->restrictOnDelete();
            $table->string('template_hash', 64);
            $table->json('customer_ids');
            $table->json('filters')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('whatsapp_reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_id')->constrained('whatsapp_templates')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('trigger', 20)->default('manual');
            $table->string('schedule_key')->nullable();
            $table->string('dedup_key', 64)->unique();
            $table->string('active_key', 100)->nullable()->unique();
            $table->string('status', 30)->default('queued')->index();
            $table->string('phone', 20)->nullable();
            $table->text('body')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('invoice_ids');
            $table->bigInteger('balance')->default(0);
            $table->json('template_snapshot');
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });
        foreach (DB::table('roles')->where('slug', 'admin')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique(array_merge($permissions, ['whatsapp.view', 'whatsapp.send', 'whatsapp.templates', 'whatsapp.configure', 'whatsapp.schedule', 'whatsapp.consent']))))]);
        }
    }

    public function down(): void
    {
        if (DB::table('whatsapp_reminders')->exists()) {
            throw new RuntimeException('Riwayat WhatsApp harus tetap tersimpan. Ekspor dan arsipkan sebelum rollback.');
        }
        Schema::dropIfExists('whatsapp_reminders');
        Schema::dropIfExists('whatsapp_batches');
        Schema::dropIfExists('whatsapp_settings');
        Schema::dropIfExists('whatsapp_templates');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_consent_by');
            $table->dropColumn(['whatsapp_consent_at', 'whatsapp_consent_source', 'whatsapp_consent_phone']);
        });
    }
};
