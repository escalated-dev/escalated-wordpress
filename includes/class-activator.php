<?php

namespace Escalated;

class Activator {

    /**
     * Run on plugin activation.
     *
     * Creates all 17 database tables, registers custom roles and capabilities,
     * inserts default settings, and schedules cron events.
     */
    public static function activate(): void {
        self::create_tables();
        self::create_roles();
        self::add_admin_caps();
        self::insert_default_settings();
        self::schedule_cron_events();

        update_option( 'escalated_version', ESCALATED_VERSION );
        flush_rewrite_rules();
    }

    /**
     * Create all 17 database tables using dbDelta.
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix . 'escalated_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. escalated_departments
        $sql = "CREATE TABLE {$prefix}departments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        dbDelta( $sql );

        // 2. escalated_department_agent
        $sql = "CREATE TABLE {$prefix}department_agent (
            department_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (department_id, user_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 3. escalated_sla_policies
        $sql = "CREATE TABLE {$prefix}sla_policies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255),
            description TEXT,
            is_default TINYINT(1) DEFAULT 0,
            first_response_hours TEXT,
            resolution_hours TEXT,
            business_hours_only TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 4. escalated_tickets
        $sql = "CREATE TABLE {$prefix}tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(20) NOT NULL,
            requester_id BIGINT UNSIGNED NULL,
            assigned_to BIGINT UNSIGNED NULL,
            subject VARCHAR(255),
            description LONGTEXT,
            status VARCHAR(50) DEFAULT 'open',
            priority VARCHAR(50) DEFAULT 'medium',
            channel VARCHAR(50) DEFAULT 'web',
            department_id BIGINT UNSIGNED NULL,
            sla_policy_id BIGINT UNSIGNED NULL,
            first_response_at DATETIME NULL,
            first_response_due_at DATETIME NULL,
            resolution_due_at DATETIME NULL,
            sla_first_response_breached TINYINT(1) DEFAULT 0,
            sla_resolution_breached TINYINT(1) DEFAULT 0,
            resolved_at DATETIME NULL,
            closed_at DATETIME NULL,
            metadata TEXT NULL,
            guest_name VARCHAR(255) NULL,
            guest_email VARCHAR(255) NULL,
            guest_token VARCHAR(64) NULL,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reference (reference),
            UNIQUE KEY guest_token (guest_token)
        ) $charset_collate;";
        dbDelta( $sql );

        // 5. escalated_replies
        $sql = "CREATE TABLE {$prefix}replies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NULL,
            body LONGTEXT,
            is_internal_note TINYINT(1) DEFAULT 0,
            is_pinned TINYINT(1) DEFAULT 0,
            type VARCHAR(50) DEFAULT 'reply',
            metadata TEXT NULL,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 6. escalated_attachments
        $sql = "CREATE TABLE {$prefix}attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachable_type VARCHAR(50),
            attachable_id BIGINT UNSIGNED,
            filename VARCHAR(255),
            original_filename VARCHAR(255),
            mime_type VARCHAR(100),
            size BIGINT UNSIGNED DEFAULT 0,
            path VARCHAR(500),
            created_at DATETIME,
            PRIMARY KEY  (id),
            KEY attachable (attachable_type, attachable_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 7. escalated_tags
        $sql = "CREATE TABLE {$prefix}tags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255),
            slug VARCHAR(255),
            color VARCHAR(7) DEFAULT '#6B7280',
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        dbDelta( $sql );

        // 8. escalated_ticket_tag
        $sql = "CREATE TABLE {$prefix}ticket_tag (
            ticket_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (ticket_id, tag_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 9. escalated_ticket_activities
        $sql = "CREATE TABLE {$prefix}ticket_activities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED,
            causer_id BIGINT UNSIGNED NULL,
            type VARCHAR(50),
            properties TEXT NULL,
            created_at DATETIME,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 10. escalated_escalation_rules
        $sql = "CREATE TABLE {$prefix}escalation_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255),
            description TEXT,
            trigger_type VARCHAR(50),
            conditions TEXT,
            actions TEXT,
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 11. escalated_canned_responses
        $sql = "CREATE TABLE {$prefix}canned_responses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255),
            body LONGTEXT,
            category VARCHAR(100),
            created_by BIGINT UNSIGNED,
            is_shared TINYINT(1) DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 12. escalated_macros
        $sql = "CREATE TABLE {$prefix}macros (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255),
            description TEXT,
            actions TEXT,
            created_by BIGINT UNSIGNED,
            is_shared TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 13. escalated_inbound_emails
        $sql = "CREATE TABLE {$prefix}inbound_emails (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id VARCHAR(255),
            from_email VARCHAR(255),
            from_name VARCHAR(255),
            to_email VARCHAR(255),
            subject VARCHAR(500),
            body_text LONGTEXT,
            body_html LONGTEXT,
            raw_headers LONGTEXT,
            ticket_id BIGINT UNSIGNED NULL,
            reply_id BIGINT UNSIGNED NULL,
            status VARCHAR(50) DEFAULT 'pending',
            adapter VARCHAR(50),
            error_message TEXT,
            processed_at DATETIME NULL,
            created_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY message_id (message_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 14. escalated_ticket_followers
        $sql = "CREATE TABLE {$prefix}ticket_followers (
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME,
            PRIMARY KEY  (ticket_id, user_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 15. escalated_satisfaction_ratings
        $sql = "CREATE TABLE {$prefix}satisfaction_ratings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED,
            rating TINYINT UNSIGNED,
            comment TEXT,
            rated_by BIGINT UNSIGNED NULL,
            created_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_id (ticket_id)
        ) $charset_collate;";
        dbDelta( $sql );

        // 16. escalated_settings
        $sql = "CREATE TABLE {$prefix}settings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            option_key VARCHAR(255),
            option_value LONGTEXT,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY option_key (option_key)
        ) $charset_collate;";
        dbDelta( $sql );

        // 17. escalated_api_tokens
        $sql = "CREATE TABLE {$prefix}api_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED,
            name VARCHAR(255),
            token VARCHAR(64),
            abilities TEXT,
            last_used_at DATETIME NULL,
            last_used_ip VARCHAR(45) NULL,
            expires_at DATETIME NULL,
            created_at DATETIME,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token)
        ) $charset_collate;";
        dbDelta( $sql );
    }

    /**
     * Get all escalated capabilities.
     */
    private static function get_escalated_caps(): array {
        return [
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
    }

    /**
     * Get agent-level escalated capabilities.
     */
    private static function get_agent_caps(): array {
        return [
            'escalated_view_tickets',
            'escalated_reply_tickets',
            'escalated_assign_tickets',
            'escalated_add_internal_notes',
            'escalated_use_macros',
            'escalated_use_canned_responses',
        ];
    }

    /**
     * Register custom roles: escalated_admin and escalated_agent.
     */
    private static function create_roles(): void {
        // Get the editor role capabilities as the base for escalated_admin.
        $editor_role = get_role( 'editor' );
        $admin_caps  = $editor_role ? $editor_role->capabilities : [];

        // Add all escalated caps to the admin role capabilities.
        foreach ( self::get_escalated_caps() as $cap ) {
            $admin_caps[ $cap ] = true;
        }

        remove_role( 'escalated_admin' );
        add_role( 'escalated_admin', 'Escalated Admin', $admin_caps );

        // Get the subscriber role capabilities as the base for escalated_agent.
        $subscriber_role = get_role( 'subscriber' );
        $agent_caps      = $subscriber_role ? $subscriber_role->capabilities : [];

        // Add agent-level escalated caps.
        foreach ( self::get_agent_caps() as $cap ) {
            $agent_caps[ $cap ] = true;
        }

        remove_role( 'escalated_agent' );
        add_role( 'escalated_agent', 'Escalated Agent', $agent_caps );
    }

    /**
     * Add all escalated capabilities to the administrator role.
     */
    private static function add_admin_caps(): void {
        $admin_role = get_role( 'administrator' );
        if ( ! $admin_role ) {
            return;
        }

        foreach ( self::get_escalated_caps() as $cap ) {
            $admin_role->add_cap( $cap );
        }
    }

    /**
     * Insert default settings into the escalated_settings table.
     */
    private static function insert_default_settings(): void {
        global $wpdb;

        $table    = $wpdb->prefix . 'escalated_settings';
        $now      = current_time( 'mysql' );
        $defaults = [
            'ticket_reference_prefix' => 'ESC',
            'default_priority'        => 'medium',
            'guest_tickets_enabled'   => '1',
            'auto_close_days'         => '7',
            'auto_close_enabled'      => '0',
            'inbound_email_enabled'   => '0',
            'sla_warning_minutes'     => '30',
            'activity_purge_days'     => '90',
            'max_attachment_size_kb'  => '10240',
            'max_attachments_per_reply' => '5',
            'webhook_url'             => '',
            'webhook_secret'          => '',
        ];

        foreach ( $defaults as $key => $value ) {
            // Only insert if the setting does not already exist.
            $exists = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE option_key = %s", $key )
            );

            if ( ! $exists ) {
                $wpdb->insert(
                    $table,
                    [
                        'option_key'   => $key,
                        'option_value' => $value,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ],
                    [ '%s', '%s', '%s', '%s' ]
                );
            }
        }
    }

