<?php
namespace Escalated\Admin;

use Escalated\Models\Ticket;
use Escalated\Models\Department;
use Escalated\Helpers\Enums;
use Escalated\Escalated;

class Admin_Reports {

    /**
     * Render the reports admin page.
     */
    public function render(): void {
        global $wpdb;

        $ticket_table = Ticket::table();
        $dept_table   = Department::table();

        // Tickets by status.
        $by_status = Ticket::count_by_status();

        // Tickets by priority.
        $by_priority_rows = $wpdb->get_results(
            "SELECT priority, COUNT(*) AS count FROM {$ticket_table} WHERE deleted_at IS NULL GROUP BY priority"
        );
        $by_priority = [];
        if ( $by_priority_rows ) {
            foreach ( $by_priority_rows as $row ) {
                $by_priority[ $row->priority ] = (int) $row->count;
            }
        }

        // Tickets by department.
        $by_department_rows = $wpdb->get_results(
            "SELECT d.name AS department_name, COUNT(t.id) AS count
             FROM {$ticket_table} t
             LEFT JOIN {$dept_table} d ON d.id = t.department_id
             WHERE t.deleted_at IS NULL
             GROUP BY t.department_id, d.name
             ORDER BY count DESC"
        );

        // Tickets by agent (top 10).
        $by_agent_rows = $wpdb->get_results(
            "SELECT assigned_to, COUNT(*) AS count
             FROM {$ticket_table}
             WHERE deleted_at IS NULL AND assigned_to IS NOT NULL
             GROUP BY assigned_to
             ORDER BY count DESC
             LIMIT 10"
        );

        // Overall stats.
        $total_tickets = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL"
        );

        $open_tickets = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL AND status IN ('open', 'in_progress', 'waiting_on_customer', 'waiting_on_agent', 'escalated', 'reopened')"
        );

        $resolved_tickets = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL AND status = 'resolved'"
        );

        $closed_tickets = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL AND status = 'closed'"
        );

        // SLA compliance.
        $sla_first_response_breached = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL AND sla_first_response_breached = 1"
        );

        $sla_resolution_breached = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL AND sla_resolution_breached = 1"
        );

        $tickets_with_sla = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL AND sla_policy_id IS NOT NULL"
        );

        $sla_compliance_rate = $tickets_with_sla > 0
            ? round( ( ( $tickets_with_sla - $sla_first_response_breached - $sla_resolution_breached ) / $tickets_with_sla ) * 100, 1 )
            : 100;
        $sla_compliance_rate = max( 0, $sla_compliance_rate );

        // Average first response time (in hours) for resolved/closed tickets.
        $avg_first_response = $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, first_response_at))
             FROM {$ticket_table}
             WHERE deleted_at IS NULL AND first_response_at IS NOT NULL"
        );
        $avg_first_response = $avg_first_response !== null ? round( (float) $avg_first_response, 1 ) : null;

        // Average resolution time (in hours).
        $avg_resolution = $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at))
             FROM {$ticket_table}
             WHERE deleted_at IS NULL AND resolved_at IS NOT NULL"
        );
        $avg_resolution = $avg_resolution !== null ? round( (float) $avg_resolution, 1 ) : null;

        // Tickets created in last 30 days.
        $recent_tickets = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$ticket_table} WHERE deleted_at IS NULL AND created_at >= %s",
                gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
            )
        );

        $statuses   = Enums::ticket_statuses();
        $priorities = Enums::ticket_priorities();

        include ESCALATED_PLUGIN_DIR . 'templates/admin/reports.php';
    }
}
