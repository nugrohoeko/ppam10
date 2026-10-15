<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'integer'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function category()
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reversalOf()
    {
        return $this->belongsTo(CashEntry::class, 'reversal_of');
    }
}
