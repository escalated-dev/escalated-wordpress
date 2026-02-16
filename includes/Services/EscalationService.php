<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;

class EscalationService {

    /**
     * Evaluate all active escalation rules against matching tickets.
     *
     * Iterates through active escalation rules sorted by sort_order,
     * finds tickets matching each rule's conditions, and executes the rule's actions.
     *
     * @return int Total number of tickets that had actions executed.
     */
    public function evaluate_rules(): int {
        global $wpdb;

        $table = Escalated::table( 'escalation_rules' );
        $rules = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC"
        );

        if ( ! $rules ) {
            return 0;
        }

        $total_affected = 0;

        foreach ( $rules as $rule ) {
            $tickets = $this->find_matching_tickets( $rule );

            foreach ( $tickets as $ticket ) {
                $this->execute_actions( $ticket, $rule );
                $total_affected++;
            }
        }

        return $total_affected;
    }

    /**
     * Find tickets matching an escalation rule's conditions.
     *
     * Builds a dynamic query based on the rule's conditions JSON. Supported conditions:
     * - status: Match specific ticket status.
     * - priority: Match specific ticket priority.
     * - assigned: Boolean, true = has agent, false = unassigned.
     * - age_hours: Minimum ticket age in hours.
     * - no_response_hours: Minimum hours without a first response.
     * - sla_breached: Boolean, true = SLA has been breached.
     * - department_id: Match specific department.
     *
     * @param object $rule Escalation rule object with a conditions JSON field.
     * @return array Array of ticket objects matching the conditions.
     */
    public function find_matching_tickets( object $rule ): array {
        global $wpdb;

        $table = Ticket::table();
        $conditions = json_decode( $rule->conditions, true );

        if ( empty( $conditions ) || ! is_array( $conditions ) ) {
            return [];
        }

        $where  = [ 't.deleted_at IS NULL' ];
        $values = [];
        $now    = current_time( 'mysql' );

        // Only consider open/active tickets by default.
        $open_scope = Ticket::scope_open();

        foreach ( $conditions as $condition ) {
            $field    = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '=';
            $value    = $condition['value'] ?? '';

            switch ( $field ) {
                case 'status':
                    $where[]  = 't.status = %s';
                    $values[] = sanitize_text_field( $value );
                    break;

                case 'priority':
                    $where[]  = 't.priority = %s';
                    $values[] = sanitize_text_field( $value );
                    break;

                case 'assigned':
                    if ( $value === true || $value === 'true' || $value === '1' ) {
                        $where[] = 't.assigned_to IS NOT NULL';
                    } else {
                        $where[] = 't.assigned_to IS NULL';
                    }
                    break;

                case 'age_hours':
                    $age_threshold = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( absint( $value ) * 3600 ) );
                    $where[]  = 't.created_at <= %s';
                    $values[] = $age_threshold;
                    // Only apply to open tickets for age-based rules.
                    $where[] = 't.' . $open_scope;
                    break;

                case 'no_response_hours':
                    $response_threshold = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( absint( $value ) * 3600 ) );
                    $where[]  = 't.first_response_at IS NULL';
                    $where[]  = 't.created_at <= %s';
                    $values[] = $response_threshold;
                    $where[]  = 't.' . $open_scope;
                    break;

                case 'sla_breached':
                    if ( $value === true || $value === 'true' || $value === '1' ) {
                        $where[] = '(t.sla_first_response_breached = 1 OR t.sla_resolution_breached = 1)';
                    }
                    break;

                case 'department_id':
                    $where[]  = 't.department_id = %d';
                    $values[] = absint( $value );
                    break;
            }
        }

        $where_clause = implode( ' AND ', $where );
        $sql = "SELECT t.* FROM {$table} AS t WHERE {$where_clause}";

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        $results = $wpdb->get_results( $sql );

        return $results ?: [];
    }

    /**
     * Execute a rule's actions against a specific ticket.
     *
     * Supported action types:
     * - escalate: Change ticket status to 'escalated'.
     * - change_priority: Change the ticket's priority.
     * - assign_to: Assign the ticket to a specific agent.
     * - change_department: Move the ticket to a different department.
     *
     * @param object $ticket The ticket object to act upon.
     * @param object $rule   The escalation rule with an actions JSON field.
     */
    public function execute_actions( object $ticket, object $rule ): void {
        $actions = json_decode( $rule->actions, true );

        if ( empty( $actions ) || ! is_array( $actions ) ) {
            return;
        }

        $ticket_id = (int) $ticket->id;

        foreach ( $actions as $action ) {
            $type  = $action['type'] ?? '';
            $value = $action['value'] ?? '';

            switch ( $type ) {
                case 'escalate':
                    try {
                        $ticket_service = new TicketService();
                        $ticket_service->change_status( $ticket_id, 'escalated' );
                    } catch ( \InvalidArgumentException $e ) {
                        // Cannot transition to escalated from current status; skip.
                    }

                    $this->log_activity( $ticket_id, null, 'escalated', [
                        'rule_id'   => $rule->id,
                        'rule_name' => $rule->name,
                    ] );
                    break;

                case 'change_priority':
                    if ( ! empty( $value ) ) {
                        $ticket_service = new TicketService();
                        $ticket_service->change_priority( $ticket_id, sanitize_text_field( $value ) );
                    }
                    break;

                case 'assign_to':
                    if ( ! empty( $value ) ) {
                        $assignment_service = new AssignmentService();
                        $assignment_service->assign( $ticket_id, absint( $value ) );
                    }
                    break;

                case 'change_department':
                    if ( ! empty( $value ) ) {
                        $ticket_service = new TicketService();
                        $ticket_service->change_department( $ticket_id, absint( $value ) );
                    }
                    break;
            }
        }

        do_action( 'escalated_rule_executed', $ticket, $rule, $actions );
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
