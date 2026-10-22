<?php

/**
 * BusinessHoursCalculator: the same cases as the Go, Phoenix, Adonis and
 * Symfony ports, so a deadline comes out the same on every backend.
 */

use Escalated\Services\BusinessHoursCalculator;
use PHPUnit\Framework\TestCase;

class Test_Business_Hours_Calculator extends TestCase
{
    private const NINE_TO_FIVE = ['start' => '09:00', 'end' => '17:00'];

    private const CLOSED = ['start' => '', 'end' => ''];

    /**
     * @param  array<string, array<string, mixed>>|null  $hours
     * @param  list<array{date: string, recurring?: bool}>  $holidays
     */
    private function schedule(?array $hours = null, string $timezone = 'UTC', array $holidays = []): array
    {
        return [
            'timezone' => $timezone,
            'schedule' => $hours ?? [
                'monday' => self::NINE_TO_FIVE,
                'tuesday' => self::NINE_TO_FIVE,
                'wednesday' => self::NINE_TO_FIVE,
                'thursday' => self::NINE_TO_FIVE,
                'friday' => self::NINE_TO_FIVE,
                'saturday' => self::CLOSED,
                'sunday' => self::CLOSED,
            ],
            'holidays' => array_map(static fn ($h) => ['name' => 'Off', 'date' => $h['date'], 'recurring' => $h['recurring'] ?? false], $holidays),
        ];
    }

    private function add(array $schedule, string $from, float $minutes): string
    {
        return (new BusinessHoursCalculator)->add_business_minutes(new DateTimeImmutable($from), $minutes, $schedule)->format('Y-m-d\TH:i:s\Z');
    }

    private function within(array $schedule, string $at): bool
    {
        return (new BusinessHoursCalculator)->is_within_business_hours(new DateTimeImmutable($at), $schedule);
    }

    public function test_adds_minutes_inside_one_open_window(): void
    {
        // 2026-10-19 is a Monday.
        $this->assertSame('2026-10-19T12:00:00Z', $this->add($this->schedule(), '2026-10-19T10:00:00Z', 120));
    }

    public function test_carries_what_is_left_into_the_next_open_day(): void
    {
        $this->assertSame('2026-10-20T10:00:00Z', $this->add($this->schedule(), '2026-10-19T16:00:00Z', 120));
    }

    public function test_skips_the_weekend(): void
    {
        $this->assertSame('2026-10-26T10:00:00Z', $this->add($this->schedule(), '2026-10-23T16:00:00Z', 120));
    }

    public function test_starts_at_the_next_opening_when_created_after_hours(): void
    {
        $this->assertSame('2026-10-26T10:00:00Z', $this->add($this->schedule(), '2026-10-23T20:00:00Z', 60));
        $this->assertSame('2026-10-26T09:30:00Z', $this->add($this->schedule(), '2026-10-24T12:00:00Z', 30));
    }

    public function test_skips_a_one_off_holiday(): void
    {
        $s = $this->schedule(null, 'UTC', [['date' => '2026-10-20']]);
        $this->assertSame('2026-10-21T10:00:00Z', $this->add($s, '2026-10-19T16:00:00Z', 120));
        // The same date in another year is an ordinary day.
        $this->assertSame('2027-10-20T10:00:00Z', $this->add($s, '2027-10-19T16:00:00Z', 120));
    }

    public function test_skips_a_recurring_holiday_every_year(): void
    {
        $s = $this->schedule(null, 'UTC', [['date' => '2020-12-25', 'recurring' => true]]);
        $this->assertSame('2026-12-28T10:00:00Z', $this->add($s, '2026-12-24T16:00:00Z', 120));
    }

    public function test_counts_an_overnight_window_that_crosses_midnight(): void
    {
        $s = $this->schedule(['monday' => ['start' => '22:00', 'end' => '06:00'], 'tuesday' => ['start' => '22:00', 'end' => '06:00']]);
        $this->assertSame('2026-10-20T02:00:00Z', $this->add($s, '2026-10-19T23:00:00Z', 180));
        // Tuesday 01:00 is still inside Monday's window: 5h left of it, then Tuesday 22:00.
        $this->assertSame('2026-10-20T23:00:00Z', $this->add($s, '2026-10-20T01:00:00Z', 360));
    }

    public function test_closes_the_overnight_window_that_opens_on_a_holiday(): void
    {
        $s = $this->schedule(['monday' => ['start' => '22:00', 'end' => '06:00'], 'tuesday' => ['start' => '22:00', 'end' => '06:00']], 'UTC', [['date' => '2026-10-19']]);
        $this->assertSame('2026-10-20T23:00:00Z', $this->add($s, '2026-10-19T23:00:00Z', 60));
    }

