<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeterReplacement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_final' => 'decimal:3', 'new_initial' => 'decimal:3', 'replaced_on' => 'date'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function oldMeter()
    {
        return $this->belongsTo(Meter::class, 'old_meter_id');
    }

    public function newMeter()
    {
        return $this->belongsTo(Meter::class, 'new_meter_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
