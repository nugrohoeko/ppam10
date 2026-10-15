<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppReminder extends Model
{
    protected $table = 'whatsapp_reminders';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['invoice_ids' => 'array', 'template_snapshot' => 'array', 'customer_snapshot' => 'array', 'balance' => 'integer', 'queued_at' => 'datetime', 'processed_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime', 'last_status_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function template()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }
}
