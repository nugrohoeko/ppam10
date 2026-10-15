<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reading extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous_reading' => 'decimal:3', 'current_reading' => 'decimal:3', 'usage' => 'decimal:3', 'customer_snapshot' => 'array', 'verified_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function meter()
    {
        return $this->belongsTo(Meter::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class)->where('status', '!=', 'cancelled')->latestOfMany();
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
