<?php

namespace Tests\Unit;

use App\Services\FjpRotationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FjpRotationTest extends TestCase
{
    use DatabaseTransactions;

    private FjpRotationService $rotation;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed known anchor for deterministic tests: Monday 2026-01-05.
        config(['fjp.rotation_anchor' => '2026-01-05']);
        $this->rotation = app(FjpRotationService::class);
    }

    public function test_anchor_is_the_configured_monday(): void
    {
        $anchor = $this->rotation->anchor();

        $this->assertSame('Monday', $anchor->format('l'));
        $this->assertSame('2026-01-05', $anchor->format('Y-m-d'));
    }

    public function test_non_monday_anchor_is_rejected(): void
    {
        config(['fjp.rotation_anchor' => '2026-01-06']); // a Tuesday

        $this->expectException(\InvalidArgumentException::class);
        app(FjpRotationService::class)->anchor();
    }

    public function test_anchor_week_is_rotation_week_1(): void
    {
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2026-01-05'))); // anchor Monday
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2026-01-09'))); // anchor Friday
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2026-01-11'))); // anchor Sunday
    }

    public function test_cycle_progresses_1_to_4_then_wraps(): void
    {
        // Week starting Mondays.
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2026-01-05')));
        $this->assertSame(2, $this->rotation->rotationWeek(Carbon::parse('2026-01-12')));
        $this->assertSame(3, $this->rotation->rotationWeek(Carbon::parse('2026-01-19')));
        $this->assertSame(4, $this->rotation->rotationWeek(Carbon::parse('2026-01-26')));
        // Wraps back to 1.
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2026-02-02')));
        $this->assertSame(2, $this->rotation->rotationWeek(Carbon::parse('2026-02-09')));
    }

    public function test_rotation_does_not_reset_at_month_boundary(): void
    {
        // January has weeks 1-4 ending Sun Feb 1; Feb must continue with week 1
        // on Mon Feb 2 — NOT restart based on the month.
        $lastDayOfJan = Carbon::parse('2026-01-31'); // Saturday of rotation week 4
        $firstDayOfFeb = Carbon::parse('2026-02-01'); // Sunday of rotation week 4

        $this->assertSame(4, $this->rotation->rotationWeek($lastDayOfJan));
        $this->assertSame(4, $this->rotation->rotationWeek($firstDayOfFeb));
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2026-02-02'))); // next Monday
    }

    public function test_rotation_does_not_reset_at_year_boundary(): void
    {
        // 2026-12-28 is a Monday. 28 Dec + 21 days = 4 rotation weeks to end of year.
        config(['fjp.rotation_anchor' => '2026-01-05']);

        // From anchor: 2026-12-28 is exactly 51 weeks later. 51 % 4 = 3 → week 4.
        $this->assertSame(4, $this->rotation->rotationWeek(Carbon::parse('2026-12-28')));

        // 2027-01-04 is 52 weeks after the anchor → 52 % 4 = 0 → week 1.
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2027-01-04')));

        // Continues 2, 3, 4 into 2027.
        $this->assertSame(2, $this->rotation->rotationWeek(Carbon::parse('2027-01-11')));
        $this->assertSame(3, $this->rotation->rotationWeek(Carbon::parse('2027-01-18')));
        $this->assertSame(4, $this->rotation->rotationWeek(Carbon::parse('2027-01-25')));
        $this->assertSame(1, $this->rotation->rotationWeek(Carbon::parse('2027-02-01')));
    }

    public function test_five_calendar_week_month_does_not_distort_cycle(): void
    {
        // July 2026 has 5 calendar week-rows; the rotation must ignore that.
        config(['fjp.rotation_anchor' => '2026-01-05']);

        // 2026-07-06 = 26 weeks after anchor. 26 % 4 = 2 → week 3.
        $this->assertSame(3, $this->rotation->rotationWeek(Carbon::parse('2026-07-06')));
        // 2026-07-27 = 29 weeks → 29 % 4 = 1 → week 2 (cycle continues, not month-driven).
        $this->assertSame(2, $this->rotation->rotationWeek(Carbon::parse('2026-07-27')));
    }

    public function test_dates_before_anchor_wrap_backwards(): void
    {
        // One week before the anchor: previous rotation's week 4.
        $this->assertSame(4, $this->rotation->rotationWeek(Carbon::parse('2025-12-29')));
        $this->assertSame(3, $this->rotation->rotationWeek(Carbon::parse('2025-12-22')));
    }

    public function test_null_preferred_week_matches_every_week(): void
    {
        $this->assertTrue($this->rotation->matchesWeek(null, Carbon::parse('2026-01-05')));
        $this->assertTrue($this->rotation->matchesWeek(null, Carbon::parse('2026-02-02')));
        $this->assertTrue($this->rotation->matchesWeek(null, Carbon::parse('2027-06-15')));
    }

    public function test_preferred_week_matches_only_its_rotation_week(): void
    {
        $week1Date = Carbon::parse('2026-01-05');
        $week2Date = Carbon::parse('2026-01-12');

        $this->assertTrue($this->rotation->matchesWeek(1, $week1Date));
        $this->assertFalse($this->rotation->matchesWeek(2, $week1Date));
        $this->assertTrue($this->rotation->matchesWeek(2, $week2Date));
        $this->assertFalse($this->rotation->matchesWeek(1, $week2Date));
    }
}
