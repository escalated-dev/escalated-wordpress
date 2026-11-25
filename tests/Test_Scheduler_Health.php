<?php

/**
 * The SLA check records a heartbeat, and admins are warned on Escalated's
 * screens when it is missing or older than 15 minutes.
 */

use Escalated\Cron\Sla_Check;
use Escalated\Support\SchedulerHealth;

class Test_Scheduler_Health extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();
        delete_option(SchedulerHealth::OPTION);
    }

    public function tear_down(): void
    {
        delete_option(SchedulerHealth::OPTION);
        $_GET = [];
        set_current_screen('front');
        wp_set_current_user(0);
        parent::tear_down();
    }

    public function test_the_sla_check_records_a_heartbeat(): void
    {
        $this->assertNull(SchedulerHealth::last_run());

        $before = time();
        (new Sla_Check)->run();

        $this->assertGreaterThanOrEqual($before, SchedulerHealth::last_run());
        $this->assertTrue(SchedulerHealth::healthy());
    }

    public function test_a_missing_heartbeat_is_unhealthy(): void
    {
        $status = SchedulerHealth::status();

        $this->assertFalse($status['healthy']);
        $this->assertNull($status['last_run_at']);
        $this->assertStringContainsString('never run', $status['message']);
        $this->assertStringContainsString('wp cron event run --due-now', $status['commands'][0]['command']);
        $this->assertSame('every minute', $status['commands'][0]['interval']);
        $this->assertSame(['healthy', 'last_run_at', 'message', 'commands'], array_keys($status));
    }

    public function test_a_heartbeat_older_than_15_minutes_is_unhealthy(): void
    {
        $now = time();

        SchedulerHealth::beat($now - 14 * MINUTE_IN_SECONDS);
        $this->assertTrue(SchedulerHealth::status($now)['healthy']);

        SchedulerHealth::beat($now - 16 * MINUTE_IN_SECONDS);
        $status = SchedulerHealth::status($now);
        $this->assertFalse($status['healthy']);
        $this->assertSame(gmdate('c', $now - 16 * MINUTE_IN_SECONDS), $status['last_run_at']);
        $this->assertStringContainsString('last ran', $status['message']);
    }

    public function test_admins_see_the_warning_on_escalated_screens(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_GET = ['page' => 'escalated-settings'];

        $html = $this->notice();

        $this->assertStringContainsString('escalated-scheduler-warning', $html);
        $this->assertStringContainsString('recurring jobs are not running', $html);
        $this->assertStringContainsString('wp cron event run --due-now', $html);
        $this->assertStringContainsString('escalated_check_sla', $html);
        $this->assertStringContainsString('escalated_run_automations', $html);
    }

    public function test_no_warning_while_the_jobs_run(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_GET = ['page' => 'escalated-settings'];
        SchedulerHealth::beat();

        $this->assertSame('', $this->notice());
    }

    public function test_no_warning_for_non_admins_or_other_screens(): void
    {
        $_GET = ['page' => 'escalated-settings'];
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame('', $this->notice());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_GET = [];
        set_current_screen('dashboard');
        $this->assertSame('', $this->notice());
    }

    public function test_the_notice_is_hooked(): void
    {
        $this->assertNotFalse(has_action('admin_notices', [SchedulerHealth::class, 'admin_notice']));
    }

    private function notice(): string
    {
        ob_start();
        SchedulerHealth::admin_notice();

        return (string) ob_get_clean();
    }
}
