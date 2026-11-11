<?php

namespace App\Support;

// WCAG contrast between two colours. Brand and accent colours fill buttons with white text,
// and the app is read outdoors in sunlight, so they must reach 4.5:1 against white.
// The client has the same maths in src/theme.js.
class Contrast
{
    public const MIN_ON_WHITE = 4.5;

    public static function isHex(?string $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /** Relative luminance (0 = black, 1 = white) of #rrggbb. */
    public static function luminance(string $hex): float
    {
        $channels = array_map(fn ($pair) => hexdec($pair) / 255, str_split(substr($hex, 1), 2));
        [$r, $g, $b] = array_map(fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function ratio(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return round(($hi + 0.05) / ($lo + 0.05), 2);
    }

    /** Readable as a button colour with white text. */
    public static function okForWhiteText(string $hex): bool
    {
        return self::ratio($hex, '#ffffff') >= self::MIN_ON_WHITE;
    }
}
