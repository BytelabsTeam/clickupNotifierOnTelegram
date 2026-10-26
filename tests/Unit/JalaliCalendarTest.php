<?php

namespace Tests\Unit;

use App\Support\JalaliCalendar;
use Carbon\Carbon;
use Tests\TestCase;

class JalaliCalendarTest extends TestCase
{
    public function test_it_uses_jalali_month_bounds_for_shahrivar_1405(): void
    {
        config(['app.timezone' => 'Asia/Tehran']);

        $bounds = JalaliCalendar::currentMonth(Carbon::parse('2026-09-16 10:00:00', 'Asia/Tehran'));

        $this->assertSame('1405-06', $bounds['key']);
        $this->assertSame('2026-08-23 00:00:00', $bounds['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-22 23:59:59', $bounds['to']->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Tehran', $bounds['from']->timezoneName);
    }

    public function test_it_maps_committed_at_to_jalali_month_key(): void
    {
        config(['app.timezone' => 'Asia/Tehran']);

        $this->assertSame(
            '1405-06',
            JalaliCalendar::monthKey(Carbon::parse('2026-09-03 12:00:00', 'UTC'))
        );
        $this->assertSame(
            '1405-05',
            JalaliCalendar::monthKey(Carbon::parse('2026-08-15 12:00:00', 'UTC'))
        );
    }
}
