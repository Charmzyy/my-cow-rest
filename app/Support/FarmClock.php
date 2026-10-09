<?php

namespace App\Support;

use Illuminate\Support\Carbon;

// Farm days and milking times are in Kenya time, whatever the server's clock (UTC) says:
// at 01:00 in Nairobi it is still "yesterday" in UTC.
class FarmClock
{
    public const TIMEZONE = 'Africa/Nairobi';

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** The session a farm is most likely milking right now: before 11:00 morning, 11:00-15:00 midday, then evening. */
    public static function currentSession(array $sessions): string
    {
        $hour = self::now()->hour;
        $guess = $hour < 11 ? 'am' : ($hour < 15 ? 'midday' : 'pm');
        if (!in_array($guess, $sessions, true)) {
            $guess = $hour < 13 ? 'am' : 'pm';
        }

        return in_array($guess, $sessions, true) ? $guess : $sessions[0];
    }
}
