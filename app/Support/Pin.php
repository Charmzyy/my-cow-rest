<?php

namespace App\Support;

// Worker PINs: 4-6 digits, stored hashed in users.password like any password.
class Pin
{
    public const MESSAGE = 'Choose a PIN that isn’t easy to guess (not 0000, 1111 or 1234).';

    /** Validation rules for a new PIN. */
    public static function rules(): array
    {
        return ['required', 'string', 'regex:/^\d{4,6}$/', function ($attribute, $value, $fail) {
            if (is_string($value) && self::isWeak($value)) {
                $fail(self::MESSAGE);
            }
        }];
    }

    /** All one digit (0000) or a straight run up or down (1234, 987654). */
    public static function isWeak(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        $up = $down = true;
        for ($i = 1; $i < strlen($pin); $i++) {
            $step = (int) $pin[$i] - (int) $pin[$i - 1];
            $up = $up && $step === 1;
            $down = $down && $step === -1;
        }

        return $up || $down;
    }
}
