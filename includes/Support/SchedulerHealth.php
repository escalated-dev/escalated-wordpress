<?php

namespace Escalated\Support;

/**
 * Whether Escalated's recurring jobs are actually running.
 *
 * The plugin schedules its jobs itself with wp_schedule_event() on
 * activation (Activator::schedule_cron_events()), but WP-Cron only fires when
 * something triggers it: a page view with WP-Cron on, or a real cron job when
 * DISABLE_WP_CRON is set. On a quiet site, or one that disabled WP-Cron
 * without adding a cron job, SLA checks, escalation rules, automations and
 * snooze wake-ups silently stop.
 *
 * The SLA check records a heartbeat each time it runs. When the heartbeat is
 * missing or older than 15 minutes, admins get a warning on Escalated's
 * screens saying what to schedule. status() is the same object the shared
 * admin pages take as their `scheduler` prop.
 */
final class SchedulerHealth
{
    public const OPTION = 'escalated_scheduler_heartbeat';

    /** A heartbeat older than this means the jobs are not running. */
    public const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

    /** Who sees the warning. */
    public const CAPABILITY = 'escalated_settings_view';

    /**
     * Escalated's WP-Cron hooks and how often each is meant to run.
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        'escalated_check_sla' => 'every minute',
        'escalated_check_snoozed_tickets' => 'every minute',
        'escalated_run_due_deferred_workflow_jobs' => 'every minute',
        'escalated_process_webhook_deliveries' => 'every minute',
        'escalated_dispatch_newsletters' => 'every minute',
        'escalated_chat_cleanup' => 'every minute',
        'escalated_evaluate_escalations' => 'every 5 minutes',
        'escalated_run_automations' => 'every 5 minutes',
        'escalated_auto_close' => 'daily',
        'escalated_purge_activities' => 'weekly',
    ];

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'admin_notice']);
    }

    /** Record that the recurring jobs just ran. */
    public static function beat(?int $now = null): void
    {
        update_option(self::OPTION, $now ?? time(), false);
    }

    public static function last_run(): ?int
    {
        $value = get_option(self::OPTION, null);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function healthy(?int $now = null): bool
    {
        $last = self::last_run();

        return $last !== null && (($now ?? time()) - $last) <= self::STALE_AFTER;
    }

    /**
     * @return array{healthy: bool, last_run_at: ?string, message: string, commands: list<array{command: string, interval: string}>}
     */
    public static function status(?int $now = null): array
    {
        $last = self::last_run();
        $healthy = self::healthy($now);

        return [
            'healthy' => $healthy,
            'last_run_at' => $last !== null ? gmdate('c', $last) : null,
            'message' => $healthy ? __('Escalated\'s recurring jobs are running.', 'escalated') : self::problem(),
            'commands' => self::commands(),
        ];
    }

    /**
     * What to schedule: one system cron job running WP-Cron every minute,
     * which in turn runs each of Escalated's events (EVENTS) at its own
     * interval.
     *
     * @return list<array{command: string, interval: string}>
     */
    public static function commands(): array
    {
        return [
            ['command' => self::cron_command(), 'interval' => 'every minute'],
        ];
    }

    /**
     * Escalated's events that are not in the WP-Cron schedule at all.
     *
     * @return list<string>
     */
    public static function unscheduled_events(): array
    {
        return array_values(array_filter(
            array_keys(self::EVENTS),
            static fn (string $hook) => wp_next_scheduled($hook) === false
        ));
    }

    public static function admin_notice(): void
    {
        if (! current_user_can(self::CAPABILITY) || ! self::on_escalated_screen() || self::healthy()) {
            return;
        }

        $status = self::status();

        echo '<div class="notice notice-warning escalated-scheduler-warning"><p><strong>'
            .esc_html__('Escalated: recurring jobs are not running.', 'escalated')
            .'</strong> '.esc_html($status['message']).'</p><ul style="list-style: disc; padding-left: 20px;">';
        foreach ($status['commands'] as $command) {
            echo '<li><code>'.esc_html($command['command']).'</code> &mdash; '.esc_html($command['interval']).'</li>';
        }
        echo '</ul><p>'.esc_html__('That runs these Escalated events:', 'escalated').'</p><ul style="list-style: disc; padding-left: 20px;">';
        foreach (self::EVENTS as $hook => $interval) {
            echo '<li><code>'.esc_html($hook).'</code> &mdash; '.esc_html($interval).'</li>';
        }
        echo '</ul></div>';
    }

    private static function problem(): string
    {
        $last = self::last_run();
        $when = $last === null
            ? __('The SLA check has never run.', 'escalated')
            /* translators: %s: how long ago, e.g. "2 hours" */
            : sprintf(__('The SLA check last ran %s ago.', 'escalated'), human_time_diff($last, time()));

        $why = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)
            ? __('WP-Cron is disabled on this site (DISABLE_WP_CRON), so nothing runs SLA checks, escalation rules, automations or snooze wake-ups until a system cron job runs WP-Cron.', 'escalated')
            : __('SLA checks, escalation rules, automations and snooze wake-ups run on WP-Cron, which only fires when the site gets traffic. Add a system cron job that runs WP-Cron every minute.', 'escalated');

        $missing = self::unscheduled_events();
        $unscheduled = $missing === []
            ? ''
            /* translators: %s: comma-separated WP-Cron hook names */
            : ' '.sprintf(__('These events are not scheduled at all; deactivate and reactivate Escalated to schedule them: %s.', 'escalated'), implode(', ', $missing));

        /* translators: %s: the cron command */
        return $when.' '.$why.' '.sprintf(__('Schedule: %s', 'escalated'), self::cron_command()).$unscheduled;
    }

    private static function cron_command(): string
    {
        return '* * * * * wp cron event run --due-now --path='.untrailingslashit(ABSPATH);
    }

    private static function on_escalated_screen(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== '' && str_starts_with($page, 'escalated')) {
            return true;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen !== null && str_contains((string) $screen->id, 'escalated');
    }
}
