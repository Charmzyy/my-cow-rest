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

    private function at(string $nairobiTime): void
    {
        Carbon::setTestNow(Carbon::parse($nairobiTime, FarmClock::TIMEZONE));
    }

    public function test_the_farm_day_is_kenyan_even_when_utc_is_still_yesterday()
    {
        $this->at('2026-10-10 01:30');   // 22:30 UTC on the 9th

        $this->assertSame('2026-10-10', FarmClock::today());
    }

    public function test_the_current_session_follows_the_time_of_day()
    {
        $three = Holding::SESSIONS[3];
        $two = Holding::SESSIONS[2];

        $this->at('2026-10-10 05:30');
        $this->assertSame('am', FarmClock::currentSession($three));
        $this->at('2026-10-10 12:30');
        $this->assertSame('midday', FarmClock::currentSession($three));
        $this->assertSame('am', FarmClock::currentSession($two));   // no midday: late morning still counts as am
        $this->at('2026-10-10 17:45');
        $this->assertSame('pm', FarmClock::currentSession($two));
        $this->assertSame('am', FarmClock::currentSession(Holding::SESSIONS[1]));
    }
}
