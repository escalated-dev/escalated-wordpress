<?php

namespace Escalated\Admin;

use Escalated\Models\BusinessSchedule;
use Escalated\Services\BusinessHoursCalculator;

/**
 * Escalated > Business Hours: weekly opening hours, time zone and holidays
 * that business-hours SLA deadlines are counted on.
 */
class Admin_Business_Hours
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function render(): void
    {
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        $schedules = BusinessSchedule::all();
        $holiday_counts = [];
        foreach ($schedules as $schedule) {
            $holiday_counts[(int) $schedule->id] = count(BusinessSchedule::holidays((int) $schedule->id));
        }

        $edit_item = ($action === 'edit' && $id) ? BusinessSchedule::find($id) : null;
        $edit_hours = $edit_item ? BusinessSchedule::hours($edit_item) : self::default_hours();
        $edit_holidays = $edit_item ? BusinessSchedule::holidays((int) $edit_item->id) : [];
        $timezones = BusinessHoursCalculator::timezones();
        $weekdays = BusinessHoursCalculator::WEEKDAYS;
        $time_options = self::time_options();

        include ESCALATED_PLUGIN_DIR.'templates/admin/business-hours.php';
    }

    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_business_hours_action'])) {
            return;
        }

        $redirect = $this->process(wp_unslash($_POST));

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Apply a submitted (unslashed) form and return the URL to redirect to.
     * Split from handle_actions() so it can be tested without the exit.
     *
     * @param  array<string, mixed>  $input
     */
    public function process(array $input): string
    {
        if (! current_user_can('escalated_sla_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $action = sanitize_key((string) ($input['escalated_business_hours_action'] ?? ''));
        $id = absint($input['id'] ?? 0);
        $nonce = sanitize_text_field((string) ($input['_escalated_nonce'] ?? ''));
        $base = admin_url('admin.php?page=escalated-business-hours');

        $nonce_action = $action === 'create' ? 'escalated_business_hours_create' : 'escalated_business_hours_'.$action.'_'.$id;
        if (! wp_verify_nonce($nonce, $nonce_action)) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }

        switch ($action) {
            case 'create':
            case 'update':
                if ($action === 'update' && ! BusinessSchedule::find($id)) {
                    return add_query_arg('message', 'error', $base);
                }

                $data = $this->validated($input, $error);
                if ($data === null) {
                    $args = ['message' => $error];
                    if ($action === 'update') {
                        $args += ['action' => 'edit', 'id' => $id];
                    }

                    return add_query_arg($args, $base);
                }

                if ($action === 'create') {
                    return add_query_arg('message', BusinessSchedule::create($data) ? 'created' : 'error', $base);
                }

                return add_query_arg('message', BusinessSchedule::update($id, $data) ? 'updated' : 'error', $base);

            case 'delete':
                BusinessSchedule::delete($id);

                return add_query_arg('message', 'deleted', $base);
        }

        return $base;
    }

    /**
     * The schedule a form describes, or null with `$error` set to the
     * message key for the first problem found.
     *
     * @param  array<string, mixed>  $input
     */
    private function validated(array $input, ?string &$error = null): ?array
    {
        $name = trim(sanitize_text_field((string) ($input['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 255) {
            $error = 'missing_name';

            return null;
        }

        $timezone = (string) ($input['timezone'] ?? '');
        if (! BusinessHoursCalculator::is_valid_timezone($timezone)) {
            $error = 'invalid_timezone';

            return null;
        }

        $posted = is_array($input['schedule'] ?? null) ? $input['schedule'] : [];
        $hours = [];
        foreach (BusinessHoursCalculator::WEEKDAYS as $day) {
            $start = trim((string) ($posted[$day]['start'] ?? ''));
            $end = trim((string) ($posted[$day]['end'] ?? ''));
            if ($start === '' && $end === '') {
                $hours[$day] = ['start' => '', 'end' => ''];

                continue;
            }
            if (! BusinessHoursCalculator::is_clock_time($start) || ! BusinessHoursCalculator::is_clock_time($end) || $start === '24:00') {
                $error = 'invalid_hours';

                return null;
            }
            $hours[$day] = ['start' => $start, 'end' => $end];
        }

        $holidays = [];
        foreach ((array) ($input['holidays'] ?? []) as $holiday) {
            if (! is_array($holiday) || ! empty($holiday['remove'])) {
                continue;
            }
            $holiday_name = trim(sanitize_text_field((string) ($holiday['name'] ?? '')));
            $date = trim((string) ($holiday['date'] ?? ''));
            if ($holiday_name === '' && $date === '') {
                continue;
            }
            if ($holiday_name === '' || mb_strlen($holiday_name) > 255 || ! self::is_date($date)) {
                $error = 'invalid_holiday';

                return null;
            }
            $holidays[] = ['name' => $holiday_name, 'date' => $date, 'recurring' => ! empty($holiday['recurring'])];
        }

        return [
            'name' => $name,
            'timezone' => $timezone,
            'is_default' => ! empty($input['is_default']),
            'schedule' => $hours,
            'holidays' => $holidays,
        ];
    }

    private static function is_date(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Mon-Fri 09:00-17:00, the hours a new schedule starts with.
     *
     * @return array<string, array{start: string, end: string}>
     */
    public static function default_hours(): array
    {
        $hours = [];
        foreach (BusinessHoursCalculator::WEEKDAYS as $day) {
            $open = ! in_array($day, ['saturday', 'sunday'], true);
            $hours[$day] = ['start' => $open ? '09:00' : '', 'end' => $open ? '17:00' : ''];
        }

        return $hours;
    }

    /**
     * Every half hour from 00:00 to 24:00, like the shared ScheduleEditor.
     *
     * @return list<string>
     */
    private static function time_options(): array
    {
        $options = [];
        for ($h = 0; $h < 24; $h++) {
            foreach ([0, 30] as $m) {
                $options[] = sprintf('%02d:%02d', $h, $m);
            }
        }
        $options[] = '24:00';

        return $options;
    }
}
