<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * A named set of weekly opening hours in one time zone, with holidays.
 *
 * `schedule` is stored as JSON keyed by weekday name:
 * {"monday": {"start": "09:00", "end": "17:00"}, ..., "sunday": {"start": "", "end": ""}}.
 */
class BusinessSchedule
{
    public static function table(): string
    {
        return Escalated::table('business_schedules');
    }

    public static function holidays_table(): string
    {
        return Escalated::table('holidays');
    }

    /**
     * @return object|null
     */
    public static function find($id)
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    /**
     * @return array<int, object>
     */
    public static function all(): array
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC, id ASC") ?: [];
    }

    /**
     * The schedule marked as default (the lowest id when several are).
     *
     * @return object|null
     */
    public static function get_default()
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_row("SELECT * FROM {$table} WHERE is_default = 1 ORDER BY id ASC LIMIT 1");
    }

    /**
     * Create a schedule with its holidays.
     *
     * @param  array{name: string, timezone: string, is_default?: int|bool, schedule: array, holidays?: array}  $data
     * @return int|false
     */
    public static function create(array $data)
    {
        $wpdb = Escalated::db();
        $now = current_time('mysql');

        if (! empty($data['is_default'])) {
            static::clear_default();
        }

        $result = $wpdb->insert(static::table(), [
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'is_default' => empty($data['is_default']) ? 0 : 1,
            'schedule' => wp_json_encode($data['schedule']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($result === false) {
            return false;
        }

        $id = (int) $wpdb->insert_id;
        static::replace_holidays($id, $data['holidays'] ?? []);

        return $id;
    }

    /**
     * Update a schedule and replace its holidays.
     */
    public static function update($id, array $data): bool
    {
        $wpdb = Escalated::db();
        $id = (int) $id;

        if (! empty($data['is_default'])) {
            static::clear_default($id);
        }

        $ok = $wpdb->update(static::table(), [
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'is_default' => empty($data['is_default']) ? 0 : 1,
            'schedule' => wp_json_encode($data['schedule']),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]) !== false;

        if ($ok) {
            static::replace_holidays($id, $data['holidays'] ?? []);
        }

        return $ok;
    }

    /**
     * Delete a schedule, its holidays, and the references policies and
     * departments hold to it (they fall back to the default schedule).
     */
    public static function delete($id): bool
    {
        $wpdb = Escalated::db();
        $id = (int) $id;

        $wpdb->delete(static::holidays_table(), ['schedule_id' => $id]);
        $wpdb->update(Escalated::table('sla_policies'), ['business_schedule_id' => null], ['business_schedule_id' => $id]);
        $wpdb->update(Escalated::table('departments'), ['business_schedule_id' => null], ['business_schedule_id' => $id]);

        return $wpdb->delete(static::table(), ['id' => $id]) !== false;
    }

    /**
     * @return array<int, object>
     */
    public static function holidays($schedule_id): array
    {
        $wpdb = Escalated::db();
        $table = static::holidays_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE schedule_id = %d ORDER BY date ASC, id ASC",
            $schedule_id
        )) ?: [];
    }

    /**
     * @param  array<int, array{name: string, date: string, recurring?: bool|int}>  $holidays
     */
    public static function replace_holidays(int $schedule_id, array $holidays): void
    {
        $wpdb = Escalated::db();
        $table = static::holidays_table();
        $now = current_time('mysql');

        $wpdb->delete($table, ['schedule_id' => $schedule_id]);
        foreach ($holidays as $holiday) {
            $wpdb->insert($table, [
                'schedule_id' => $schedule_id,
                'name' => $holiday['name'],
                'date' => $holiday['date'],
                'recurring' => empty($holiday['recurring']) ? 0 : 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * The decoded weekly hours of a schedule row.
     *
     * @return array<string, array{start: string, end: string}>
     */
    public static function hours(object $schedule): array
    {
        $hours = json_decode((string) ($schedule->schedule ?? ''), true);

        return is_array($hours) ? $hours : [];
    }

    /**
     * A schedule row in the calculator's shape (timezone, schedule, holidays).
     */
    public static function for_calculator(object $schedule): array
    {
        return [
            'timezone' => (string) $schedule->timezone,
            'schedule' => static::hours($schedule),
            'holidays' => array_map(static fn ($h) => [
                'date' => substr((string) $h->date, 0, 10),
                'recurring' => (bool) (int) $h->recurring,
            ], static::holidays((int) $schedule->id)),
        ];
    }

    /**
     * The business_schedule_id a policy or department form submitted, as
     * column data: empty when the field was not submitted (so the column is
     * left alone), null for "use the default schedule", or the id of an
     * existing schedule.
     *
     * @param  array<string, mixed>  $input
     * @return array{business_schedule_id?: int|null}
     */
    public static function reference_from_input(array $input): array
    {
        if (! array_key_exists('business_schedule_id', $input)) {
            return [];
        }

        $id = absint($input['business_schedule_id']);

        return ['business_schedule_id' => ($id && static::find($id)) ? $id : null];
    }

    private static function clear_default(int $except = 0): void
    {
        $wpdb = Escalated::db();
        $table = static::table();

        $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_default = 0 WHERE is_default = 1 AND id <> %d", $except));
    }
}
