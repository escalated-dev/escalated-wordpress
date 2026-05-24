<?php

namespace Escalated;

class Activator
{
    /**
     * Run on plugin activation.
     *
     * Creates all 21 database tables, registers custom roles and capabilities,
     * inserts default settings, and schedules cron events.
     */
    public static function activate(): void
    {
        self::create_tables();
        self::create_newsletter_tables();
        self::seed_permissions();
        self::create_roles();
        self::add_admin_caps();
        self::insert_default_settings();
        self::schedule_cron_events();

        update_option('escalated_version', ESCALATED_VERSION);
        flush_rewrite_rules();
    }

    /**
     * Run upgrade-time work when the stored plugin version differs from the current one.
     *
     * WordPress does not fire activation hooks on auto-update or on manual
     * upload-overwrite upgrades, so existing installs can end up on new code
     * without the schema/permission seed having run.
     *
     * IMPORTANT: this is **not** the same as a full `activate()` call. The
     * activation hook fires once per install and bootstraps everything
     * including custom WP roles. On *upgrade*, repeating that role bootstrap
     * via `create_roles()` would unconditionally `remove_role(...)` + re-add
     * the escalated_admin / escalated_agent / escalated_light_agent roles,
     * destroying any per-install customizations an admin made.
     *
     * Instead, only the steps that are safely idempotent and additive run on
     * upgrade:
     *   - create_tables()        — dbDelta is purpose-built for this
     *   - seed_permissions()     — upserts permission rows + role pivots
     *   - add_admin_caps()       — only adds caps; doesn't remove
     *   - insert_default_settings() — `if not exists` guarded
     *   - schedule_cron_events() — `wp_next_scheduled` guarded
     *
     * `create_roles()` is intentionally skipped on upgrade. New installs still
     * call `activate()` from the activation hook, which runs `create_roles()`
     * once.
     */
    public static function maybe_upgrade(): void
    {
        if (get_option('escalated_version') === ESCALATED_VERSION) {
            return;
        }

        self::create_tables();
        self::create_newsletter_tables();
        self::seed_permissions();
        self::add_admin_caps();
        self::insert_default_settings();
        self::schedule_cron_events();

        update_option('escalated_version', ESCALATED_VERSION);
    }

    /**
     * Create all 21 database tables using dbDelta.
     */
    private static function create_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix.'escalated_';

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

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
        dbDelta($sql);

