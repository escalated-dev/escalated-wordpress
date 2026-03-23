<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Automation;
use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;

class AutomationRunner {

    /**
     * Valid ticket types (mirrors Laravel Ticket::TYPES).
     */
    private const TICKET_TYPES = [ 'question', 'incident', 'problem', 'task' ];

    /**
     * Evaluate all active automations against open tickets.
     *
     * @return int Total number of tickets that had actions executed.
     */
    public function run(): int {
        $automations = Automation::active();

        if ( ! $automations ) {
            return 0;
        }

        $affected = 0;

        foreach ( $automations as $automation ) {
            $tickets = $this->find_matching_tickets( $automation );

            foreach ( $tickets as $ticket ) {
                $this->execute_actions( $automation, $ticket );
                $affected++;
            }

            Automation::touch_last_run( (int) $automation->id );
        }

        return $affected;
    }

    /**
     * Find open tickets matching an automation's conditions.
     *
     * Supported condition fields:
     * - hours_since_created
     * - hours_since_updated
     * - hours_since_assigned
     * - status
     * - priority
     * - assigned (value: "assigned" or "unassigned")
     * - ticket_type
     * - subject_contains
     *
     * @param object $automation Automation row with JSON conditions field.
     * @return array Array of ticket objects.
     */
    protected function find_matching_tickets( object $automation ): array {
        global $wpdb;

        $table      = Ticket::table();
        $conditions = json_decode( $automation->conditions, true );

        if ( empty( $conditions ) || ! is_array( $conditions ) ) {
            return [];
        }

        $where  = [ 't.deleted_at IS NULL', 't.' . Ticket::scope_open() ];
        $values = [];
        $now    = current_time( 'mysql' );

        foreach ( $conditions as $condition ) {
            $field    = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '>';
            $value    = $condition['value'] ?? '';

            switch ( $field ) {
                case 'hours_since_created':
                    $threshold = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( absint( $value ) * 3600 ) );
                    $sql_op    = $this->resolve_operator( $operator );
                    $where[]   = "t.created_at {$sql_op} %s";
                    $values[]  = $threshold;
                    break;

                case 'hours_since_updated':
                    $threshold = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( absint( $value ) * 3600 ) );
                    $sql_op    = $this->resolve_operator( $operator );
                    $where[]   = "t.updated_at {$sql_op} %s";
                    $values[]  = $threshold;
                    break;

                case 'hours_since_assigned':
                    $threshold = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( absint( $value ) * 3600 ) );
                    $sql_op    = $this->resolve_operator( $operator );
                    $where[]   = 't.assigned_to IS NOT NULL';
                    $where[]   = "t.updated_at {$sql_op} %s";
                    $values[]  = $threshold;
                    break;

                case 'status':
                    $where[]  = 't.status = %s';
                    $values[] = sanitize_text_field( $value );
                    break;

                case 'priority':
                    $where[]  = 't.priority = %s';
                    $values[] = sanitize_text_field( $value );
                    break;

                case 'assigned':
                    if ( $value === 'unassigned' ) {
                        $where[] = 't.assigned_to IS NULL';
                    } elseif ( $value === 'assigned' ) {
                        $where[] = 't.assigned_to IS NOT NULL';
                    }
                    break;

                case 'ticket_type':
                    $where[]  = 't.ticket_type = %s';
                    $values[] = sanitize_text_field( $value );
                    break;

