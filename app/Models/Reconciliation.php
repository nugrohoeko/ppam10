<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reconciliation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reconciled_on' => 'date', 'statement_balance' => 'integer', 'book_balance' => 'integer', 'difference' => 'integer'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
