<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TariffTier extends Model
{
    protected static function booted(): void
    {
        $guard = function (TariffTier $tier) {
            if ($tier->tariffScheme()->whereNotNull('locked_at')->exists()) {
                throw ValidationException::withMessages(['tariff' => 'Lapisan pada versi tarif yang digunakan tidak dapat diubah.']);
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lower_bound' => 'decimal:3', 'upper_bound' => 'decimal:3', 'rate' => 'integer', 'position' => 'integer'];
    }

    public function tariffScheme()
    {
        return $this->belongsTo(TariffScheme::class);
    }
}
