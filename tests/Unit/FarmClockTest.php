<?php

namespace Tests\Unit;

use App\Models\Holding;
use App\Support\FarmClock;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class FarmClockTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // Not called at(): PHPUnit's TestCase already has a static at(), and PHP refuses the clash.
    private function freezeAt(string $nairobiTime): void
    {
        Carbon::setTestNow(Carbon::parse($nairobiTime, FarmClock::TIMEZONE));
    }

    public function test_the_farm_day_is_kenyan_even_when_utc_is_still_yesterday()
    {
        $this->freezeAt('2026-10-10 01:30');   // 22:30 UTC on the 9th

        $this->assertSame('2026-10-10', FarmClock::today());
    }

    public function test_the_current_session_follows_the_time_of_day()
    {
        $three = Holding::SESSIONS[3];
        $two = Holding::SESSIONS[2];

        $this->freezeAt('2026-10-10 05:30');
        $this->assertSame('am', FarmClock::currentSession($three));
        $this->freezeAt('2026-10-10 12:30');
        $this->assertSame('midday', FarmClock::currentSession($three));
        $this->assertSame('am', FarmClock::currentSession($two));   // no midday: late morning still counts as am
        $this->freezeAt('2026-10-10 17:45');
        $this->assertSame('pm', FarmClock::currentSession($two));
        $this->assertSame('am', FarmClock::currentSession(Holding::SESSIONS[1]));
    }

    public function test_chart_buckets_cover_the_range_without_gaps()
    {
        $this->assertSame(['2026-10-30', '2026-10-31', '2026-11-01'], FarmClock::buckets('2026-10-30', '2026-11-01', 'day'));
        // 2026-11-04 is a Wednesday: its week starts on Monday the 2nd
        $this->assertSame(['2026-11-02', '2026-11-09'], FarmClock::buckets('2026-11-04', '2026-11-10', 'week'));
        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], FarmClock::buckets('2026-01-31', '2026-03-02', 'month'));
        $this->assertSame('2026-11-02', FarmClock::bucket('2026-11-08', 'week'));   // Sunday belongs to the week before
    }
}
