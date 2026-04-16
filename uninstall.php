<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = [
    'escalated_api_tokens',
    'escalated_settings',
    'escalated_satisfaction_ratings',
    'escalated_ticket_followers',
    'escalated_inbound_emails',
    'escalated_macros',
    'escalated_canned_responses',
    'escalated_escalation_rules',
    'escalated_ticket_activities',
    'escalated_ticket_tag',
    'escalated_tags',
    'escalated_attachments',
    'escalated_replies',
    'escalated_tickets',
    'escalated_sla_policies',
    'escalated_department_agent',
    'escalated_departments',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

remove_role('escalated_admin');
remove_role('escalated_agent');

$admin_role = get_role('administrator');
if ($admin_role) {
    $caps = [
        'escalated_ticket_view', 'escalated_ticket_create', 'escalated_ticket_edit', 'escalated_ticket_delete',
        'escalated_ticket_assign', 'escalated_ticket_merge', 'escalated_ticket_close', 'escalated_ticket_export',
        'escalated_reply_create', 'escalated_reply_create_internal', 'escalated_reply_edit', 'escalated_reply_delete',
        'escalated_kb_view', 'escalated_kb_create', 'escalated_kb_edit', 'escalated_kb_delete', 'escalated_kb_publish',
        'escalated_department_view', 'escalated_department_create', 'escalated_department_edit', 'escalated_department_delete',
        'escalated_report_view', 'escalated_report_export',
        'escalated_sla_view', 'escalated_sla_manage',
        'escalated_automation_view', 'escalated_automation_manage',
        'escalated_escalation_view', 'escalated_escalation_manage',
        'escalated_macro_view', 'escalated_macro_create', 'escalated_macro_manage',
        'escalated_tag_view', 'escalated_tag_manage',
        'escalated_custom_field_view', 'escalated_custom_field_manage',
        'escalated_role_view', 'escalated_role_manage',
        'escalated_user_view', 'escalated_user_manage',
        'escalated_settings_view', 'escalated_settings_manage',
        'escalated_webhook_view', 'escalated_webhook_manage',
        'escalated_api_token_view', 'escalated_api_token_manage',
        'escalated_audit_view',
        'escalated_plugin_view', 'escalated_plugin_manage',
        'escalated_custom_object_view', 'escalated_custom_object_manage', 'escalated_custom_object_data',
    ];
    foreach ($caps as $cap) {
        $admin_role->remove_cap($cap);
    }
}

remove_role('escalated_light_agent');
