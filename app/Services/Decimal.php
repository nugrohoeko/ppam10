<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Decimal quantities stay strings; currency is rounded once to whole rupiah. */
final class Decimal
{
    public static function normalize(string|int $value, int $scale = 3): string
    {
        $parts = explode('.', (string) $value, 2);
        if (! preg_match('/^\d+(?:\.\d+)?$/D', (string) $value) || ($scale < strlen($parts[1] ?? '') && trim(substr($parts[1], $scale), '0') !== '')) {
            throw ValidationException::withMessages(['value' => 'Gunakan angka positif dengan maksimal '.$scale.' angka desimal.']);
        }

        return bcadd((string) $value, '0', $scale);
    }

    public static function add(string|int $a, string|int $b, int $scale = 3): string
    {
        return bcadd((string) $a, (string) $b, $scale);
    }

    public static function sub(string|int $a, string|int $b, int $scale = 3): string
    {
        return bcsub((string) $a, (string) $b, $scale);
    }

    public static function mul(string|int $a, string|int $b, int $scale = 6): string
    {
        return bcmul((string) $a, (string) $b, $scale);
    }

    public static function cmp(string|int $a, string|int $b, int $scale = 6): int
    {
        return bccomp((string) $a, (string) $b, $scale);
    }

    public static function money(string|int $value, string $rounding = 'half_up'): int
    {
        $negative = bccomp((string) $value, '0', 6) < 0;
        $absolute = $negative ? bcmul((string) $value, '-1', 6) : (string) $value;
        $integer = bcadd($absolute, '0', 0);
        $fraction = bcsub($absolute, $integer, 6);
        $increment = match ($rounding) {
            'up' => bccomp($fraction, '0', 6) > 0,
            'down' => false,
            default => bccomp($fraction, '0.5', 6) >= 0,
        };
        if (bccomp($integer, '9000000000000000', 0) > 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal melampaui batas aman pencatatan rupiah.']);
        }
        $result = (int) bcadd($integer, $increment ? '1' : '0', 0);

        return $negative ? -$result : $result;
    }
}
