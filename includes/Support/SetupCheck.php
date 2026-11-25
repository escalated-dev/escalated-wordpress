<?php

namespace Escalated\Support;

use Escalated\Escalated;

/**
 * Setup problems that otherwise surface only as "Sorry, you are not allowed
 * to access this page" or as SLA timers that never move: Escalated's tables,
 * roles and capabilities missing (the plugin was dropped in or loaded as a
 * must-use plugin without its activation hook running), nobody able to work
 * tickets, and recurring jobs that are not scheduled or not running.
 *
 * WordPress has no host user model to get wrong: every user is a WP_User and
 * every staff check is current_user_can(), which answers false instead of
 * throwing. The WordPress equivalent of "gates are not defined" is the
 * capabilities not being granted, which is what this checks.
 *
 * Run it with `wp escalated doctor`.
 */
final class SetupCheck
{
    /** Capabilities the administrator role must hold for Escalated's admin to work. */
    public const ADMIN_CAPABILITIES = [
        'escalated_ticket_view',
        'escalated_ticket_edit',
        'escalated_reply_create',
        'escalated_settings_view',
        'escalated_settings_manage',
    ];

    public const ROLES = ['escalated_admin', 'escalated_agent', 'escalated_light_agent'];

    /**
     * @return list<array{level: 'error'|'warning', message: string}>
     */
    public static function issues(): array
    {
        $issues = [];
        $reactivate = 'Deactivate and reactivate Escalated (or call \Escalated\Activator::activate() from your installer) to create it.';

        $db = Escalated::db();
        $tickets = Escalated::table('tickets');
        if ($db->get_var($db->prepare('SHOW TABLES LIKE %s', $tickets)) !== $tickets) {
            $issues[] = ['level' => 'error', 'message' => "Escalated's tables are missing ({$tickets} not found). ".$reactivate];
        }

        foreach (self::ROLES as $role) {
            if (get_role($role) === null) {
                $issues[] = ['level' => 'error', 'message' => "The {$role} role is missing. ".$reactivate];
            }
        }

        $admin = get_role('administrator');
        $missing = $admin === null
            ? self::ADMIN_CAPABILITIES
            : array_values(array_filter(self::ADMIN_CAPABILITIES, static fn ($cap) => ! $admin->has_cap($cap)));
        if ($missing !== []) {
            $issues[] = [
                'level' => 'error',
                'message' => 'The administrator role is missing Escalated capabilities ('.implode(', ', $missing).'), so administrators cannot open Escalated\'s screens. '
                    .'Deactivate and reactivate Escalated to grant them, or grant them with your role manager.',
            ];
        }

        $staff = get_users(['capability' => 'escalated_ticket_view', 'number' => 1, 'fields' => 'ID']);
        if ($staff === []) {
            $issues[] = [
                'level' => 'warning',
                'message' => 'No user holds escalated_ticket_view, so nobody can work tickets. Give staff the Escalated Agent or Escalated Admin role.',
            ];
        }

        $unscheduled = SchedulerHealth::unscheduled_events();
        if ($unscheduled !== []) {
            $issues[] = [
                'level' => 'error',
                'message' => 'These recurring events are not scheduled: '.implode(', ', $unscheduled).'. Deactivate and reactivate Escalated to schedule them.',
            ];
        }

        $scheduler = SchedulerHealth::status();
        if (! $scheduler['healthy']) {
            $issues[] = ['level' => 'warning', 'message' => $scheduler['message']];
        }

        return $issues;
    }
}
