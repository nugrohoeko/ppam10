<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Period extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'due_date' => 'date', 'locked_at' => 'datetime'];
    }

    public function readings()
    {
        return $this->hasMany(Reading::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
