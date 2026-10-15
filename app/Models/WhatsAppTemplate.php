<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['variables' => 'array', 'active' => 'boolean', 'last_synced_at' => 'datetime'];
    }
}
