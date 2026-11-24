<?php

namespace App\Support;

class PhoneNumber
{
    public static function normalize(string $phone, string $defaultCountryCode = '966'): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && ! str_starts_with($digits, $defaultCountryCode)) {
            $digits = $defaultCountryCode.substr($digits, 1);
        } elseif (! str_starts_with($digits, $defaultCountryCode) && strlen($digits) >= 7) {
            $digits = $defaultCountryCode.$digits;
        }

        return '+'.$digits;
    }
}
