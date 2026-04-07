<?php

namespace Escalated\Helpers;

class Enums
{
    public static function ticket_statuses(): array
    {
        return [
            'open' => ['label' => __('Open', 'escalated'),                'color' => '#3B82F6'],
            'in_progress' => ['label' => __('In Progress', 'escalated'),         'color' => '#8B5CF6'],
            'waiting_on_customer' => ['label' => __('Waiting on Customer', 'escalated'), 'color' => '#F59E0B'],
            'waiting_on_agent' => ['label' => __('Waiting on Agent', 'escalated'),    'color' => '#F97316'],
            'escalated' => ['label' => __('Escalated', 'escalated'),           'color' => '#EF4444'],
            'resolved' => ['label' => __('Resolved', 'escalated'),            'color' => '#10B981'],
            'closed' => ['label' => __('Closed', 'escalated'),              'color' => '#6B7280'],
            'reopened' => ['label' => __('Reopened', 'escalated'),             'color' => '#3B82F6'],
        ];
    }

    public static function ticket_priorities(): array
    {
        return [
            'low' => ['label' => __('Low', 'escalated'),      'color' => '#6B7280', 'weight' => 1],
            'medium' => ['label' => __('Medium', 'escalated'),   'color' => '#3B82F6', 'weight' => 2],
            'high' => ['label' => __('High', 'escalated'),     'color' => '#F59E0B', 'weight' => 3],
            'urgent' => ['label' => __('Urgent', 'escalated'),   'color' => '#F97316', 'weight' => 4],
            'critical' => ['label' => __('Critical', 'escalated'), 'color' => '#EF4444', 'weight' => 5],
        ];
    }

    public static function activity_types(): array
    {
        return [
            'status_changed' => __('Status changed', 'escalated'),
            'assigned' => __('Assigned', 'escalated'),
            'unassigned' => __('Unassigned', 'escalated'),
            'priority_changed' => __('Priority changed', 'escalated'),
            'tag_added' => __('Tag added', 'escalated'),
            'tag_removed' => __('Tag removed', 'escalated'),
            'escalated' => __('Escalated', 'escalated'),
            'sla_breached' => __('SLA breached', 'escalated'),
            'replied' => __('Replied', 'escalated'),
            'note_added' => __('Note added', 'escalated'),
            'department_changed' => __('Department changed', 'escalated'),
            'reopened' => __('Reopened', 'escalated'),
            'resolved' => __('Resolved', 'escalated'),
            'closed' => __('Closed', 'escalated'),
        ];
    }

    public static function allowed_transitions(): array
    {
        $transitions = [
            'open' => ['in_progress', 'waiting_on_customer', 'waiting_on_agent', 'escalated', 'resolved', 'closed'],
            'in_progress' => ['waiting_on_customer', 'waiting_on_agent', 'escalated', 'resolved', 'closed'],
            'waiting_on_customer' => ['open', 'in_progress', 'waiting_on_agent', 'escalated', 'resolved', 'closed'],
            'waiting_on_agent' => ['open', 'in_progress', 'waiting_on_customer', 'escalated', 'resolved', 'closed'],
            'escalated' => ['open', 'in_progress', 'waiting_on_customer', 'waiting_on_agent', 'resolved', 'closed'],
            'resolved' => ['reopened', 'closed'],
            'closed' => ['reopened'],
            'reopened' => ['open', 'in_progress', 'waiting_on_customer', 'waiting_on_agent', 'escalated', 'resolved', 'closed'],
        ];

        return apply_filters('escalated_allowed_transitions', $transitions);
    }

    public static function can_transition(string $from, string $to): bool
    {
        $transitions = self::allowed_transitions();
        $allowed = $transitions[$from] ?? [];

        return in_array($to, $allowed, true);
    }

    public static function is_open_status(string $status): bool
    {
        return ! in_array($status, ['resolved', 'closed'], true);
    }

    public static function blocked_extensions(): array
    {
        $defaults = [
            'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'pif', 'vbs', 'vbe',
            'js', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'psd1', 'reg',
            'cpl', 'hta', 'inf', 'lnk', 'sct', 'shb', 'sys', 'drv',
            'php', 'phtml', 'php3', 'php4', 'php5', 'phar',
            'sh', 'bash', 'csh', 'ksh', 'pl', 'py', 'rb',
            'dll', 'so', 'dylib',
        ];

        return apply_filters('escalated_blocked_extensions', $defaults);
    }
}
