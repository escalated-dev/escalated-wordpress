<?php

namespace Escalated\Services;

use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;
use Escalated\Models\Department;

class AssignmentService {

    /**
     * Assign a ticket to an agent.
     *
     * Updates the ticket's assigned_to field, logs the activity, and fires
     * the escalated_ticket_assigned action hook.
     *
     * @param int      $ticket_id Ticket ID to assign.
     * @param int      $agent_id  WordPress user ID of the agent.
     * @param int|null $causer_id User who triggered the assignment (null for system).
     * @return object The updated ticket.
     */
    public function assign( int $ticket_id, int $agent_id, ?int $causer_id = null ): object {
        $ticket = Ticket::find( $ticket_id );
        if ( ! $ticket ) {
            throw new \InvalidArgumentException( 'Ticket not found.' );
        }

        $old_agent_id = $ticket->assigned_to;

        Ticket::update( $ticket_id, [ 'assigned_to' => $agent_id ] );

        $this->log_activity( $ticket_id, $causer_id, 'assigned', [
            'old_agent_id' => $old_agent_id,
            'new_agent_id' => $agent_id,
        ] );

        $ticket = Ticket::find( $ticket_id );

        do_action( 'escalated_ticket_assigned', $ticket, $agent_id, $old_agent_id, $causer_id );

        return $ticket;
    }

    /**
     * Unassign a ticket (remove the assigned agent).
     *
     * Sets the ticket's assigned_to to null and logs the activity.
     *
     * @param int      $ticket_id Ticket ID to unassign.
     * @param int|null $causer_id User who triggered the unassignment.
     * @return object The updated ticket.
     */
    public function unassign( int $ticket_id, ?int $causer_id = null ): object {
        $ticket = Ticket::find( $ticket_id );
        if ( ! $ticket ) {
            throw new \InvalidArgumentException( 'Ticket not found.' );
        }

        $old_agent_id = $ticket->assigned_to;

        Ticket::update( $ticket_id, [ 'assigned_to' => null ] );

        $this->log_activity( $ticket_id, $causer_id, 'unassigned', [
            'old_agent_id' => $old_agent_id,
        ] );

        $ticket = Ticket::find( $ticket_id );

        do_action( 'escalated_ticket_unassigned', $ticket, $old_agent_id, $causer_id );

        return $ticket;
    }

    /**
     * Automatically assign a ticket using round-robin (lowest open ticket count).
     *
     * Finds the ticket's department, retrieves the department's agents,
     * and assigns the ticket to the agent with the fewest open tickets.
     *
     * @param int $ticket_id Ticket ID to auto-assign.
     * @return object|null The updated ticket, or null if no agents are available.
     */
    public function auto_assign( int $ticket_id ): ?object {
        $ticket = Ticket::find( $ticket_id );
        if ( ! $ticket ) {
            throw new \InvalidArgumentException( 'Ticket not found.' );
        }

        // If the ticket already has an agent, skip auto-assignment.
        if ( ! empty( $ticket->assigned_to ) ) {
            return $ticket;
        }

        $agent_ids = [];

        // If the ticket belongs to a department, get agents from that department.
        if ( ! empty( $ticket->department_id ) ) {
            $agent_ids = Department::agents( (int) $ticket->department_id );
        }

        // Fall back to all users with escalated_agent or escalated_admin roles.
        if ( empty( $agent_ids ) ) {
            $agent_ids = $this->get_all_agent_ids();
        }

        if ( empty( $agent_ids ) ) {
            return null;
        }

        // Find the agent with the lowest open ticket count.
        $best_agent_id = null;
        $lowest_count  = PHP_INT_MAX;

        foreach ( $agent_ids as $agent_id ) {
            $count = Ticket::count_for_agent( (int) $agent_id );
            if ( $count < $lowest_count ) {
                $lowest_count  = $count;
                $best_agent_id = (int) $agent_id;
            }
        }

        if ( $best_agent_id === null ) {
            return null;
        }

        return $this->assign( $ticket_id, $best_agent_id );
    }

    /**
     * Get workload statistics for an agent.
     *
     * Returns counts of open tickets, tickets resolved today, and SLA-breached tickets.
     *
     * @param int $agent_id WordPress user ID of the agent.
     * @return array {
     *     @type int $open_count       Number of open tickets assigned to the agent.
     *     @type int $resolved_today   Number of tickets resolved today by the agent.
     *     @type int $sla_breached     Number of SLA-breached open tickets assigned to the agent.
     * }
     */
    public function get_agent_workload( int $agent_id ): array {
        global $wpdb;

        $table = Ticket::table();
        $open_scope = Ticket::scope_open();
        $today_start = current_time( 'Y-m-d' ) . ' 00:00:00';

        // Count open tickets assigned to agent.
        $open_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                WHERE assigned_to = %d AND {$open_scope} AND deleted_at IS NULL",
                $agent_id
            )
        );

        // Count tickets resolved today.
        $resolved_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                WHERE assigned_to = %d AND status = 'resolved' AND resolved_at >= %s AND deleted_at IS NULL",
                $agent_id,
                $today_start
            )
        );

        // Count SLA-breached open tickets.
        $sla_breached = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                WHERE assigned_to = %d AND {$open_scope}
                AND (sla_first_response_breached = 1 OR sla_resolution_breached = 1)
                AND deleted_at IS NULL",
                $agent_id
            )
        );

        return [
            'open_count'     => $open_count,
            'resolved_today' => $resolved_today,
            'sla_breached'   => $sla_breached,
        ];
    }

    /**
     * Get all WordPress user IDs that have agent or admin roles.
     *
     * @return array Array of user IDs.
     */
    protected function get_all_agent_ids(): array {
        $agents = get_users( [
            'role__in' => [ 'escalated_agent', 'escalated_admin', 'administrator' ],
            'fields'   => 'ID',
        ] );

        return array_map( 'intval', $agents );
    }

    /**
     * Log a ticket activity entry.
     *
     * @param int      $ticket_id  Ticket ID.
     * @param int|null $causer_id  User who caused the activity.
     * @param string   $type       Activity type.
     * @param array    $properties Additional properties to store as JSON.
     */
    protected function log_activity( int $ticket_id, ?int $causer_id, string $type, array $properties = [] ): void {
        TicketActivity::create( [
            'ticket_id'  => $ticket_id,
            'causer_id'  => $causer_id,
            'type'       => $type,
            'properties' => ! empty( $properties ) ? wp_json_encode( $properties ) : null,
            'created_at' => current_time( 'mysql' ),
        ] );
    }
}
