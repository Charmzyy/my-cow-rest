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

    /** The first day of the chart bucket a day falls in: itself, its week's Monday, or the 1st of its month. */
    public static function bucket(string $day, string $granularity): string
    {
        $d = Carbon::parse($day, self::TIMEZONE);

        return match ($granularity) {
            'week' => $d->startOfWeek(Carbon::MONDAY)->toDateString(),
            'month' => $d->startOfMonth()->toDateString(),
            default => $d->toDateString(),
        };
    }

    /**
     * Every bucket from $from to $to, oldest first, so a chart's axis has no gaps
     * (a day, week or month with nothing recorded still appears, as 0).
     *
     * @return string[] bucket start dates (Y-m-d)
     */
    public static function buckets(string $from, string $to, string $granularity): array
    {
        $out = [];
        $day = Carbon::parse(self::bucket($from, $granularity), self::TIMEZONE);
        $end = Carbon::parse($to, self::TIMEZONE);
        while ($day->lte($end)) {
            $out[] = $day->toDateString();
            match ($granularity) {
                'week' => $day->addWeek(),
                'month' => $day->addMonthNoOverflow(),
                default => $day->addDay(),
            };
        }

        return $out;
    }
}
