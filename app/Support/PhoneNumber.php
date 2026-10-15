<?php

namespace App\Support;

final class PhoneNumber
{
    /** WhatsApp Indonesian mobile numbers, without '+' as required by Cloud API. */
    public static function normalize(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '' || preg_match('/[^0-9+() .-]/', $value) || substr_count($value, '+') > 1 || (str_contains($value, '+') && ! str_starts_with($value, '+'))) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if (str_starts_with($digits, '08')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^628[1-9][0-9]{7,10}$/D', $digits) ? $digits : null;
    }
}
