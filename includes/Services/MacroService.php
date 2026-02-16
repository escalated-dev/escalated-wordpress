<?php

namespace Escalated\Services;

use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;

class MacroService {

    /**
     * Apply a macro's actions to a ticket.
     *
     * Iterates through the macro's actions array and executes each one sequentially.
     * Supported action types:
     * - change_status: Transition the ticket to a new status.
     * - change_priority: Change the ticket's priority.
     * - assign_to: Assign the ticket to a specific agent.
     * - add_tags: Add one or more tags to the ticket.
     * - remove_tags: Remove one or more tags from the ticket.
     * - change_department: Move the ticket to a different department.
     * - reply: Add a public reply to the ticket.
     * - note: Add an internal note to the ticket.
     *
     * @param object   $macro     Macro object with an actions JSON field.
     * @param int      $ticket_id Ticket ID to apply the macro to.
     * @param int|null $causer_id WordPress user ID of the user applying the macro.
     * @return object The ticket after all actions have been applied.
     */
    public function apply( object $macro, int $ticket_id, ?int $causer_id = null ): object {
        $actions = json_decode( $macro->actions, true );

        if ( empty( $actions ) || ! is_array( $actions ) ) {
            return Ticket::find( $ticket_id );
        }

        $ticket_service     = new TicketService();
        $assignment_service = new AssignmentService();

        foreach ( $actions as $action ) {
            $type  = $action['type'] ?? '';
            $value = $action['value'] ?? '';

            switch ( $type ) {
                case 'change_status':
                    if ( ! empty( $value ) ) {
                        try {
                            $ticket_service->change_status( $ticket_id, sanitize_text_field( $value ), $causer_id );
                        } catch ( \InvalidArgumentException $e ) {
                            // Cannot transition to the target status; skip this action.
                        }
                    }
                    break;

                case 'change_priority':
                    if ( ! empty( $value ) ) {
                        $ticket_service->change_priority( $ticket_id, sanitize_text_field( $value ), $causer_id );
                    }
                    break;

                case 'assign_to':
                    if ( ! empty( $value ) ) {
                        $assignment_service->assign( $ticket_id, absint( $value ), $causer_id );
                    }
                    break;

                case 'add_tags':
                    if ( ! empty( $value ) ) {
                        $tag_ids = is_array( $value ) ? $value : [ $value ];
                        $tag_ids = array_map( 'absint', $tag_ids );
                        $ticket_service->add_tags( $ticket_id, $tag_ids, $causer_id );
                    }
                    break;

                case 'remove_tags':
                    if ( ! empty( $value ) ) {
                        $tag_ids = is_array( $value ) ? $value : [ $value ];
                        $tag_ids = array_map( 'absint', $tag_ids );
                        $ticket_service->remove_tags( $ticket_id, $tag_ids, $causer_id );
                    }
                    break;

                case 'change_department':
                    if ( ! empty( $value ) ) {
                        $ticket_service->change_department( $ticket_id, absint( $value ), $causer_id );
                    }
                    break;

                case 'reply':
                    if ( ! empty( $value ) && $causer_id ) {
                        $body = is_string( $value ) ? $value : ( $value['body'] ?? '' );
                        if ( ! empty( $body ) ) {
                            $ticket_service->reply( $ticket_id, $causer_id, $body );
                        }
                    }
                    break;

                case 'note':
                    if ( ! empty( $value ) && $causer_id ) {
                        $body = is_string( $value ) ? $value : ( $value['body'] ?? '' );
                        if ( ! empty( $body ) ) {
                            $ticket_service->add_note( $ticket_id, $causer_id, $body );
                        }
                    }
                    break;
            }
        }

        $this->log_activity( $ticket_id, $causer_id, [
            'macro_id'   => $macro->id,
            'macro_name' => $macro->name,
        ] );

        do_action( 'escalated_macro_applied', $macro, $ticket_id, $causer_id );

        return Ticket::find( $ticket_id );
    }

    /**
     * Log a macro application activity.
     *
     * @param int      $ticket_id  Ticket ID.
     * @param int|null $causer_id  User who applied the macro.
     * @param array    $properties Additional properties (macro_id, macro_name).
     */
    protected function log_activity( int $ticket_id, ?int $causer_id, array $properties = [] ): void {
        TicketActivity::create( [
            'ticket_id'  => $ticket_id,
            'causer_id'  => $causer_id,
            'type'       => 'macro_applied',
            'properties' => ! empty( $properties ) ? wp_json_encode( $properties ) : null,
            'created_at' => current_time( 'mysql' ),
        ] );
    }
}
