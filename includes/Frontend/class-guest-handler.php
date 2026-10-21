<?php

namespace Escalated\Frontend;

class Guest_Handler
{
    /**
     * The guest's private ticket link. Pass a grant from GuestAccess::issue();
     * without one the legacy permanent token is used.
     */
    public static function generate_view_url(object $ticket, ?string $token = null): string
    {
        $token ??= (string) $ticket->guest_token;
        $page_id = \Escalated\Models\Setting::get('ticket_view_page_id', '');
        if ($page_id) {
            return add_query_arg('guest_token', rawurlencode($token), get_permalink($page_id));
        }

        return add_query_arg('guest_token', rawurlencode($token), home_url('/'));
    }
}
