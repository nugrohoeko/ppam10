<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBatch extends Model
{
    protected $table = 'whatsapp_batches';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['customer_ids' => 'array', 'filters' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }
}
