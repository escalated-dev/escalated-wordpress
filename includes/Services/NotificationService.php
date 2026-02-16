<?php

namespace Escalated\Services;

use Escalated\Models\Ticket;
use Escalated\Models\Setting;

class NotificationService {

    /**
     * Send a webhook notification for an event.
     *
     * POSTs a JSON payload to the configured webhook URL. The request includes an
     * HMAC-SHA256 signature in the X-Escalated-Signature header for verification.
     *
     * @param string $event   The event name (e.g., 'ticket.created', 'reply.created').
     * @param array  $payload The event data to send.
     * @return bool True if the webhook was sent successfully (2xx response), false otherwise.
     */
    public function send_webhook( string $event, array $payload ): bool {
        $webhook_url = Setting::get( 'webhook_url', '' );
        if ( empty( $webhook_url ) ) {
            return false;
        }

        $webhook_secret = Setting::get( 'webhook_secret', '' );

        $body = wp_json_encode( [
            'event'     => $event,
            'payload'   => $payload,
            'timestamp' => current_time( 'timestamp' ),
        ] );

        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Add HMAC signature if a secret is configured.
        if ( ! empty( $webhook_secret ) ) {
            $signature = hash_hmac( 'sha256', $body, $webhook_secret );
            $headers['X-Escalated-Signature'] = $signature;
        }

        $headers['X-Escalated-Event'] = $event;

        /**
         * Filter the webhook request arguments before sending.
         *
         * @param array  $args    The wp_remote_post arguments.
         * @param string $event   The event name.
         * @param array  $payload The event payload.
         */
        $args = apply_filters( 'escalated_webhook_args', [
            'body'      => $body,
            'headers'   => $headers,
            'timeout'   => 15,
            'sslverify' => true,
        ], $event, $payload );

        $response = wp_remote_post( $webhook_url, $args );

        if ( is_wp_error( $response ) ) {
            do_action( 'escalated_webhook_failed', $event, $payload, $response->get_error_message() );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );

        if ( $response_code >= 200 && $response_code < 300 ) {
            do_action( 'escalated_webhook_sent', $event, $payload, $response_code );
            return true;
        }

        do_action( 'escalated_webhook_failed', $event, $payload, sprintf( 'HTTP %d', $response_code ) );

        return false;
    }

    /**
     * Send notification emails when a ticket is created.
     *
     * Sends notifications to:
     * - Site admin (or configured admin email).
     * - Assigned agent (if the ticket is already assigned).
     * - Department agents (if the ticket belongs to a department).
     *
     * @param object $ticket The newly created ticket object.
     */
    public function notify_ticket_created( object $ticket ): void {
        $site_name = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );

        $subject = sprintf(
            /* translators: 1: ticket reference, 2: ticket subject */
            __( '[%1$s] New Ticket: %2$s', 'escalated' ),
            esc_html( $ticket->reference ),
            esc_html( $ticket->subject )
        );

        // Build the email body.
        $requester_name = $this->get_requester_name( $ticket );

        $body = sprintf(
            /* translators: 1: site name */
            __( 'A new support ticket has been submitted on %s.', 'escalated' ),
            esc_html( $site_name )
        ) . "\n\n";

        $body .= sprintf(
            __( 'Reference: %s', 'escalated' ),
            esc_html( $ticket->reference )
        ) . "\n";

        $body .= sprintf(
            __( 'Subject: %s', 'escalated' ),
            esc_html( $ticket->subject )
        ) . "\n";

        $body .= sprintf(
            __( 'Priority: %s', 'escalated' ),
            esc_html( ucfirst( $ticket->priority ) )
        ) . "\n";

        $body .= sprintf(
            __( 'From: %s', 'escalated' ),
            esc_html( $requester_name )
        ) . "\n\n";

        $body .= __( 'Description:', 'escalated' ) . "\n";
        $body .= wp_strip_all_tags( $ticket->description ) . "\n\n";

        // Admin ticket URL.
        $admin_url = admin_url( 'admin.php?page=escalated-tickets&action=view&id=' . $ticket->id );
        $body .= sprintf(
            __( 'View ticket: %s', 'escalated' ),
            $admin_url
        ) . "\n";

