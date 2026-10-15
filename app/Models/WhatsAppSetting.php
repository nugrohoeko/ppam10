<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSetting extends Model
{
    protected $table = 'whatsapp_settings';
    protected $guarded = [];
    protected $hidden = ['access_token', 'app_secret', 'verify_token'];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1])->refresh();
    }

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'app_secret' => 'encrypted', 'verify_token' => 'encrypted', 'connected_at' => 'datetime', 'auto_enabled' => 'boolean', 'on_due' => 'boolean', 'next_send_at' => 'datetime', 'last_attempt_at' => 'datetime', 'before_days' => 'integer', 'after_days' => 'integer', 'repeat_days' => 'integer', 'max_reminders' => 'integer', 'interval_seconds' => 'integer'];
    }
}
