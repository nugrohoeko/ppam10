<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Customer extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'whatsapp_consent_at' => 'datetime'];
    }

    public function rt()
    {
        return $this->belongsTo(Rt::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function meters()
    {
        return $this->hasMany(Meter::class);
    }

    public function activeMeter()
    {
        return $this->hasOne(Meter::class)->where('active', true)->latestOfMany();
    }

    public function readings()
    {
        return $this->hasMany(Reading::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function histories()
    {
        return $this->hasMany(CustomerHistory::class);
    }

    protected static function booted(): void
    {
        static::updating(function (Customer $customer) {
            if ($customer->isDirty('number')) {
                throw ValidationException::withMessages(['number' => 'ID pelanggan bersifat permanen.']);
            }
        });
    }
}
