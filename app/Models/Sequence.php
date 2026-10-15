<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
