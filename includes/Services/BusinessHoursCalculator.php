<?php

namespace Escalated\Services;

/**
 * escalated-laravel's BusinessHoursCalculator, counted in seconds.
 *
 * A schedule is an array: `timezone` (IANA name), `schedule` (weekday name =>
 * ['start' => 'HH:MM', 'end' => 'HH:MM'], empty strings or a missing day for
 * closed) and `holidays` (list of ['date' => 'Y-m-d', 'recurring' => bool]).
 *
 * Opening hours are wall-clock times in the schedule's time zone, but time is
 * counted in real elapsed seconds, so a window on a daylight-saving change day
 * is an hour shorter or longer than its wall-clock span. A window belongs to
 * the local date it opens on: a holiday on that date closes it, overnight part
 * included. `24:00` is the next midnight; an end before the start is an
 * overnight window; an equal start and end is closed. A schedule with no usable
 * window falls back to calendar time, so a policy never loses its deadline.
 */
class BusinessHoursCalculator
{
    /** A schedule whose windows are all closed by holidays for ten years has no hours. */
    private const MAX_SCAN_DAYS = 3660;

    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * The instant `$minutes` of business time after `$from`, in UTC.
     */
    public function add_business_minutes(\DateTimeInterface $from, float $minutes, array $schedule): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $start = \DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $remaining = (int) round($minutes * 60);
        if ($remaining <= 0) {
            return $start;
        }

        $calendar = $start->modify(sprintf('+%d seconds', $remaining));
        if (! $this->has_open_day($schedule)) {
            return $calendar;
        }

        $zone = self::zone($schedule);
        $cursor = $start->getTimestamp();
        // Start a day early: an overnight window opened yesterday may still be open.
        $day = $start->setTimezone($zone)->setTime(0, 0)->modify('-1 day')->format('Y-m-d');

        for ($i = 0; $i < self::MAX_SCAN_DAYS; $i++) {
            $window = $this->window_on($day, $schedule, $zone);
            if ($window !== null && $window[1] > $cursor) {
                $open = max($window[0], $cursor);
                $available = $window[1] - $open;
                if ($remaining <= $available) {
                    return (new \DateTimeImmutable('@'.($open + $remaining)))->setTimezone($utc);
                }
                $remaining -= $available;
                $cursor = $window[1];
            }
            $day = self::next_date($day);
        }

        return $calendar;
    }

    public function add_business_hours(\DateTimeInterface $from, float $hours, array $schedule): \DateTimeImmutable
    {
        return $this->add_business_minutes($from, $hours * 60, $schedule);
    }

    /**
     * Whether `$at` falls inside one of the schedule's opening windows.
     */
    public function is_within_business_hours(\DateTimeInterface $at, array $schedule): bool
    {
        $zone = self::zone($schedule);
        $instant = $at->getTimestamp();
        $today = \DateTimeImmutable::createFromInterface($at)->setTimezone($zone)->format('Y-m-d');

        foreach ([self::previous_date($today), $today] as $day) {
            $window = $this->window_on($day, $schedule, $zone);
            if ($window !== null && $instant >= $window[0] && $instant < $window[1]) {
                return true;
            }
        }

        return false;
    }

    public static function is_valid_timezone($name): bool
    {
        return is_string($name) && $name !== '' && in_array($name, self::timezones(), true);
    }

    /**
     * @return list<string>
     */
    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /**
     * Whether "HH:MM" is a clock time from 00:00 to 24:00.
     */
    public static function is_clock_time($value): bool
    {
        return self::clock_minutes($value) !== null;
    }

    /**
     * A day's hours as `start` and `end` "HH:MM", or null when the day is
     * closed. A day carrying `enabled: false` is closed.
     *
     * @return array{start: string, end: string}|null
     */
    public static function day_bounds($hours): ?array
    {
        if (! is_array($hours) || ($hours['enabled'] ?? true) === false) {
            return null;
        }
        $start = self::clock_minutes($hours['start'] ?? null);
        $end = self::clock_minutes($hours['end'] ?? null);
        if ($start === null || $end === null || $start >= 1440 || $start === $end) {
            return null;
        }

        return ['start' => (string) $hours['start'], 'end' => (string) $hours['end']];
    }

    private static function zone(array $schedule): \DateTimeZone
    {
        $name = $schedule['timezone'] ?? 'UTC';

        return new \DateTimeZone(self::is_valid_timezone($name) ? $name : 'UTC');
    }

    private function has_open_day(array $schedule): bool
    {
        $hours = is_array($schedule['schedule'] ?? null) ? $schedule['schedule'] : [];
        foreach (self::WEEKDAYS as $key) {
            if (self::day_bounds($hours[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The opening window that starts on the local date `$day` (Y-m-d), as unix
     * timestamps, or null when that date is closed or a holiday.
     *
     * @return array{0: int, 1: int}|null
     */
    private function window_on(string $day, array $schedule, \DateTimeZone $zone): ?array
    {
        $weekday = self::WEEKDAYS[(int) (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->format('N') - 1];
        $hours = is_array($schedule['schedule'] ?? null) ? $schedule['schedule'] : [];
        $bounds = self::day_bounds($hours[$weekday] ?? null);
        if ($bounds === null || $this->is_holiday($day, $schedule)) {
            return null;
        }

        $open = (int) self::clock_minutes($bounds['start']);
        $close = (int) self::clock_minutes($bounds['end']);
        $start = self::wall_clock($day, $open, $zone);
        $end = self::wall_clock($close <= $open ? self::next_date($day) : $day, $close, $zone);

        return $end > $start ? [$start, $end] : null;
    }

    private function is_holiday(string $day, array $schedule): bool
    {
        foreach ((array) ($schedule['holidays'] ?? []) as $holiday) {
            $holiday = (array) $holiday;
            $date = substr((string) ($holiday['date'] ?? ''), 0, 10);
            if ($date === '') {
                continue;
            }
            if (! empty($holiday['recurring']) ? substr($date, 5) === substr($day, 5) : $date === $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * The instant the clock in `$zone` reads `$minutes` past midnight on the
     * local date `$day`. 1440 is the next midnight. A wall time skipped by a
     * daylight-saving change resolves to the moment the clocks go forward.
     */
    private static function wall_clock(string $day, int $minutes, \DateTimeZone $zone): int
    {
        if ($minutes >= 1440) {
            $day = self::next_date($day);
            $minutes -= 1440;
        }
        $label = sprintf('%s %02d:%02d', $day, intdiv($minutes, 60), $minutes % 60);
        $t = new \DateTimeImmutable($label, $zone);
        if ($t->format('Y-m-d H:i') === $label) {
            return $t->getTimestamp();
        }

        // In a gap, PHP moves the wall time forward by the size of the jump.
        // Walk back to the first instant whose wall time is not before the one
        // asked for: the moment of the change.
        $instant = $t->getTimestamp() - ($t->getTimestamp() % 60);
        for ($i = 0; $i < 1440; $i++) {
            $previous = (new \DateTimeImmutable('@'.($instant - 60)))->setTimezone($zone);
            if ($previous->format('Y-m-d H:i') < $label) {
                break;
            }
            $instant -= 60;
        }

        return $instant;
    }

    /** Parses "HH:MM", 00:00 to 24:00, into minutes after midnight. */
    private static function clock_minutes($value): ?int
    {
        if (! is_string($value) || preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $value, $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 24 || $min > 59 || ($h === 24 && $min !== 0)) {
            return null;
        }

        return $h * 60 + $min;
    }

    private static function next_date(string $day): string
    {
        return (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }

    private static function previous_date(string $day): string
    {
        return (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
    }
}
