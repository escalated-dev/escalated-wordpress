<?php

/**
 * Email Threading - adds In-Reply-To, References, and Message-ID headers
 * to outbound WordPress emails for proper email threading support.
 */

namespace Escalated\Mail;

class Email_Threading
{
    /**
     * The current ticket context for email headers.
     */
    private static ?object $current_ticket = null;

    /**
     * The current reply context for email headers.
     */
    private static ?object $current_reply = null;

    /**
     * Register WordPress hooks for email threading.
     */
    public function register(): void
    {
        add_filter('wp_mail', [$this, 'add_threading_headers'], 10, 1);
        add_action('escalated_reply_created', [$this, 'set_reply_context'], 5, 2);
        add_action('escalated_ticket_created', [$this, 'set_ticket_context'], 5, 1);
    }

    /**
     * Set the ticket context for the next outbound email.
     *
     * @param  object  $ticket  The ticket object.
     */
    public function set_ticket_context(object $ticket): void
    {
        self::$current_ticket = $ticket;
        self::$current_reply = null;
    }

    /**
     * Set the reply context for the next outbound email.
     *
     * @param  object  $reply  The reply object.
     * @param  object  $ticket  The parent ticket object.
     */
    public function set_reply_context(object $reply, object $ticket): void
    {
        self::$current_reply = $reply;
        self::$current_ticket = $ticket;
    }

    /**
     * Add threading headers to outbound WordPress emails.
     *
     * @param  array  $args  The wp_mail arguments.
     * @return array Modified wp_mail arguments.
     */
    public function add_threading_headers(array $args): array
    {
        if (! self::$current_ticket) {
            return $args;
        }

        $ticket = self::$current_ticket;
        $reply = self::$current_reply;
        $domain = self::get_email_domain();

        // Generate Message-ID for this email.
        $message_id = self::generate_message_id($ticket, $reply, $domain);

        // Build threading headers.
        $headers = is_array($args['headers']) ? $args['headers'] : [];
        if (is_string($args['headers'])) {
            $headers = explode("\n", $args['headers']);
            $headers = array_filter(array_map('trim', $headers));
        }

        $headers[] = sprintf('Message-ID: <%s>', $message_id);

        // For replies, reference the original ticket's message ID.
        if ($reply) {
            $original_message_id = self::generate_ticket_message_id($ticket, $domain);
            $headers[] = sprintf('In-Reply-To: <%s>', $original_message_id);
            $headers[] = sprintf('References: <%s>', $original_message_id);
        }

        $args['headers'] = $headers;

        // Reset context after use.
        self::$current_ticket = null;
        self::$current_reply = null;

        return $args;
    }

    /**
     * Generate a Message-ID for a ticket.
     *
     * @param  object  $ticket  The ticket object.
     * @param  string  $domain  The email domain.
     */
    public static function generate_ticket_message_id(object $ticket, string $domain): string
    {
        return sprintf('ticket-%s@%s', $ticket->reference, $domain);
    }

    /**
     * Generate a Message-ID for a specific email.
     *
     * @param  object  $ticket  The ticket object.
     * @param  object|null  $reply  The reply object, if any.
     * @param  string  $domain  The email domain.
     */
    public static function generate_message_id(object $ticket, ?object $reply, string $domain): string
    {
        if ($reply) {
            return sprintf('reply-%d-ticket-%s@%s', $reply->id, $ticket->reference, $domain);
        }

        return self::generate_ticket_message_id($ticket, $domain);
    }

    /**
     * Get the email domain for Message-ID generation.
     */
    public static function get_email_domain(): string
    {
        $site_url = wp_parse_url(site_url(), PHP_URL_HOST);

        return $site_url ?: 'localhost';
    }

    /**
     * Get the current ticket context (for testing).
     */
    public static function get_current_ticket(): ?object
    {
        return self::$current_ticket;
    }

    /**
     * Reset the context (useful for testing).
     */
    public static function reset_context(): void
    {
        self::$current_ticket = null;
        self::$current_reply = null;
    }
}
