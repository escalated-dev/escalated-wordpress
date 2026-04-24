<?php

/**
 * Tests for the Activator class.
 *
 * Verifies that plugin activation creates all database tables, custom roles,
 * default settings, and cron events.
 */

use Escalated\Activator;

class Test_Activator extends WP_UnitTestCase
{
    /**
     * Run activation before each test.
     */
    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
    }

    /**
     * All 21 database tables should exist after activation.
     */
    public function test_tables_created(): void
    {
        global $wpdb;

        $tables = [
            'escalated_departments',
            'escalated_department_agent',
            'escalated_sla_policies',
            'escalated_tickets',
            'escalated_replies',
            'escalated_attachments',
            'escalated_tags',
            'escalated_ticket_tag',
            'escalated_ticket_activities',
            'escalated_escalation_rules',
            'escalated_canned_responses',
            'escalated_macros',
            'escalated_inbound_emails',
            'escalated_ticket_followers',
            'escalated_satisfaction_ratings',
            'escalated_settings',
            'escalated_api_tokens',
            'escalated_permissions',
            'escalated_roles',
            'escalated_role_permissions',
            'escalated_role_users',
        ];

        $existing_tables = $wpdb->get_col('SHOW TABLES');

        foreach ($tables as $table) {
            $full_table = $wpdb->prefix.$table;
            $this->assertContains($full_table, $existing_tables, "Table {$full_table} should exist.");
        }
    }

    /**
     * Custom roles escalated_admin, escalated_agent, and escalated_light_agent should exist.
     */
    public function test_roles_created(): void
    {
        $this->assertNotNull(get_role('escalated_admin'), 'Role escalated_admin should exist.');
        $this->assertNotNull(get_role('escalated_agent'), 'Role escalated_agent should exist.');
        $this->assertNotNull(get_role('escalated_light_agent'), 'Role escalated_light_agent should exist.');
    }

    /**
     * All 52 granular capability names derived from permission slugs.
     *
     * Each slug like "ticket.view" maps to the WordPress capability
     * "escalated_ticket_view" (prefix "escalated_", dots become underscores).
     *
     * @return string[]
     */
    private function get_all_caps(): array
    {
        return [
            // Tickets
            'escalated_ticket_view',
            'escalated_ticket_create',
            'escalated_ticket_edit',
            'escalated_ticket_delete',
            'escalated_ticket_assign',
            'escalated_ticket_merge',
            'escalated_ticket_close',
            'escalated_ticket_export',
            // Replies
            'escalated_reply_create',
            'escalated_reply_create_internal',
            'escalated_reply_edit',
            'escalated_reply_delete',
            // Knowledge Base
            'escalated_kb_view',
            'escalated_kb_create',
            'escalated_kb_edit',
            'escalated_kb_delete',
            'escalated_kb_publish',
            // Departments
            'escalated_department_view',
            'escalated_department_create',
            'escalated_department_edit',
            'escalated_department_delete',
            // Reports
            'escalated_report_view',
            'escalated_report_export',
            // SLA
            'escalated_sla_view',
            'escalated_sla_manage',
            // Automations
            'escalated_automation_view',
            'escalated_automation_manage',
            // Escalation Rules
            'escalated_escalation_view',
            'escalated_escalation_manage',
            // Macros
            'escalated_macro_view',
            'escalated_macro_create',
            'escalated_macro_manage',
            // Tags
            'escalated_tag_view',
            'escalated_tag_manage',
            // Custom Fields
            'escalated_custom_field_view',
            'escalated_custom_field_manage',
            // Roles
            'escalated_role_view',
            'escalated_role_manage',
            // Users
            'escalated_user_view',
            'escalated_user_manage',
            // Settings
            'escalated_settings_view',
            'escalated_settings_manage',
            // Webhooks
            'escalated_webhook_view',
            'escalated_webhook_manage',
            // API Tokens
            'escalated_api_token_view',
            'escalated_api_token_manage',
            // Audit Log
            'escalated_audit_view',
            // Plugins
            'escalated_plugin_view',
            'escalated_plugin_manage',
            // Custom Objects
            'escalated_custom_object_view',
            'escalated_custom_object_manage',
            'escalated_custom_object_data',
        ];
    }

    /**
     * The escalated_admin role should have all 52 escalated capabilities.
     */
    public function test_admin_role_has_all_caps(): void
    {
        $role = get_role('escalated_admin');

        $all_caps = $this->get_all_caps();
        $this->assertCount(52, $all_caps, 'There should be exactly 52 granular capabilities.');

        foreach ($all_caps as $cap) {
            $this->assertTrue($role->has_cap($cap), "escalated_admin should have capability: {$cap}");
        }
    }

    /**
     * The escalated_agent role should have agent-level capabilities only.
     */
    public function test_agent_role_has_agent_caps(): void
    {
        $role = get_role('escalated_agent');

        $agent_caps = [
            'escalated_ticket_view',
            'escalated_ticket_create',
            'escalated_ticket_edit',
            'escalated_ticket_delete',
            'escalated_ticket_assign',
            'escalated_ticket_merge',
            'escalated_ticket_close',
            'escalated_ticket_export',
            'escalated_reply_create',
            'escalated_reply_create_internal',
            'escalated_reply_edit',
            'escalated_reply_delete',
            'escalated_kb_view',
            'escalated_report_view',
            'escalated_macro_view',
            'escalated_macro_create',
            'escalated_tag_view',
            'escalated_custom_field_view',
            'escalated_audit_view',
        ];

        foreach ($agent_caps as $cap) {
            $this->assertTrue($role->has_cap($cap), "escalated_agent should have capability: {$cap}");
        }

        // Agent should NOT have admin-only caps.
        $admin_only_caps = [
            'escalated_settings_view',
            'escalated_settings_manage',
            'escalated_department_create',
            'escalated_department_edit',
            'escalated_department_delete',
            'escalated_sla_manage',
            'escalated_escalation_manage',
            'escalated_api_token_view',
            'escalated_api_token_manage',
            'escalated_role_view',
            'escalated_role_manage',
            'escalated_user_manage',
            'escalated_webhook_view',
            'escalated_webhook_manage',
            'escalated_plugin_manage',
            'escalated_automation_manage',
            'escalated_custom_field_manage',
            'escalated_tag_manage',
            'escalated_macro_manage',
        ];

        foreach ($admin_only_caps as $cap) {
            $this->assertFalse($role->has_cap($cap), "escalated_agent should NOT have capability: {$cap}");
        }
    }

    /**
     * The escalated_light_agent role should have limited capabilities.
     */
    public function test_light_agent_role_has_limited_caps(): void
    {
        $role = get_role('escalated_light_agent');

        $light_caps = [
            'escalated_ticket_view',
            'escalated_reply_create',
            'escalated_reply_create_internal',
            'escalated_kb_view',
            'escalated_macro_view',
            'escalated_tag_view',
        ];

        foreach ($light_caps as $cap) {
            $this->assertTrue($role->has_cap($cap), "escalated_light_agent should have capability: {$cap}");
        }

        // Light agent should NOT have these caps.
        $excluded_caps = [
            'escalated_ticket_create',
            'escalated_ticket_edit',
            'escalated_ticket_delete',
            'escalated_ticket_assign',
            'escalated_ticket_merge',
            'escalated_ticket_close',
            'escalated_ticket_export',
            'escalated_reply_edit',
            'escalated_reply_delete',
            'escalated_settings_manage',
            'escalated_department_create',
            'escalated_sla_manage',
            'escalated_api_token_manage',
        ];

        foreach ($excluded_caps as $cap) {
            $this->assertFalse($role->has_cap($cap), "escalated_light_agent should NOT have capability: {$cap}");
        }
    }

    /**
     * The WP administrator role should receive all 52 escalated capabilities.
     */
    public function test_administrator_has_escalated_caps(): void
    {
        $role = get_role('administrator');

        foreach ($this->get_all_caps() as $cap) {
            $this->assertTrue($role->has_cap($cap), "administrator should have capability: {$cap}");
        }
    }

    /**
     * Default settings should be inserted on activation.
     */
    public function test_default_settings_inserted(): void
    {
        $this->assertEquals('ESC', \Escalated\Models\Setting::get('ticket_reference_prefix'));
        $this->assertEquals('medium', \Escalated\Models\Setting::get('default_priority'));
        $this->assertEquals('1', \Escalated\Models\Setting::get('guest_tickets_enabled'));
        $this->assertEquals('7', \Escalated\Models\Setting::get('auto_close_days'));
        $this->assertEquals('30', \Escalated\Models\Setting::get('sla_warning_minutes'));
        $this->assertEquals('10240', \Escalated\Models\Setting::get('max_attachment_size_kb'));
    }

    /**
     * Cron events should be scheduled on activation.
     */
    public function test_cron_events_scheduled(): void
    {
        $this->assertNotFalse(wp_next_scheduled('escalated_check_sla'), 'SLA check cron should be scheduled.');
        $this->assertNotFalse(wp_next_scheduled('escalated_auto_close'), 'Auto close cron should be scheduled.');
        $this->assertNotFalse(wp_next_scheduled('escalated_evaluate_escalations'), 'Escalation check cron should be scheduled.');
        $this->assertNotFalse(wp_next_scheduled('escalated_purge_activities'), 'Activity purge cron should be scheduled.');
    }

    /**
     * The plugin version option should be set on activation.
     */
    public function test_version_option_set(): void
    {
        $this->assertEquals(ESCALATED_VERSION, get_option('escalated_version'));
    }

    /**
     * maybe_upgrade() is a no-op when the stored version matches current.
     */
    public function test_maybe_upgrade_noop_when_version_matches(): void
    {
        global $wpdb;

        // Drop a table to prove activate() did NOT re-run.
        $dropped = $wpdb->prefix.'escalated_departments';
        $wpdb->query("DROP TABLE {$dropped}");

        Activator::maybe_upgrade();

        $this->assertNotContains($dropped, $wpdb->get_col('SHOW TABLES'));
    }

    /**
     * maybe_upgrade() re-runs activate() when the stored version is stale.
     */
    public function test_maybe_upgrade_reactivates_when_version_differs(): void
    {
        global $wpdb;

        update_option('escalated_version', '0.0.0-stale');

        // Drop a table to prove activate() DID re-run and re-created it.
        $dropped = $wpdb->prefix.'escalated_departments';
        $wpdb->query("DROP TABLE {$dropped}");

        Activator::maybe_upgrade();

        $this->assertContains($dropped, $wpdb->get_col('SHOW TABLES'));
        $this->assertEquals(ESCALATED_VERSION, get_option('escalated_version'));
    }

    /**
     * maybe_upgrade() reactivates on a fresh install where the option is missing.
     */
    public function test_maybe_upgrade_reactivates_when_version_missing(): void
    {
        global $wpdb;

        delete_option('escalated_version');
        $dropped = $wpdb->prefix.'escalated_departments';
        $wpdb->query("DROP TABLE {$dropped}");

        Activator::maybe_upgrade();

        $this->assertContains($dropped, $wpdb->get_col('SHOW TABLES'));
        $this->assertEquals(ESCALATED_VERSION, get_option('escalated_version'));
    }
}
