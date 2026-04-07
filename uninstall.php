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
    foreach ($caps as $cap) {
        $admin_role->remove_cap($cap);
    }
}
