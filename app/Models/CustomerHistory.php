<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerHistory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
