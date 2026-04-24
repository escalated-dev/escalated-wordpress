<?php

/**
 * Email Threading - adds In-Reply-To, References, and Message-ID headers
 * to outbound WordPress emails for proper email threading support.
 *
 * Delegates to Message_Id_Util for header generation so the format
 * matches the canonical NestJS reference and inbound Reply-To
 * verification has something to check against.
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
     */
    public function set_ticket_context(object $ticket): void
    {
        self::$current_ticket = $ticket;
        self::$current_reply = null;
    }

    /**
     * Set the reply context for the next outbound email.
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

        $headers = is_array($args['headers']) ? $args['headers'] : [];
        if (is_string($args['headers'])) {
            $headers = explode("\n", $args['headers']);
            $headers = array_filter(array_map('trim', $headers));
        }

        $message_id = self::generate_message_id($ticket, $reply, $domain);
        $headers[] = sprintf('Message-ID: %s', $message_id);

        if ($reply) {
            // Thread the reply off the ticket root.
            $root = self::generate_ticket_message_id($ticket, $domain);
            $headers[] = sprintf('In-Reply-To: %s', $root);
            $headers[] = sprintf('References: %s', $root);
        }

        // Signed Reply-To so the inbound webhook can verify ticket
        // identity even when clients strip the Message-ID chain.
        $reply_to = self::get_signed_reply_to($ticket, $domain);
        if ($reply_to !== null) {
            $headers[] = sprintf('Reply-To: %s', $reply_to);
        }

        $args['headers'] = $headers;

        // Reset context after use.
        self::$current_ticket = null;
        self::$current_reply = null;

        return $args;
    }

    /**
     * Generate a Message-ID for a ticket (the thread anchor).
     */
    public static function generate_ticket_message_id(object $ticket, string $domain): string
    {
        return Message_Id_Util::build_message_id((int) $ticket->id, null, $domain);
    }

    /**
     * Generate a Message-ID for a specific email. Initial ticket
     * notifications use the anchor form; replies use the reply form
     * that includes the reply id.
     */
    public static function generate_message_id(object $ticket, ?object $reply, string $domain): string
    {
        if ($reply) {
            return Message_Id_Util::build_message_id((int) $ticket->id, (int) $reply->id, $domain);
        }

        return self::generate_ticket_message_id($ticket, $domain);
    }

    /**
     * Return the signed Reply-To address for a ticket, or null when
     * no inbound secret is configured.
     *
     * @return string|null Full `reply+{id}.{hmac8}@{domain}` address.
     */
    public static function get_signed_reply_to(object $ticket, ?string $domain = null): ?string
    {
        $secret = self::get_inbound_secret();
        if ($secret === '') {
            return null;
        }

        return Message_Id_Util::build_reply_to(
            (int) $ticket->id,
            $secret,
            $domain ?? self::get_email_domain()
        );
    }

    /**
     * Get the email domain for Message-ID generation. Resolution:
     *   1. escalated_email_domain option (admin-configurable)
     *   2. ESCALATED_EMAIL_DOMAIN PHP constant
     *   3. WordPress site_url() host
     *   4. 'localhost'
     */
    public static function get_email_domain(): string
    {
        $configured = get_option('escalated_email_domain', '');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (defined('ESCALATED_EMAIL_DOMAIN') && ESCALATED_EMAIL_DOMAIN !== '') {
            return (string) ESCALATED_EMAIL_DOMAIN;
        }
        $site_url = wp_parse_url(site_url(), PHP_URL_HOST);

        return $site_url ?: 'localhost';
    }

    /**
     * Return the HMAC secret used to sign Reply-To addresses. Empty
     * string means "Reply-To signing disabled".
     */
    public static function get_inbound_secret(): string
    {
        $configured = get_option('escalated_email_inbound_secret', '');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (defined('ESCALATED_EMAIL_INBOUND_SECRET') && ESCALATED_EMAIL_INBOUND_SECRET !== '') {
            return (string) ESCALATED_EMAIL_INBOUND_SECRET;
        }

        return '';
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
