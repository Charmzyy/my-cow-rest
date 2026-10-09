<?php

namespace App\Support;

// Kenyan mobile numbers, however they're typed (0712 345 678, 254712345678, +254-712-345678,
// and the newer 01xx numbers), become one form: +2547XXXXXXXX / +2541XXXXXXXX.
class Phone
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', (string) $raw);

        if (preg_match('/^(?:\+254|254|0)([17]\d{8})$/', $digits, $m)) {
            return '+254' . $m[1];
        }

        return null;
    }
}