        /**
         * Filter the ticket created notification email body.
         *
         * @param string $body   The email body.
         * @param object $ticket The ticket object.
         */
        $body = apply_filters( 'escalated_notify_ticket_created_body', $body, $ticket );

        /**
         * Filter the ticket created notification email subject.
         *
         * @param string $subject The email subject.
         * @param object $ticket  The ticket object.
         */
        $subject = apply_filters( 'escalated_notify_ticket_created_subject', $subject, $ticket );

        // Collect recipient emails.
        $recipients = [ $admin_email ];

        // Add assigned agent email.
        if ( ! empty( $ticket->assigned_to ) ) {
            $agent = get_userdata( (int) $ticket->assigned_to );
            if ( $agent && ! empty( $agent->user_email ) ) {
                $recipients[] = $agent->user_email;
            }
        }

        // Add department agents if applicable.
        if ( ! empty( $ticket->department_id ) ) {
            $agent_ids = \Escalated\Models\Department::agents( (int) $ticket->department_id );
            foreach ( $agent_ids as $agent_id ) {
                $agent = get_userdata( (int) $agent_id );
                if ( $agent && ! empty( $agent->user_email ) ) {
                    $recipients[] = $agent->user_email;
                }
            }
        }

        // Remove duplicates.
        $recipients = array_unique( $recipients );

