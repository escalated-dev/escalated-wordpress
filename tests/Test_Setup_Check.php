<?php

/**
 * `wp escalated doctor` (Support\SetupCheck) names missing roles,
 * capabilities and recurring jobs instead of leaving admins with "Sorry, you
 * are not allowed to access this page".
 */

use Escalated\Support\SchedulerHealth;
use Escalated\Support\SetupCheck;

class Test_Setup_Check extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();
        self::factory()->user->create(['role' => 'escalated_agent']);
        SchedulerHealth::beat();
    }

    public function tear_down(): void
    {
        \Escalated\Activator::activate();
        delete_option(SchedulerHealth::OPTION);
        parent::tear_down();
    }

    /** @return list<string> */
    private function messages(): array
    {
        return array_column(SetupCheck::issues(), 'message');
    }

    public function test_a_fully_set_up_install_has_no_issues(): void
    {
        $this->assertSame([], SetupCheck::issues());
    }

    public function test_missing_administrator_capabilities_are_named(): void
    {
        get_role('administrator')->remove_cap('escalated_ticket_view');
        get_role('administrator')->remove_cap('escalated_settings_manage');

        $issues = SetupCheck::issues();

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['level']);
        $this->assertStringContainsString('administrator role is missing Escalated capabilities', $issues[0]['message']);
        $this->assertStringContainsString('escalated_ticket_view', $issues[0]['message']);
        $this->assertStringContainsString('escalated_settings_manage', $issues[0]['message']);
        $this->assertStringContainsString('reactivate', $issues[0]['message']);
    }

    public function test_a_missing_role_is_named(): void
    {
        remove_role('escalated_light_agent');

        $this->assertContains('The escalated_light_agent role is missing. Deactivate and reactivate Escalated (or call \Escalated\Activator::activate() from your installer) to create it.', $this->messages());
    }

    public function test_unscheduled_and_stalled_jobs_are_reported(): void
    {
        wp_clear_scheduled_hook('escalated_check_sla');
        delete_option(SchedulerHealth::OPTION);

        $messages = implode("\n", $this->messages());

        $this->assertStringContainsString('These recurring events are not scheduled: escalated_check_sla.', $messages);
        $this->assertStringContainsString('The SLA check has never run.', $messages);
    }
}
