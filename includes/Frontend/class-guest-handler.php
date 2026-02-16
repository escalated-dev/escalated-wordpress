<?php
namespace Escalated\Frontend;

class Guest_Handler {

    public static function generate_view_url( object $ticket ): string {
        $page_id = \Escalated\Models\Setting::get( 'ticket_view_page_id', '' );
        if ( $page_id ) {
            return add_query_arg( 'guest_token', $ticket->guest_token, get_permalink( $page_id ) );
        }
        return add_query_arg( 'guest_token', $ticket->guest_token, home_url( '/' ) );
    }
}