        /**
         * Filter the ticket created notification recipients.
         *
         * @param array  $recipients Array of email addresses.
         * @param object $ticket     The ticket object.
         */
        $recipients = apply_filters( 'escalated_notify_ticket_created_recipients', $recipients, $ticket );

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf( 'From: %s <%s>', $site_name, $admin_email ),
        ];

        foreach ( $recipients as $recipient_email ) {
            if ( is_email( $recipient_email ) ) {
                wp_mail( $recipient_email, $subject, $body, $headers );
            }
        }

        do_action( 'escalated_notification_ticket_created_sent', $ticket, $recipients );
    }

    /**
     * Send notification emails when a reply is created on a ticket.
     *
     * Sends notifications to relevant users: the requester, the assigned agent,
     * and any ticket followers. Internal notes do not trigger notifications
     * to the requester.
     *
     * @param object $reply  The newly created reply object.
     * @param object $ticket The ticket the reply belongs to.
     */
    public function notify_reply_created( object $reply, object $ticket ): void {
        // Do not send external notifications for internal notes.
        $is_internal = ! empty( $reply->is_internal_note );

        $site_name   = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );

        $subject = sprintf(
            /* translators: 1: ticket reference, 2: ticket subject */
            __( 'Re: [%1$s] %2$s', 'escalated' ),
            esc_html( $ticket->reference ),
            esc_html( $ticket->subject )
        );

        // Get the author name.
        $author_name = __( 'System', 'escalated' );
        if ( ! empty( $reply->author_id ) ) {
            $author = get_userdata( (int) $reply->author_id );
            if ( $author ) {
                $author_name = $author->display_name;
            }
        }

        $body = sprintf(
            /* translators: 1: author name, 2: ticket reference */
            __( '%1$s replied to ticket %2$s:', 'escalated' ),
            esc_html( $author_name ),
            esc_html( $ticket->reference )
        ) . "\n\n";

        if ( $is_internal ) {
            $body .= __( '[Internal Note]', 'escalated' ) . "\n\n";
        }

        $body .= wp_strip_all_tags( $reply->body ) . "\n\n";

        // Admin ticket URL.
        $admin_url = admin_url( 'admin.php?page=escalated-tickets&action=view&id=' . $ticket->id );
        $body .= sprintf(
            __( 'View ticket: %s', 'escalated' ),
            $admin_url
        ) . "\n";

        /**
         * Filter the reply created notification email body.
         *
         * @param string $body   The email body.
         * @param object $reply  The reply object.
         * @param object $ticket The ticket object.
         */
        $body = apply_filters( 'escalated_notify_reply_created_body', $body, $reply, $ticket );

        /**
         * Filter the reply created notification email subject.
         *
         * @param string $subject The email subject.
         * @param object $reply   The reply object.
         * @param object $ticket  The ticket object.
         */
        $subject = apply_filters( 'escalated_notify_reply_created_subject', $subject, $reply, $ticket );

        $recipients = [];

        if ( $is_internal ) {
            // Internal notes: notify only agents and admin, not the requester.
            if ( ! empty( $ticket->assigned_to ) && (int) $ticket->assigned_to !== (int) $reply->author_id ) {
                $agent = get_userdata( (int) $ticket->assigned_to );
                if ( $agent && ! empty( $agent->user_email ) ) {
                    $recipients[] = $agent->user_email;
                }
            }

            // Notify admin for internal notes.
            $recipients[] = $admin_email;
        } else {
            // Public replies: notify the requester (if not the author).
            if ( ! empty( $ticket->requester_id ) && (int) $ticket->requester_id !== (int) $reply->author_id ) {
                $requester = get_userdata( (int) $ticket->requester_id );
                if ( $requester && ! empty( $requester->user_email ) ) {
                    $recipients[] = $requester->user_email;
                }
            } elseif ( empty( $ticket->requester_id ) && ! empty( $ticket->guest_email ) ) {
                // Guest ticket: notify the guest email.
                $recipients[] = $ticket->guest_email;
            }

            // Notify the assigned agent (if they are not the author).
            if ( ! empty( $ticket->assigned_to ) && (int) $ticket->assigned_to !== (int) $reply->author_id ) {
                $agent = get_userdata( (int) $ticket->assigned_to );
                if ( $agent && ! empty( $agent->user_email ) ) {
                    $recipients[] = $agent->user_email;
                }
            }
        }

        // Add ticket followers.
        $followers = $this->get_ticket_followers( (int) $ticket->id );
        foreach ( $followers as $follower_id ) {
            // Skip the reply author.
            if ( (int) $follower_id === (int) $reply->author_id ) {
                continue;
            }
            // For internal notes, skip non-agents (the requester).
            if ( $is_internal && (int) $follower_id === (int) $ticket->requester_id ) {
                continue;
            }
            $follower = get_userdata( (int) $follower_id );
            if ( $follower && ! empty( $follower->user_email ) ) {
                $recipients[] = $follower->user_email;
            }
        }

        // Remove duplicates.
        $recipients = array_unique( $recipients );

        /**
         * Filter the reply created notification recipients.
         *
         * @param array  $recipients Array of email addresses.
         * @param object $reply      The reply object.
         * @param object $ticket     The ticket object.
         */
        $recipients = apply_filters( 'escalated_notify_reply_created_recipients', $recipients, $reply, $ticket );

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf( 'From: %s <%s>', $site_name, $admin_email ),
            sprintf( 'References: <%s@%s>', $ticket->reference, wp_parse_url( home_url(), PHP_URL_HOST ) ),
        ];

        foreach ( $recipients as $recipient_email ) {
            if ( is_email( $recipient_email ) ) {
                wp_mail( $recipient_email, $subject, $body, $headers );
            }
        }

        do_action( 'escalated_notification_reply_created_sent', $reply, $ticket, $recipients );
    }

    /**
     * Get the display name of a ticket's requester.
     *
     * @param object $ticket The ticket object.
     * @return string The requester's display name, or guest name/email.
     */
    protected function get_requester_name( object $ticket ): string {
        if ( ! empty( $ticket->requester_id ) ) {
            $user = get_userdata( (int) $ticket->requester_id );
            if ( $user ) {
                return $user->display_name;
            }
        }

        if ( ! empty( $ticket->guest_name ) ) {
            return $ticket->guest_name;
        }

        if ( ! empty( $ticket->guest_email ) ) {
            return $ticket->guest_email;
        }

        return __( 'Unknown', 'escalated' );
    }

    /**
     * Get all follower user IDs for a ticket.
     *
     * @param int $ticket_id Ticket ID.
     * @return array Array of WordPress user IDs.
     */
    protected function get_ticket_followers( int $ticket_id ): array {
        global $wpdb;

        $table = \Escalated\Escalated::table( 'ticket_followers' );

        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE ticket_id = %d",
                $ticket_id
            )
        );

        return $results ?: [];
    }
}
