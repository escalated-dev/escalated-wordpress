<?php
/**
 * Tests for the Activator class.
 *
 * Verifies that plugin activation creates all database tables, custom roles,
 * default settings, and cron events.
 *
 * @package Escalated
 */

use Escalated\Activator;

class Test_Activator extends WP_UnitTestCase {

    /**
     * Run activation before each test.
     */
    public function set_up(): void {
        parent::set_up();
        Activator::activate();
    }

    /**
     * All 17 database tables should exist after activation.
     */
    public function test_tables_created(): void {
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
        ];

        foreach ( $tables as $table ) {
            $full_table = $wpdb->prefix . $table;
            $like       = $wpdb->esc_like( $full_table );
            $exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
            $this->assertEquals( $full_table, $exists, "Table {$full_table} should exist." );
        }
    }

    /**
     * Custom roles escalated_admin and escalated_agent should exist.
     */
    public function test_roles_created(): void {
        $this->assertNotNull( get_role( 'escalated_admin' ), 'Role escalated_admin should exist.' );
        $this->assertNotNull( get_role( 'escalated_agent' ), 'Role escalated_agent should exist.' );
    }

    /**
     * The escalated_admin role should have all escalated capabilities.
     */
    public function test_admin_role_has_all_caps(): void {
        $role = get_role( 'escalated_admin' );

        $expected_caps = [
            'escalated_manage_settings',
            'escalated_manage_departments',
            'escalated_manage_sla',
            'escalated_manage_escalation_rules',
            'escalated_manage_tags',
            'escalated_view_reports',
            'escalated_manage_api_tokens',
            'escalated_manage_all_tickets',
            'escalated_view_tickets',
            'escalated_reply_tickets',
            'escalated_assign_tickets',
            'escalated_add_internal_notes',
            'escalated_use_macros',
            'escalated_use_canned_responses',
        ];

        foreach ( $expected_caps as $cap ) {
            $this->assertTrue( $role->has_cap( $cap ), "escalated_admin should have capability: {$cap}" );
        }
    }

    /**
     * The escalated_agent role should have agent-level capabilities only.
     */
    public function test_agent_role_has_agent_caps(): void {
        $role = get_role( 'escalated_agent' );

        $agent_caps = [
            'escalated_view_tickets',
            'escalated_reply_tickets',
            'escalated_assign_tickets',
            'escalated_add_internal_notes',
            'escalated_use_macros',
            'escalated_use_canned_responses',
        ];

        foreach ( $agent_caps as $cap ) {
            $this->assertTrue( $role->has_cap( $cap ), "escalated_agent should have capability: {$cap}" );
        }

        // Agent should NOT have admin-level caps.
        $admin_only_caps = [
            'escalated_manage_settings',
            'escalated_manage_departments',
            'escalated_manage_sla',
            'escalated_manage_escalation_rules',
            'escalated_manage_api_tokens',
        ];

        foreach ( $admin_only_caps as $cap ) {
            $this->assertFalse( $role->has_cap( $cap ), "escalated_agent should NOT have capability: {$cap}" );
        }
    }

    /**
     * The WP administrator role should receive all escalated capabilities.
     */
    public function test_administrator_has_escalated_caps(): void {
        $role = get_role( 'administrator' );

        $this->assertTrue( $role->has_cap( 'escalated_manage_settings' ) );
        $this->assertTrue( $role->has_cap( 'escalated_view_tickets' ) );
    }

    /**
     * Default settings should be inserted on activation.
     */
    public function test_default_settings_inserted(): void {
        $this->assertEquals( 'ESC', \Escalated\Models\Setting::get( 'ticket_reference_prefix' ) );
        $this->assertEquals( 'medium', \Escalated\Models\Setting::get( 'default_priority' ) );
        $this->assertEquals( '1', \Escalated\Models\Setting::get( 'guest_tickets_enabled' ) );
        $this->assertEquals( '7', \Escalated\Models\Setting::get( 'auto_close_days' ) );
        $this->assertEquals( '30', \Escalated\Models\Setting::get( 'sla_warning_minutes' ) );
        $this->assertEquals( '10240', \Escalated\Models\Setting::get( 'max_attachment_size_kb' ) );
    }

    /**
     * Cron events should be scheduled on activation.
     */
    public function test_cron_events_scheduled(): void {
        $this->assertNotFalse( wp_next_scheduled( 'escalated_check_sla' ), 'SLA check cron should be scheduled.' );
        $this->assertNotFalse( wp_next_scheduled( 'escalated_auto_close' ), 'Auto close cron should be scheduled.' );
        $this->assertNotFalse( wp_next_scheduled( 'escalated_evaluate_escalations' ), 'Escalation check cron should be scheduled.' );
        $this->assertNotFalse( wp_next_scheduled( 'escalated_purge_activities' ), 'Activity purge cron should be scheduled.' );
    }

    /**
     * The plugin version option should be set on activation.
     */
    public function test_version_option_set(): void {
        $this->assertEquals( ESCALATED_VERSION, get_option( 'escalated_version' ) );
    }
}
