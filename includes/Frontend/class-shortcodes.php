<?php
namespace Escalated\Frontend;

class Shortcodes {

    public function register(): void {
        add_shortcode( 'escalated_tickets', [ $this, 'render_ticket_list' ] );
        add_shortcode( 'escalated_create_ticket', [ $this, 'render_create_ticket' ] );
        add_shortcode( 'escalated_view_ticket', [ $this, 'render_view_ticket' ] );
        add_shortcode( 'escalated_guest_create', [ $this, 'render_guest_create' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }
        $shortcodes = [ 'escalated_tickets', 'escalated_create_ticket', 'escalated_view_ticket', 'escalated_guest_create' ];
        $has_shortcode = false;
        foreach ( $shortcodes as $sc ) {
            if ( has_shortcode( $post->post_content, $sc ) ) {
                $has_shortcode = true;
                break;
            }
        }
        if ( ! $has_shortcode ) {
            return;
        }
        wp_enqueue_style( 'escalated-frontend', ESCALATED_PLUGIN_URL . 'assets/css/frontend.css', [], ESCALATED_VERSION );
        wp_enqueue_script( 'escalated-frontend', ESCALATED_PLUGIN_URL . 'assets/js/frontend.js', [ 'jquery' ], ESCALATED_VERSION, true );
        wp_localize_script( 'escalated-frontend', 'escalatedFrontend', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'escalated_frontend' ),
        ] );
    }

    public function render_ticket_list( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<p class="escalated-notice">' . esc_html__( 'Please log in to view your tickets.', 'escalated' ) . '</p>';
        }
        $user_id = get_current_user_id();
        $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $page = isset( $_GET['tpage'] ) ? max( 1, absint( $_GET['tpage'] ) ) : 1;

        $filters = [ 'requester_id' => $user_id, 'per_page' => 10, 'page' => $page ];
        if ( $status ) {
            $filters['status'] = $status;
        }
        $result = \Escalated\Models\Ticket::all( $filters );

        ob_start();
        $tickets = $result['items'];
        $total = $result['total'];
        $per_page = $result['per_page'];
        $current_page = $result['current_page'];
        $template = apply_filters( 'escalated_template_path', ESCALATED_PLUGIN_DIR . 'templates/frontend/ticket-list.php', 'ticket-list' );
        include $template;
        return ob_get_clean() . $this->powered_by_footer();
    }

    public function render_create_ticket( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<p class="escalated-notice">' . esc_html__( 'Please log in to create a ticket.', 'escalated' ) . '</p>';
        }
        $departments = \Escalated\Models\Department::active();
        ob_start();
        $template = apply_filters( 'escalated_template_path', ESCALATED_PLUGIN_DIR . 'templates/frontend/ticket-create.php', 'ticket-create' );
        include $template;
        return ob_get_clean() . $this->powered_by_footer();
    }

    public function render_view_ticket( $atts ): string {
        // Check for guest token first
        $guest_token = isset( $_GET['guest_token'] ) ? sanitize_text_field( wp_unslash( $_GET['guest_token'] ) ) : '';
        if ( $guest_token ) {
            $ticket = \Escalated\Models\Ticket::find_by_guest_token( $guest_token );
            if ( ! $ticket ) {
                return '<p class="escalated-notice">' . esc_html__( 'Ticket not found.', 'escalated' ) . '</p>';
            }
            $replies = \Escalated\Models\Reply::for_ticket( $ticket->id, false );
            $is_guest = true;
            ob_start();
            $template = apply_filters( 'escalated_template_path', ESCALATED_PLUGIN_DIR . 'templates/frontend/guest-view.php', 'guest-view' );
            include $template;
            return ob_get_clean() . $this->powered_by_footer();
        }

        // Logged-in user
        if ( ! is_user_logged_in() ) {
            return '<p class="escalated-notice">' . esc_html__( 'Please log in to view tickets.', 'escalated' ) . '</p>';
        }

        $ref = isset( $_GET['ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['ticket'] ) ) : '';
        if ( ! $ref ) {
            return '<p class="escalated-notice">' . esc_html__( 'No ticket specified.', 'escalated' ) . '</p>';
        }

        $ticket = \Escalated\Models\Ticket::find_by_reference( $ref );
        if ( ! $ticket || (int) $ticket->requester_id !== get_current_user_id() ) {
            return '<p class="escalated-notice">' . esc_html__( 'Ticket not found.', 'escalated' ) . '</p>';
        }

        $replies = \Escalated\Models\Reply::for_ticket( $ticket->id, false );
        $tags = \Escalated\Models\Tag::for_ticket( $ticket->id );
        $rating = \Escalated\Models\SatisfactionRating::for_ticket( $ticket->id );
        $is_guest = false;

        ob_start();
        $template = apply_filters( 'escalated_template_path', ESCALATED_PLUGIN_DIR . 'templates/frontend/ticket-view.php', 'ticket-view' );
        include $template;
        return ob_get_clean() . $this->powered_by_footer();
    }

    public function render_guest_create( $atts ): string {
        if ( ! \Escalated\Models\Setting::get_bool( 'guest_tickets_enabled', true ) ) {
            return '<p class="escalated-notice">' . esc_html__( 'Guest tickets are not available.', 'escalated' ) . '</p>';
        }
        $departments = \Escalated\Models\Department::active();
        ob_start();
        $template = apply_filters( 'escalated_template_path', ESCALATED_PLUGIN_DIR . 'templates/frontend/guest-create.php', 'guest-create' );
        include $template;
        return ob_get_clean() . $this->powered_by_footer();
    }

    /**
     * Render "Powered by Escalated" footer if enabled.
     */
    protected function powered_by_footer(): string {
        if ( ! \Escalated\Models\Setting::get_bool( 'show_powered_by', true ) ) {
            return '';
        }

        return '<div class="escalated-powered-by">'
            . '<a href="https://escalated.dev" target="_blank" rel="noopener noreferrer">'
            . '<img src="https://escalated.dev/brand/logo-icon-white.svg" alt="" width="14" height="14" style="vertical-align: middle; margin-right: 4px;" />'
            . esc_html__( 'Powered by Escalated', 'escalated' )
            . '</a>'
            . '</div>';
    }
}
