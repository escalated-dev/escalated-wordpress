<?php
namespace Escalated\Frontend;

class Ajax_Handler {

    public function register(): void {
        // Logged-in user actions
        add_action( 'wp_ajax_escalated_create_ticket', [ $this, 'create_ticket' ] );
        add_action( 'wp_ajax_escalated_reply_ticket', [ $this, 'reply_ticket' ] );
        add_action( 'wp_ajax_escalated_close_ticket', [ $this, 'close_ticket' ] );
        add_action( 'wp_ajax_escalated_rate_ticket', [ $this, 'rate_ticket' ] );

        // Guest actions (nopriv)
        add_action( 'wp_ajax_nopriv_escalated_guest_create', [ $this, 'guest_create' ] );
        add_action( 'wp_ajax_nopriv_escalated_guest_reply', [ $this, 'guest_reply' ] );
        add_action( 'wp_ajax_escalated_guest_create', [ $this, 'guest_create' ] );
        add_action( 'wp_ajax_escalated_guest_reply', [ $this, 'guest_reply' ] );
    }

    public function create_ticket(): void {
        check_ajax_referer( 'escalated_frontend', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'escalated' ) ], 401 );
        }

        $subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
        $description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
        $department_id = absint( $_POST['department_id'] ?? 0 );
        $priority = sanitize_text_field( $_POST['priority'] ?? 'medium' );

        if ( empty( $subject ) || empty( $description ) ) {
            wp_send_json_error( [ 'message' => __( 'Subject and description are required.', 'escalated' ) ], 422 );
        }

        $service = new \Escalated\Services\TicketService();
        $ticket = $service->create( get_current_user_id(), [
            'subject'       => $subject,
            'description'   => $description,
            'department_id' => $department_id ?: null,
            'priority'      => $priority,
        ] );

        // Handle attachments
        if ( ! empty( $_FILES['attachments'] ) ) {
            $attachment_service = new \Escalated\Services\AttachmentService();
            $attachment_service->store_many( 'ticket', $ticket->id, $_FILES['attachments'] );
        }

        // Attach default SLA policy
        $sla_service = new \Escalated\Services\SlaService();
        $sla_service->attach_default_policy( $ticket->id );

        wp_send_json_success( [
            'message'   => __( 'Ticket created successfully.', 'escalated' ),
            'reference' => $ticket->reference,
        ] );
    }

    public function reply_ticket(): void {
        check_ajax_referer( 'escalated_frontend', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'escalated' ) ], 401 );
        }

        $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
        $body = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

        if ( ! $ticket_id || empty( $body ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'escalated' ) ], 422 );
        }

        $ticket = \Escalated\Models\Ticket::find( $ticket_id );
        if ( ! $ticket || (int) $ticket->requester_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Ticket not found.', 'escalated' ) ], 404 );
        }

        $service = new \Escalated\Services\TicketService();
        $attachments = ! empty( $_FILES['attachments'] ) ? $_FILES['attachments'] : [];
        $reply = $service->reply( $ticket_id, get_current_user_id(), $body, $attachments );

        wp_send_json_success( [ 'message' => __( 'Reply sent.', 'escalated' ) ] );
    }

    public function close_ticket(): void {
        check_ajax_referer( 'escalated_frontend', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'escalated' ) ], 401 );
        }

        $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
        $ticket = \Escalated\Models\Ticket::find( $ticket_id );

        if ( ! $ticket || (int) $ticket->requester_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Ticket not found.', 'escalated' ) ], 404 );
        }

        $service = new \Escalated\Services\TicketService();
        try {
            $service->close( $ticket_id, get_current_user_id() );
            wp_send_json_success( [ 'message' => __( 'Ticket closed.', 'escalated' ) ] );
        } catch ( \InvalidArgumentException $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 422 );
        }
    }

    public function rate_ticket(): void {
        check_ajax_referer( 'escalated_frontend', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'escalated' ) ], 401 );
        }

        $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
        $rating = absint( $_POST['rating'] ?? 0 );
        $comment = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );

        if ( ! $ticket_id || $rating < 1 || $rating > 5 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid rating.', 'escalated' ) ], 422 );
        }

        $ticket = \Escalated\Models\Ticket::find( $ticket_id );
        if ( ! $ticket || (int) $ticket->requester_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Ticket not found.', 'escalated' ) ], 404 );
        }

        \Escalated\Models\SatisfactionRating::create( [
            'ticket_id'  => $ticket_id,
            'rating'     => $rating,
            'comment'    => $comment,
            'rated_by'   => get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
        ] );

        wp_send_json_success( [ 'message' => __( 'Thank you for your feedback!', 'escalated' ) ] );
    }

    public function guest_create(): void {
        check_ajax_referer( 'escalated_frontend', 'nonce' );

        if ( ! \Escalated\Models\Setting::get_bool( 'guest_tickets_enabled', true ) ) {
            wp_send_json_error( [ 'message' => __( 'Guest tickets are disabled.', 'escalated' ) ], 403 );
        }

        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
        $description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );

        if ( empty( $name ) || empty( $email ) || empty( $subject ) || empty( $description ) ) {
            wp_send_json_error( [ 'message' => __( 'All fields are required.', 'escalated' ) ], 422 );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid email address.', 'escalated' ) ], 422 );
        }

        $service = new \Escalated\Services\TicketService();
        $ticket = $service->create_guest( [
            'subject'     => $subject,
            'description' => $description,
            'guest_name'  => $name,
            'guest_email' => $email,
        ] );

        // Attach default SLA policy
        $sla_service = new \Escalated\Services\SlaService();
        $sla_service->attach_default_policy( $ticket->id );

        $view_url = Guest_Handler::generate_view_url( $ticket );

        wp_send_json_success( [
            'message'     => __( 'Ticket created successfully.', 'escalated' ),
            'reference'   => $ticket->reference,
            'guest_token' => $ticket->guest_token,
            'view_url'    => $view_url,
        ] );
    }

    public function guest_reply(): void {
        check_ajax_referer( 'escalated_frontend', 'nonce' );

        $guest_token = sanitize_text_field( $_POST['guest_token'] ?? '' );
        $body = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

        if ( empty( $guest_token ) || empty( $body ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'escalated' ) ], 422 );
        }

        $ticket = \Escalated\Models\Ticket::find_by_guest_token( $guest_token );
        if ( ! $ticket ) {
            wp_send_json_error( [ 'message' => __( 'Ticket not found.', 'escalated' ) ], 404 );
        }

        $now = current_time( 'mysql' );
        \Escalated\Models\Reply::create( [
            'ticket_id'      => $ticket->id,
            'author_id'      => null,
            'body'           => $body,
            'is_internal_note' => 0,
            'type'           => 'reply',
            'created_at'     => $now,
            'updated_at'     => $now,
        ] );

        wp_send_json_success( [ 'message' => __( 'Reply sent.', 'escalated' ) ] );
    }
}