    public function test_treats_2400_as_the_next_midnight(): void
    {
        $s = $this->schedule(['monday' => ['start' => '20:00', 'end' => '24:00']]);
        $this->assertSame('2026-10-20T00:00:00Z', $this->add($s, '2026-10-19T22:00:00Z', 120));
        $this->assertSame('2026-10-26T21:00:00Z', $this->add($s, '2026-10-19T22:00:00Z', 180));
    }

    public function test_reads_the_hours_in_the_schedule_time_zone(): void
    {
        $s = $this->schedule(null, 'America/Toronto');
        $this->assertSame('2026-10-19T14:00:00Z', $this->add($s, '2026-10-19T12:00:00Z', 60));
        $this->assertSame('2026-10-26T13:30:00Z', $this->add($s, '2026-10-23T20:30:00Z', 60));
    }

    public function test_uses_the_local_date_for_weekdays_and_holidays(): void
    {
        $s = $this->schedule(null, 'Asia/Tokyo', [['date' => '2026-10-20']]);
        // 2026-10-19T23:00Z is Tuesday 08:00 in Tokyo, a holiday.
        $this->assertSame('2026-10-21T01:00:00Z', $this->add($s, '2026-10-19T23:00:00Z', 60));
    }

    public function test_counts_real_minutes_across_the_spring_forward_change(): void
    {
        $s = $this->schedule(['sunday' => ['start' => '00:00', 'end' => '06:00'], 'monday' => self::NINE_TO_FIVE], 'America/New_York');
        $this->assertSame('2026-03-08T10:00:00Z', $this->add($s, '2026-03-08T05:00:00Z', 300));
        $this->assertSame('2026-03-09T14:00:00Z', $this->add($s, '2026-03-08T05:00:00Z', 360));
    }

    public function test_opens_at_the_clock_change_when_the_opening_time_does_not_exist(): void
    {
        $s = $this->schedule(['sunday' => ['start' => '02:30', 'end' => '04:00']], 'America/New_York');
        $this->assertSame('2026-03-08T07:30:00Z', $this->add($s, '2026-03-08T00:00:00Z', 30));
        $this->assertSame('2026-03-08T08:00:00Z', $this->add($s, '2026-03-08T00:00:00Z', 60));
    }

    public function test_counts_real_minutes_across_the_fall_back_change(): void
    {
        $s = $this->schedule(['sunday' => ['start' => '00:00', 'end' => '03:00'], 'monday' => self::NINE_TO_FIVE], 'America/New_York');
        $this->assertSame('2026-11-01T08:00:00Z', $this->add($s, '2026-11-01T04:00:00Z', 240));
        $this->assertSame('2026-11-02T14:30:00Z', $this->add($s, '2026-11-01T04:00:00Z', 270));
    }

    public function test_falls_back_to_calendar_time_when_no_day_is_open(): void
    {
        $s = $this->schedule(['monday' => self::CLOSED, 'tuesday' => ['start' => '09:00', 'end' => '09:00']]);
        $this->assertSame('2026-10-19T11:30:00Z', $this->add($s, '2026-10-19T10:00:00Z', 90));
    }

    public function test_falls_back_to_utc_for_an_unknown_time_zone(): void
    {
        $this->assertSame('2026-10-19T11:00:00Z', $this->add($this->schedule(null, 'Not/AZone'), '2026-10-19T10:00:00Z', 60));
    }

    public function test_returns_the_start_for_zero_minutes(): void
    {
        $this->assertSame('2026-10-24T10:00:00Z', $this->add($this->schedule(), '2026-10-24T10:00:00Z', 0));
    }

    public function test_reads_an_enabled_flag_when_a_day_has_one(): void
    {
        $s = $this->schedule(['monday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => false], 'tuesday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true]]);
        $this->assertSame('2026-10-20T10:00:00Z', $this->add($s, '2026-10-19T10:00:00Z', 60));
    }

    public function test_tells_whether_an_instant_is_inside_business_hours(): void
    {
        $s = $this->schedule(null, 'America/Toronto', [['date' => '2026-10-20']]);
        $this->assertTrue($this->within($s, '2026-10-19T14:00:00Z'));
        $this->assertFalse($this->within($s, '2026-10-19T12:59:00Z'));
        $this->assertFalse($this->within($s, '2026-10-19T21:00:00Z'));
        $this->assertFalse($this->within($s, '2026-10-20T14:00:00Z'));
        $this->assertFalse($this->within($s, '2026-10-24T14:00:00Z'));
    }

    public function test_counts_the_early_hours_of_an_overnight_window_as_open(): void
    {
        $s = $this->schedule(['monday' => ['start' => '22:00', 'end' => '06:00']]);
        $this->assertTrue($this->within($s, '2026-10-20T03:00:00Z'));
        $this->assertFalse($this->within($s, '2026-10-20T07:00:00Z'));
    }
}
