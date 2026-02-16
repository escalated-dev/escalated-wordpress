<?php
namespace Escalated\Admin;

use Escalated\Models\Ticket;
use Escalated\Models\Reply;
use Escalated\Models\Department;
use Escalated\Models\Tag;
use Escalated\Models\TicketActivity;
use Escalated\Models\Attachment;
use Escalated\Models\SlaPolicy;
use Escalated\Services\TicketService;
use Escalated\Helpers\Enums;

class Admin_Tickets {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    /**
     * Render the ticket list or detail view depending on URL parameters.
     */
    public function render_list(): void {
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'view' && ! empty( $_GET['ticket_id'] ) ) {
            $this->render_detail();
            return;
        }

        // Build filters from query params.
        $filters = [];

        if ( ! empty( $_GET['status'] ) ) {
            $filters['status'] = sanitize_text_field( wp_unslash( $_GET['status'] ) );
        }
        if ( ! empty( $_GET['priority'] ) ) {
            $filters['priority'] = sanitize_text_field( wp_unslash( $_GET['priority'] ) );
        }
        if ( ! empty( $_GET['department_id'] ) ) {
            $filters['department_id'] = absint( $_GET['department_id'] );
        }
        if ( ! empty( $_GET['assigned_to'] ) ) {
            $filters['assigned_to'] = absint( $_GET['assigned_to'] );
        }
        if ( ! empty( $_GET['s'] ) ) {
            $filters['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }
        if ( ! empty( $_GET['paged'] ) ) {
            $filters['page'] = absint( $_GET['paged'] );
        }

        $filters['per_page'] = 20;

        $result      = Ticket::all( $filters );
        $tickets     = $result['items'];
        $total       = $result['total'];
        $per_page    = $result['per_page'];
        $current_page = $result['current_page'];
        $total_pages = ceil( $total / $per_page );

        $statuses    = Enums::ticket_statuses();
        $priorities  = Enums::ticket_priorities();
        $departments = Department::all();

        // Get agents for filter dropdown.
        $agents = get_users( [
            'role__in' => [ 'administrator', 'escalated_admin', 'escalated_agent' ],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ] );

        $message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

        include ESCALATED_PLUGIN_DIR . 'templates/admin/tickets-list.php';
    }

    /**
     * Render the ticket detail view.
     */
    private function render_detail(): void {
        $ticket_id = absint( $_GET['ticket_id'] );
        $ticket    = Ticket::find( $ticket_id );

        if ( ! $ticket ) {
            wp_die( esc_html__( 'Ticket not found.', 'escalated' ) );
        }

        $replies      = Reply::for_ticket( $ticket_id );
        $activities   = TicketActivity::for_ticket( $ticket_id );
        $tags         = Tag::for_ticket( $ticket_id );
        $all_tags     = Tag::all();
        $statuses     = Enums::ticket_statuses();
        $priorities   = Enums::ticket_priorities();
        $departments  = Department::all();
        $attachments  = Attachment::for_attachable( 'ticket', $ticket_id );

        // Get agents for assignment.
        $agents = get_users( [
            'role__in' => [ 'administrator', 'escalated_admin', 'escalated_agent' ],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ] );

        // Get followers.
        global $wpdb;
        $followers_table = \Escalated\Escalated::table( 'ticket_followers' );
        $follower_ids    = $wpdb->get_col(
            $wpdb->prepare( "SELECT user_id FROM {$followers_table} WHERE ticket_id = %d", $ticket_id )
        );
        $followers = [];
        if ( $follower_ids ) {
            foreach ( $follower_ids as $fid ) {
                $user = get_userdata( (int) $fid );
                if ( $user ) {
                    $followers[] = $user;
                }
            }
        }

        // SLA info.
        $sla_policy = null;
        if ( $ticket->sla_policy_id ) {
            $sla_policy = SlaPolicy::find( $ticket->sla_policy_id );
        }

        $message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

        include ESCALATED_PLUGIN_DIR . 'templates/admin/ticket-detail.php';
    }

    /**
     * Handle POST actions for tickets (reply, note, status change, etc.).
     */
    public function handle_actions(): void {
        if ( ! isset( $_POST['escalated_ticket_action'] ) ) {
            return;
        }

        $action    = sanitize_text_field( wp_unslash( $_POST['escalated_ticket_action'] ) );
        $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;

        if ( ! $ticket_id ) {
            return;
        }

        // Verify nonce.
        if ( ! isset( $_POST['_escalated_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ) ), 'escalated_ticket_action_' . $ticket_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
        }

        $service   = new TicketService();
        $causer_id = get_current_user_id();
        $redirect  = admin_url( 'admin.php?page=escalated&action=view&ticket_id=' . $ticket_id );

        switch ( $action ) {
            case 'reply':
                if ( ! current_user_can( 'escalated_reply_tickets' ) ) {
                    wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
                }
                $body = isset( $_POST['reply_body'] ) ? wp_kses_post( wp_unslash( $_POST['reply_body'] ) ) : '';
                if ( ! empty( $body ) ) {
                    $service->reply( $ticket_id, $causer_id, $body );
                    $redirect = add_query_arg( 'message', 'reply_added', $redirect );
                }
                break;

            case 'note':
                if ( ! current_user_can( 'escalated_add_internal_notes' ) ) {
                    wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
                }
                $body = isset( $_POST['note_body'] ) ? wp_kses_post( wp_unslash( $_POST['note_body'] ) ) : '';
                if ( ! empty( $body ) ) {
                    $service->add_note( $ticket_id, $causer_id, $body );
                    $redirect = add_query_arg( 'message', 'note_added', $redirect );
                }
                break;

            case 'change_status':
                if ( ! current_user_can( 'escalated_reply_tickets' ) ) {
                    wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
                }
                $new_status = isset( $_POST['new_status'] ) ? sanitize_text_field( wp_unslash( $_POST['new_status'] ) ) : '';
                if ( ! empty( $new_status ) ) {
                    try {
                        $service->change_status( $ticket_id, $new_status, $causer_id );
                        $redirect = add_query_arg( 'message', 'status_changed', $redirect );
                    } catch ( \InvalidArgumentException $e ) {
                        $redirect = add_query_arg( 'message', 'error', $redirect );
                    }
                }
                break;

            case 'change_priority':
                if ( ! current_user_can( 'escalated_reply_tickets' ) ) {
                    wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
                }
                $new_priority = isset( $_POST['new_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['new_priority'] ) ) : '';
                if ( ! empty( $new_priority ) ) {
                    $service->change_priority( $ticket_id, $new_priority, $causer_id );
                    $redirect = add_query_arg( 'message', 'priority_changed', $redirect );
                }
                break;

            case 'assign':
                if ( ! current_user_can( 'escalated_assign_tickets' ) ) {
                    wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
                }
                $assigned_to = isset( $_POST['assigned_to'] ) ? absint( $_POST['assigned_to'] ) : 0;
                Ticket::update( $ticket_id, [ 'assigned_to' => $assigned_to ?: null ] );
                TicketActivity::create( [
                    'ticket_id'  => $ticket_id,
                    'causer_id'  => $causer_id,
                    'type'       => $assigned_to ? 'assigned' : 'unassigned',
                    'properties' => wp_json_encode( [ 'assigned_to' => $assigned_to ] ),
                ] );
                $redirect = add_query_arg( 'message', 'assigned', $redirect );
                break;

            case 'change_department':
                if ( ! current_user_can( 'escalated_reply_tickets' ) ) {
                    wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
                }
                $department_id = isset( $_POST['department_id'] ) ? absint( $_POST['department_id'] ) : 0;
                if ( $department_id ) {
                    $service->change_department( $ticket_id, $department_id, $causer_id );
                    $redirect = add_query_arg( 'message', 'department_changed', $redirect );
                }
                break;

            case 'update_tags':
                if ( ! current_user_can( 'escalated_reply_tickets' ) ) {
                    wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
                }
                $tag_ids = isset( $_POST['tag_ids'] ) ? array_map( 'absint', (array) $_POST['tag_ids'] ) : [];
                Tag::sync( $ticket_id, $tag_ids );
                TicketActivity::create( [
                    'ticket_id'  => $ticket_id,
                    'causer_id'  => $causer_id,
                    'type'       => 'tag_added',
                    'properties' => wp_json_encode( [ 'tag_ids' => $tag_ids ] ),
                ] );
                $redirect = add_query_arg( 'message', 'tags_updated', $redirect );
                break;
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
