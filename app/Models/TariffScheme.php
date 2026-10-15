<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TariffScheme extends Model
{
    protected static function booted(): void
    {
        static::updating(function (TariffScheme $scheme) {
            if ($scheme->getOriginal('locked_at') && array_diff(array_keys($scheme->getDirty()), ['effective_until', 'updated_at'])) {
                throw ValidationException::withMessages(['tariff' => 'Versi tarif sudah digunakan dan dikunci; buat versi baru.']);
            }
        });
        static::deleting(function (TariffScheme $scheme) {
            if ($scheme->locked_at || $scheme->invoices()->exists()) {
                throw ValidationException::withMessages(['tariff' => 'Versi tarif historis tidak dapat dihapus.']);
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'included_usage' => 'decimal:3', 'discount_value' => 'decimal:3', 'locked_at' => 'datetime', 'base_amount' => 'integer', 'admin_fee' => 'integer', 'additional_fee' => 'integer', 'penalty_amount' => 'integer', 'rounding_unit' => 'integer'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function tiers()
    {
        return $this->hasMany(TariffTier::class)->orderBy('position');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
