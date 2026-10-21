<?php

namespace App\Support;

class PhoneNumber
{
    public static function canonical(?string $phone): ?string
    {
        if ($phone === null || ! preg_match('/^\+?[0-9\s().-]+$/D', trim($phone))) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }
}
