<?php

namespace App\Services;

use App\Models\Sequence;
use Illuminate\Support\Facades\DB;

class NumberService
{
    public static function next(string $type): string
    {
        return DB::transaction(function () use ($type) {
            DB::table('sequences')->insertOrIgnore(['key' => $type, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = Sequence::where('key', $type)->lockForUpdate()->firstOrFail();
            $sequence->increment('value');
            $defaults = ['customer' => 'PAM10-', 'invoice' => 'TAG-', 'receipt' => 'KWT-'];
            $prefix = SettingService::get('number.'.$type.'.prefix', $defaults[$type] ?? strtoupper($type).'-');
            $prefix = strtr($prefix, ['{YYYY}' => now()->format('Y'), '{MM}' => now()->format('m')]);
            $digits = max(1, min(12, (int) SettingService::get('number.'.$type.'.digits', 6)));

            return $prefix.str_pad((string) $sequence->value, $digits, '0', STR_PAD_LEFT);
        }, 3);
    }
}