    /**
     * Schedule cron events and register custom cron intervals.
     */
    private static function schedule_cron_events(): void {
        // Register custom cron intervals via filter.
        add_filter( 'cron_schedules', [ self::class, 'add_cron_schedules' ] );

        if ( ! wp_next_scheduled( 'escalated_check_sla' ) ) {
            wp_schedule_event( time(), 'escalated_every_minute', 'escalated_check_sla' );
        }

        if ( ! wp_next_scheduled( 'escalated_evaluate_escalations' ) ) {
            wp_schedule_event( time(), 'escalated_every_five_minutes', 'escalated_evaluate_escalations' );
        }

        if ( ! wp_next_scheduled( 'escalated_auto_close' ) ) {
            wp_schedule_event( time(), 'daily', 'escalated_auto_close' );
        }

        if ( ! wp_next_scheduled( 'escalated_purge_activities' ) ) {
            wp_schedule_event( time(), 'weekly', 'escalated_purge_activities' );
        }
    }

    /**
     * Add custom cron schedule intervals.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public static function add_cron_schedules( array $schedules ): array {
        $schedules['escalated_every_minute'] = [
            'interval' => 60,
            'display'  => __( 'Every Minute (Escalated)', 'escalated' ),
        ];

        $schedules['escalated_every_five_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Every Five Minutes (Escalated)', 'escalated' ),
        ];

        return $schedules;
    }
}
