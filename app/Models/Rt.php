<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rt extends Model
{
    protected $guarded = [];

    protected $table = 'rts';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function operators()
    {
        return $this->belongsToMany(User::class, 'operator_rt');
    }
}