        // 2. escalated_department_agent
        $sql = "CREATE TABLE {$prefix}department_agent (
            department_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (department_id, user_id)
        ) $charset_collate;";
        dbDelta($sql);

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
        dbDelta($sql);

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
            ticket_type VARCHAR(50) DEFAULT 'question',
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
            contact_id BIGINT UNSIGNED NULL,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reference (reference),
            UNIQUE KEY guest_token (guest_token),
            KEY contact_id (contact_id)
        ) $charset_collate;";
        dbDelta($sql);

        // 4b. escalated_contacts (Pattern B convergence — first-class
        // identity for guest requesters, deduped by email).
        $sql = "CREATE TABLE {$prefix}contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(320) NOT NULL,
            name VARCHAR(255) NULL,
            user_id BIGINT UNSIGNED NULL,
            metadata TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

        // 8. escalated_ticket_tag
        $sql = "CREATE TABLE {$prefix}ticket_tag (
            ticket_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (ticket_id, tag_id)
        ) $charset_collate;";
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

        // 14. escalated_ticket_followers
        $sql = "CREATE TABLE {$prefix}ticket_followers (
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME,
            PRIMARY KEY  (ticket_id, user_id)
        ) $charset_collate;";
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

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
        dbDelta($sql);

        // 18. escalated_permissions
        $sql = "CREATE TABLE {$prefix}permissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            `group` VARCHAR(255),
            description TEXT,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        dbDelta($sql);

        // 19. escalated_roles
        $sql = "CREATE TABLE {$prefix}roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT,
            is_system TINYINT(1) DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        dbDelta($sql);

        // 20. escalated_role_permissions (pivot)
        $sql = "CREATE TABLE {$prefix}role_permissions (
            role_id BIGINT UNSIGNED NOT NULL,
            permission_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (role_id, permission_id)
        ) $charset_collate;";
        dbDelta($sql);

        // 21. escalated_role_users (pivot)
        $sql = "CREATE TABLE {$prefix}role_users (
            role_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (role_id, user_id)
        ) $charset_collate;";
        dbDelta($sql);

        // 22. escalated_automations
        $sql = "CREATE TABLE {$prefix}automations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            conditions LONGTEXT NOT NULL,
            actions LONGTEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            last_run_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY active (active)
        ) $charset_collate;";
        dbDelta($sql);

        // escalated_workflows — event-driven workflow rows fired by
        // WorkflowRunnerService. Distinct from automations (time-based
        // sweep) — see escalated-developer-context for the taxonomy.
        $sql = "CREATE TABLE {$prefix}workflows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            trigger_event VARCHAR(64) NOT NULL,
            conditions LONGTEXT DEFAULT NULL,
            actions LONGTEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            stop_on_match TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY trigger_active (trigger_event, is_active),
            KEY position (position)
        ) $charset_collate;";
        dbDelta($sql);

        // escalated_workflow_logs — one row per workflow firing attempt.
        $sql = "CREATE TABLE {$prefix}workflow_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workflow_id BIGINT UNSIGNED NOT NULL,
            ticket_id BIGINT UNSIGNED NOT NULL,
            trigger_event VARCHAR(64) NOT NULL,
            conditions_matched TINYINT(1) NOT NULL DEFAULT 0,
            actions_executed LONGTEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY workflow_id (workflow_id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";
        dbDelta($sql);

        // escalated_chat_sessions
        $sql = "CREATE TABLE {$prefix}chat_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            visitor_name VARCHAR(255) NOT NULL DEFAULT 'Visitor',
            visitor_email VARCHAR(255) NULL,
            agent_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'waiting',
            accepted_at DATETIME NULL,
            ended_at DATETIME NULL,
            last_activity_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY status (status),
            KEY agent_id (agent_id)
        ) $charset_collate;";
        dbDelta($sql);

        // escalated_chat_routing_rules
        $sql = "CREATE TABLE {$prefix}chat_routing_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            department_id BIGINT UNSIGNED NULL,
            agent_id BIGINT UNSIGNED NULL,
            conditions TEXT NULL,
            priority INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY priority (priority)
        ) $charset_collate;";
        dbDelta($sql);

        // escalated_deferred_workflow_jobs — queue row for the `delay`
        // workflow action. Cron\Deferred_Workflow_Jobs_Check polls for
        // status=pending + run_at <= now and re-dispatches remaining_actions.
        $sql = "CREATE TABLE {$prefix}deferred_workflow_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            remaining_actions LONGTEXT NOT NULL,
            run_at DATETIME NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status_runat (status, run_at)
        ) $charset_collate;";
        dbDelta($sql);
    }

    /**
     * Get all 52 granular permission definitions.
     *
     * Each entry maps to a row in the escalated_permissions table AND
     * a WordPress capability prefixed with "escalated_".
     *
     * @return array<array{slug: string, name: string, group: string, description: string}>
     */
    private static function get_permission_definitions(): array
    {
        return [
            // Tickets
            ['slug' => 'ticket.view',    'name' => 'View tickets',           'group' => 'Tickets',          'description' => 'View tickets'],
            ['slug' => 'ticket.create',  'name' => 'Create tickets',         'group' => 'Tickets',          'description' => 'Create tickets'],
            ['slug' => 'ticket.edit',    'name' => 'Edit ticket properties', 'group' => 'Tickets',          'description' => 'Edit ticket properties'],
            ['slug' => 'ticket.delete',  'name' => 'Delete tickets',         'group' => 'Tickets',          'description' => 'Delete tickets'],
            ['slug' => 'ticket.assign',  'name' => 'Assign tickets',         'group' => 'Tickets',          'description' => 'Assign tickets to agents'],
            ['slug' => 'ticket.merge',   'name' => 'Merge tickets',          'group' => 'Tickets',          'description' => 'Merge tickets together'],
            ['slug' => 'ticket.close',   'name' => 'Close tickets',          'group' => 'Tickets',          'description' => 'Close and reopen tickets'],
            ['slug' => 'ticket.export',  'name' => 'Export tickets',         'group' => 'Tickets',          'description' => 'Export ticket data'],
            // Replies
            ['slug' => 'reply.create',          'name' => 'Reply to tickets',   'group' => 'Replies', 'description' => 'Reply to tickets'],
            ['slug' => 'reply.create_internal', 'name' => 'Add internal notes', 'group' => 'Replies', 'description' => 'Add internal notes'],
            ['slug' => 'reply.edit',            'name' => 'Edit replies',       'group' => 'Replies', 'description' => 'Edit replies'],
            ['slug' => 'reply.delete',          'name' => 'Delete replies',     'group' => 'Replies', 'description' => 'Delete replies'],
            // Knowledge Base
            ['slug' => 'kb.view',    'name' => 'View knowledge base', 'group' => 'Knowledge Base', 'description' => 'View knowledge base'],
            ['slug' => 'kb.create',  'name' => 'Create articles',     'group' => 'Knowledge Base', 'description' => 'Create articles'],
            ['slug' => 'kb.edit',    'name' => 'Edit articles',       'group' => 'Knowledge Base', 'description' => 'Edit articles'],
            ['slug' => 'kb.delete',  'name' => 'Delete articles',     'group' => 'Knowledge Base', 'description' => 'Delete articles'],
            ['slug' => 'kb.publish', 'name' => 'Publish articles',    'group' => 'Knowledge Base', 'description' => 'Publish/unpublish articles'],
            // Departments
            ['slug' => 'department.view',   'name' => 'View departments',   'group' => 'Departments', 'description' => 'View departments'],
            ['slug' => 'department.create', 'name' => 'Create departments', 'group' => 'Departments', 'description' => 'Create departments'],
            ['slug' => 'department.edit',   'name' => 'Edit departments',   'group' => 'Departments', 'description' => 'Edit departments'],
            ['slug' => 'department.delete', 'name' => 'Delete departments', 'group' => 'Departments', 'description' => 'Delete departments'],
            // Reports
            ['slug' => 'report.view',   'name' => 'View reports',   'group' => 'Reports', 'description' => 'View reports and analytics'],
            ['slug' => 'report.export', 'name' => 'Export reports', 'group' => 'Reports', 'description' => 'Export report data'],
            // SLA
            ['slug' => 'sla.view',   'name' => 'View SLA policies',   'group' => 'SLA', 'description' => 'View SLA policies'],
            ['slug' => 'sla.manage', 'name' => 'Manage SLA policies', 'group' => 'SLA', 'description' => 'Create, edit, delete SLA policies'],
            // Automations
            ['slug' => 'automation.view',   'name' => 'View automations',   'group' => 'Automations', 'description' => 'View automations'],
            ['slug' => 'automation.manage', 'name' => 'Manage automations', 'group' => 'Automations', 'description' => 'Create, edit, delete automations'],
            // Escalation Rules
            ['slug' => 'escalation.view',   'name' => 'View escalation rules',   'group' => 'Escalation Rules', 'description' => 'View escalation rules'],
            ['slug' => 'escalation.manage', 'name' => 'Manage escalation rules', 'group' => 'Escalation Rules', 'description' => 'Create, edit, delete escalation rules'],
            // Macros
            ['slug' => 'macro.view',   'name' => 'View macros',   'group' => 'Macros', 'description' => 'View macros'],
            ['slug' => 'macro.create', 'name' => 'Create macros', 'group' => 'Macros', 'description' => 'Create personal macros'],
            ['slug' => 'macro.manage', 'name' => 'Manage macros', 'group' => 'Macros', 'description' => 'Create, edit, delete shared macros'],
            // Tags
            ['slug' => 'tag.view',   'name' => 'View tags',   'group' => 'Tags', 'description' => 'View tags'],
            ['slug' => 'tag.manage', 'name' => 'Manage tags', 'group' => 'Tags', 'description' => 'Create, edit, delete tags'],
            // Custom Fields
            ['slug' => 'custom_field.view',   'name' => 'View custom fields',   'group' => 'Custom Fields', 'description' => 'View custom fields'],
            ['slug' => 'custom_field.manage', 'name' => 'Manage custom fields', 'group' => 'Custom Fields', 'description' => 'Create, edit, delete custom fields'],
            // Roles
            ['slug' => 'role.view',   'name' => 'View roles',   'group' => 'Roles', 'description' => 'View roles'],
            ['slug' => 'role.manage', 'name' => 'Manage roles', 'group' => 'Roles', 'description' => 'Create, edit, delete roles and assign permissions'],
            // Users
            ['slug' => 'user.view',   'name' => 'View users',   'group' => 'Users', 'description' => 'View user profiles'],
            ['slug' => 'user.manage', 'name' => 'Manage users', 'group' => 'Users', 'description' => 'Manage user accounts and agent profiles'],
            // Settings
            ['slug' => 'settings.view',   'name' => 'View settings',   'group' => 'Settings', 'description' => 'View settings'],
            ['slug' => 'settings.manage', 'name' => 'Manage settings', 'group' => 'Settings', 'description' => 'Manage system settings'],
            // Webhooks
            ['slug' => 'webhook.view',   'name' => 'View webhooks',   'group' => 'Webhooks', 'description' => 'View webhooks'],
            ['slug' => 'webhook.manage', 'name' => 'Manage webhooks', 'group' => 'Webhooks', 'description' => 'Create, edit, delete webhooks'],
            // API Tokens
            ['slug' => 'api_token.view',   'name' => 'View API tokens',   'group' => 'API Tokens', 'description' => 'View API tokens'],
            ['slug' => 'api_token.manage', 'name' => 'Manage API tokens', 'group' => 'API Tokens', 'description' => 'Create, revoke API tokens'],
            // Audit Log
            ['slug' => 'audit.view', 'name' => 'View audit log', 'group' => 'Audit Log', 'description' => 'View audit log'],
            // Plugins
            ['slug' => 'plugin.view',   'name' => 'View plugins',   'group' => 'Plugins', 'description' => 'View plugins'],
            ['slug' => 'plugin.manage', 'name' => 'Manage plugins', 'group' => 'Plugins', 'description' => 'Install, configure, remove plugins'],
            // Custom Objects
            ['slug' => 'custom_object.view',   'name' => 'View custom objects',       'group' => 'Custom Objects', 'description' => 'View custom objects'],
            ['slug' => 'custom_object.manage', 'name' => 'Manage custom objects',     'group' => 'Custom Objects', 'description' => 'Create, edit, delete custom object schemas'],
            ['slug' => 'custom_object.data',   'name' => 'Manage custom object data', 'group' => 'Custom Objects', 'description' => 'Manage custom object records'],
        ];
    }

    /**
     * Convert a permission slug to a WordPress capability name.
     *
     * Example: "ticket.view" → "escalated_ticket_view"
     */
    private static function slug_to_cap(string $slug): string
    {
        return 'escalated_'.str_replace('.', '_', $slug);
    }

    /**
     * Get all escalated capabilities (derived from permission slugs).
     */
    private static function get_escalated_caps(): array
    {
        return array_map(
            [self::class, 'slug_to_cap'],
            array_column(self::get_permission_definitions(), 'slug')
        );
    }

    /**
     * Get the permission slugs assigned to the Agent role.
     */
    private static function get_agent_permission_slugs(): array
    {
        return [
            'ticket.view', 'ticket.create', 'ticket.edit', 'ticket.delete',
            'ticket.assign', 'ticket.merge', 'ticket.close', 'ticket.export',
            'reply.create', 'reply.create_internal', 'reply.edit', 'reply.delete',
            'kb.view',
            'report.view',
            'macro.view', 'macro.create',
            'tag.view',
            'custom_field.view',
            'audit.view',
        ];
    }

    /**
     * Get the permission slugs assigned to the Light Agent role.
     */
    private static function get_light_agent_permission_slugs(): array
    {
        return [
            'ticket.view',
            'reply.create', 'reply.create_internal',
            'kb.view',
            'macro.view',
            'tag.view',
        ];
    }

    /**
     * Register custom WordPress roles: escalated_admin, escalated_agent, escalated_light_agent.
     */
    private static function create_roles(): void
    {
        // --- Escalated Admin (all escalated capabilities) ---
        $editor_role = get_role('editor');
        $admin_caps = $editor_role ? $editor_role->capabilities : [];

        foreach (self::get_escalated_caps() as $cap) {
            $admin_caps[$cap] = true;
        }

        remove_role('escalated_admin');
        add_role('escalated_admin', 'Escalated Admin', $admin_caps);

        // --- Escalated Agent ---
        $subscriber_role = get_role('subscriber');
        $agent_caps = $subscriber_role ? $subscriber_role->capabilities : [];

        foreach (self::get_agent_permission_slugs() as $slug) {
            $agent_caps[self::slug_to_cap($slug)] = true;
        }

        remove_role('escalated_agent');
        add_role('escalated_agent', 'Escalated Agent', $agent_caps);

        // --- Escalated Light Agent ---
        $light_caps = $subscriber_role ? $subscriber_role->capabilities : [];

        foreach (self::get_light_agent_permission_slugs() as $slug) {
            $light_caps[self::slug_to_cap($slug)] = true;
        }

        remove_role('escalated_light_agent');
        add_role('escalated_light_agent', 'Escalated Light Agent', $light_caps);
    }

    /**
     * Add all escalated capabilities to the WordPress administrator role.
     */
    private static function add_admin_caps(): void
    {
        $admin_role = get_role('administrator');
        if (! $admin_role) {
            return;
        }

        foreach (self::get_escalated_caps() as $cap) {
            $admin_role->add_cap($cap);
        }
    }

    /**
     * Seed granular permissions and default system roles into the
     * escalated_permissions, escalated_roles, and escalated_role_permissions tables.
     *
     * This method is idempotent — safe to run multiple times.
     */
    private static function seed_permissions(): void
    {
        global $wpdb;

        $perm_table = $wpdb->prefix.'escalated_permissions';
        $role_table = $wpdb->prefix.'escalated_roles';
        $pivot_table = $wpdb->prefix.'escalated_role_permissions';
        $now = current_time('mysql');
        $definitions = self::get_permission_definitions();

        // ---- Upsert permissions ----
        // Note: `group` is a MySQL reserved word, so we use raw SQL with backtick-quoted column names.
        foreach ($definitions as $attrs) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$perm_table} WHERE slug = %s", $attrs['slug'])
            );

            if ($exists) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$perm_table} SET name = %s, `group` = %s, description = %s, updated_at = %s WHERE id = %d",
                    $attrs['name'],
                    $attrs['group'],
                    $attrs['description'],
                    $now,
                    $exists
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$perm_table} (name, slug, `group`, description, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s)",
                    $attrs['name'],
                    $attrs['slug'],
                    $attrs['group'],
                    $attrs['description'],
                    $now,
                    $now
                ));
            }
        }

        // Build slug → id index.
        $rows = $wpdb->get_results("SELECT id, slug FROM {$perm_table}", OBJECT);
        $slug_index = [];
        foreach ($rows as $row) {
            $slug_index[$row->slug] = (int) $row->id;
        }

        // ---- Upsert roles ----
        $role_defs = [
            [
                'slug' => 'admin',
                'name' => 'Admin',
                'description' => 'Full access to all features and settings.',
                'permissions' => array_column($definitions, 'slug'), // all
            ],
            [
                'slug' => 'agent',
                'name' => 'Agent',
                'description' => 'Standard agent with ticket handling and limited administrative access.',
                'permissions' => self::get_agent_permission_slugs(),
            ],
            [
                'slug' => 'light_agent',
                'name' => 'Light Agent',
                'description' => 'Limited agent with read-only ticket access and internal note capability.',
                'permissions' => self::get_light_agent_permission_slugs(),
            ],
        ];

        foreach ($role_defs as $def) {
            $role_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$role_table} WHERE slug = %s", $def['slug'])
            );

            if ($role_id) {
                $wpdb->update(
                    $role_table,
                    [
                        'name' => $def['name'],
                        'description' => $def['description'],
                        'is_system' => 1,
                        'updated_at' => $now,
                    ],
                    ['id' => $role_id]
                );
            } else {
                $wpdb->insert(
                    $role_table,
                    [
                        'name' => $def['name'],
                        'slug' => $def['slug'],
                        'description' => $def['description'],
                        'is_system' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                $role_id = $wpdb->insert_id;
            }

            // Sync pivot: delete existing, then re-insert.
            $wpdb->delete($pivot_table, ['role_id' => $role_id]);

            foreach ($def['permissions'] as $slug) {
                if (isset($slug_index[$slug])) {
                    $wpdb->insert(
                        $pivot_table,
                        [
                            'role_id' => (int) $role_id,
                            'permission_id' => $slug_index[$slug],
                        ]
                    );
                }
            }
        }
    }

    /**
     * Insert default settings into the escalated_settings table.
     */
    private static function insert_default_settings(): void
    {
        global $wpdb;

        $table = $wpdb->prefix.'escalated_settings';
        $now = current_time('mysql');
        $defaults = [
            'ticket_reference_prefix' => 'ESC',
            'default_priority' => 'medium',
            'guest_tickets_enabled' => '1',
            'auto_close_days' => '7',
            'auto_close_enabled' => '0',
            'inbound_email_enabled' => '0',
            'sla_warning_minutes' => '30',
            'activity_purge_days' => '90',
            'max_attachment_size_kb' => '10240',
            'max_attachments_per_reply' => '5',
            'webhook_url' => '',
            'webhook_secret' => '',
        ];

        foreach ($defaults as $key => $value) {
            // Only insert if the setting does not already exist.
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE option_key = %s", $key)
            );

            if (! $exists) {
                $wpdb->insert(
                    $table,
                    [
                        'option_key' => $key,
                        'option_value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['%s', '%s', '%s', '%s']
                );
            }
        }
    }

    /**
     * Optional newsletter system tables. Not registered to any specific
     * activation flag — the data is harmless if unused. Behavior is gated by
     * the `escalated_newsletters_enabled` option.
     */
    public static function create_newsletter_tables(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $prefix = $wpdb->prefix.'escalated_';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$prefix}newsletter_lists (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            kind VARCHAR(16) NOT NULL,
            filter_json TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY kind (kind),
            KEY created_by (created_by)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}newsletter_list_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            added_at DATETIME NOT NULL,
            added_by BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY list_contact (list_id, contact_id),
            KEY contact_id (contact_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}newsletter_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            theme VARCHAR(64) NOT NULL DEFAULT 'default',
            subject_template VARCHAR(998) NULL,
            body_markdown LONGTEXT NOT NULL,
            merge_fields_schema TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY theme (theme),
            KEY created_by (created_by)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}newsletters (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subject VARCHAR(998) NOT NULL,
            from_email VARCHAR(320) NOT NULL,
            from_name VARCHAR(255) NULL,
            reply_to VARCHAR(320) NULL,
            target_list_id BIGINT UNSIGNED NOT NULL,
            template_id BIGINT UNSIGNED NULL,
            theme VARCHAR(64) NULL,
            body_markdown LONGTEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            sent_by BIGINT UNSIGNED NULL,
            summary_total INT UNSIGNED NOT NULL DEFAULT 0,
            summary_sent INT UNSIGNED NOT NULL DEFAULT 0,
            summary_opened INT UNSIGNED NOT NULL DEFAULT 0,
            summary_clicked INT UNSIGNED NOT NULL DEFAULT 0,
            summary_bounced INT UNSIGNED NOT NULL DEFAULT 0,
            summary_complained INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY scheduled_at (scheduled_at),
            KEY status_sched (status, scheduled_at),
            KEY created_by (created_by)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}newsletter_deliveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            newsletter_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            email_at_send VARCHAR(320) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            tracking_token VARCHAR(40) NOT NULL,
            sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            last_clicked_at DATETIME NULL,
            clicks_count INT UNSIGNED NOT NULL DEFAULT 0,
            bounce_reason TEXT NULL,
            failure_reason TEXT NULL,
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            claimed_at DATETIME NULL,
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tracking_token (tracking_token),
            KEY nl_status (newsletter_id, status),
            KEY contact_id (contact_id),
            KEY status_claimed (status, claimed_at)
        ) $charset_collate;";
        dbDelta($sql);

        // Add marketing_opt_out_at column to contacts if it doesn't exist.
        $contacts_table = $prefix.'contacts';
        $col = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$contacts_table}` LIKE %s",
            'marketing_opt_out_at'
        ));
        if (! $col) {
            $wpdb->query("ALTER TABLE `{$contacts_table}` ADD COLUMN marketing_opt_out_at DATETIME NULL");
            $wpdb->query("ALTER TABLE `{$contacts_table}` ADD INDEX marketing_opt_out_at (marketing_opt_out_at)");
        }
    }

    /**
     * Schedule cron events and register custom cron intervals.
     */
    private static function schedule_cron_events(): void
    {
        // Register custom cron intervals via filter.
        add_filter('cron_schedules', [self::class, 'add_cron_schedules']);

        if (! wp_next_scheduled('escalated_check_sla')) {
            wp_schedule_event(time(), 'escalated_every_minute', 'escalated_check_sla');
        }

        if (! wp_next_scheduled('escalated_evaluate_escalations')) {
            wp_schedule_event(time(), 'escalated_every_five_minutes', 'escalated_evaluate_escalations');
        }

        if (! wp_next_scheduled('escalated_auto_close')) {
            wp_schedule_event(time(), 'daily', 'escalated_auto_close');
        }

        if (! wp_next_scheduled('escalated_purge_activities')) {
            wp_schedule_event(time(), 'weekly', 'escalated_purge_activities');
        }

        if (! wp_next_scheduled('escalated_run_automations')) {
            wp_schedule_event(time(), 'escalated_every_five_minutes', 'escalated_run_automations');
        }

        if (! wp_next_scheduled('escalated_check_snoozed_tickets')) {
            wp_schedule_event(time(), 'escalated_every_minute', 'escalated_check_snoozed_tickets');
        }

        if (! wp_next_scheduled('escalated_chat_cleanup')) {
            wp_schedule_event(time(), 'escalated_every_minute', 'escalated_chat_cleanup');
        }

        if (! wp_next_scheduled('escalated_run_due_deferred_workflow_jobs')) {
            wp_schedule_event(time(), 'escalated_every_minute', 'escalated_run_due_deferred_workflow_jobs');
        }
    }

    /**
     * Add custom cron schedule intervals.
     *
     * @param  array  $schedules  Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public static function add_cron_schedules(array $schedules): array
    {
        $schedules['escalated_every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute (Escalated)', 'escalated'),
        ];

        $schedules['escalated_every_five_minutes'] = [
            'interval' => 300,
            'display' => __('Every Five Minutes (Escalated)', 'escalated'),
        ];

        return $schedules;
    }
}
