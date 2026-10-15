<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'tariff_snapshot' => 'array', 'lines' => 'array', 'usage' => 'decimal:3', 'water_amount' => 'integer', 'additional_amount' => 'integer', 'discount_amount' => 'integer', 'penalty_amount' => 'integer', 'total' => 'integer', 'paid_amount' => 'integer', 'due_date' => 'date', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function reading()
    {
        return $this->belongsTo(Reading::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function tariffScheme()
    {
        return $this->belongsTo(TariffScheme::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replaces()
    {
        return $this->belongsTo(Invoice::class, 'replacement_for');
    }

    public function getRemainingAttribute(): int
    {
        return max(0, $this->total - $this->paid_amount);
    }
}
