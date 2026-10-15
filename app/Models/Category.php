<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function tariffSchemes()
    {
        return $this->hasMany(TariffScheme::class);
    }
}
