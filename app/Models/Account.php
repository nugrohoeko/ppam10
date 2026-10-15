<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'opening_balance' => 'integer'];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function entries()
    {
        return $this->hasMany(CashEntry::class);
    }

    public function cashEntries()
    {
        return $this->hasMany(CashEntry::class);
    }

    public function reconciliations()
    {
        return $this->hasMany(Reconciliation::class);
    }
}
