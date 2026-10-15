<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meter extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['initial_reading' => 'decimal:3', 'final_reading' => 'decimal:3', 'installed_on' => 'date', 'retired_on' => 'date', 'active' => 'boolean'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function readings()
    {
        return $this->hasMany(Reading::class);
    }
}