                case 'subject_contains':
                    $like     = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
                    $where[]  = 't.subject LIKE %s';
                    $values[] = $like;
                    break;
            }
        }

        $where_clause = implode( ' AND ', $where );
        $sql = "SELECT t.* FROM {$table} AS t WHERE {$where_clause}";

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return $wpdb->get_results( $sql ) ?: [];
    }

    /**
     * Execute an automation's actions on a ticket.
     *
     * Supported action types:
     * - change_status
     * - assign
     * - add_tag
     * - change_priority
     * - add_note
     * - set_ticket_type
     *
     * @param object $automation The automation row.
     * @param object $ticket     The ticket object.
     */
    protected function execute_actions( object $automation, object $ticket ): void {
        $actions = json_decode( $automation->actions, true );

        if ( empty( $actions ) || ! is_array( $actions ) ) {
            return;
        }

        $ticket_id     = (int) $ticket->id;
        $automation_id = (int) $automation->id;

        foreach ( $actions as $action ) {
            $type  = $action['type'] ?? '';
            $value = $action['value'] ?? '';

            try {
                switch ( $type ) {
                    case 'change_status':
                        if ( ! empty( $value ) ) {
                            $ticket_service = new TicketService();
                            $ticket_service->change_status( $ticket_id, sanitize_text_field( $value ) );
                        }
                        break;

                    case 'assign':
                        if ( ! empty( $value ) ) {
                            $assignment_service = new AssignmentService();
                            $assignment_service->assign( $ticket_id, absint( $value ) );
                        }
                        break;

                    case 'add_tag':
                        if ( ! empty( $value ) ) {
                            $this->add_tag_to_ticket( $ticket_id, sanitize_text_field( $value ) );
                        }
                        break;

                    case 'change_priority':
                        if ( ! empty( $value ) ) {
                            $ticket_service = new TicketService();
                            $ticket_service->change_priority( $ticket_id, sanitize_text_field( $value ) );
                        }
                        break;

                    case 'add_note':
                        if ( ! empty( $value ) ) {
                            Reply::create( [
                                'ticket_id'        => $ticket_id,
                                'author_id'        => null,
                                'body'             => sanitize_textarea_field( $value ),
                                'is_internal_note' => 1,
                                'is_pinned'        => 0,
                                'type'             => 'note',
                                'metadata'         => wp_json_encode( [
                                    'system_note'   => true,
                                    'automation_id' => $automation_id,
                                ] ),
                            ] );
                        }
                        break;

                    case 'set_ticket_type':
                        if ( ! empty( $value ) && in_array( $value, self::TICKET_TYPES, true ) ) {
                            Ticket::update( $ticket_id, [
                                'ticket_type' => sanitize_text_field( $value ),
                            ] );
                        }
                        break;
                }
            } catch ( \Throwable $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        'Escalated automation action failed: automation=%d ticket=%d action=%s error=%s',
                        $automation_id,
                        $ticket_id,
                        $type,
                        $e->getMessage()
                    ) );
                }
            }
        }

        $this->log_activity( $ticket_id, null, 'automation_executed', [
            'automation_id'   => $automation_id,
            'automation_name' => $automation->name,
        ] );

        do_action( 'escalated_automation_executed', $ticket, $automation, $actions );
    }

    /**
     * Add a tag to a ticket by tag name.
     *
     * @param int    $ticket_id Ticket ID.
     * @param string $tag_name  Tag name to look up.
     */
    protected function add_tag_to_ticket( int $ticket_id, string $tag_name ): void {
        global $wpdb;

        $tag_table   = Tag::table();
        $pivot_table = Tag::pivot_table();

        $tag = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$tag_table} WHERE name = %s", $tag_name )
        );

        if ( ! $tag ) {
            return;
        }

        // Only insert if not already tagged.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$pivot_table} WHERE ticket_id = %d AND tag_id = %d",
                $ticket_id,
                $tag->id
            )
        );

        if ( ! $exists ) {
            $wpdb->insert( $pivot_table, [
                'ticket_id' => $ticket_id,
                'tag_id'    => (int) $tag->id,
            ] );
        }
    }

    /**
     * Resolve a condition operator to a SQL comparison operator.
     *
     * For hours_since fields, > hours means < datetime (older).
     *
     * @param string $operator Condition operator.
     * @return string SQL operator.
     */
    protected function resolve_operator( string $operator ): string {
        return match ( $operator ) {
            '>'     => '<',
            '>='    => '<=',
            '<'     => '>',
            '<='    => '>=',
            '='     => '=',
            default => '<',
        };
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
