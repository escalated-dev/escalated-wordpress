<?php

namespace Escalated\Mail;

/**
 * Pure helpers for RFC 5322 Message-ID threading and signed Reply-To
 * addresses. Mirrors the NestJS reference
 * `escalated-nestjs/src/services/email/message-id.ts` and the Spring
 * `dev.escalated.services.email.MessageIdUtil`.
 *
 * Coexists with the existing `Email_Threading` class during the migration
 * window. New outbound paths should prefer this util so inbound Reply-To
 * verification has something to check against.
 *
 * ## Message-ID format
 *   <ticket-{ticketId}@{domain}>             initial ticket email
 *   <ticket-{ticketId}-reply-{replyId}@{domain}>  agent reply
 *
 * ## Signed Reply-To format
 *   reply+{ticketId}.{hmac8}@{domain}
 *
 * The signed Reply-To carries ticket identity even when clients strip our
 * Message-ID / In-Reply-To headers — the inbound provider webhook can
 * verify the HMAC prefix before routing a reply to its ticket.
 */
class Message_Id_Util
{
    /**
     * Build an RFC 5322 Message-ID. Pass `null` for `$reply_id` on the
     * initial ticket email; the `-reply-{id}` tail is appended only when
     * `$reply_id` is non-null.
     */
    public static function build_message_id(int $ticket_id, ?int $reply_id, string $domain): string
    {
        $body = $reply_id !== null
            ? sprintf('ticket-%d-reply-%d', $ticket_id, $reply_id)
            : sprintf('ticket-%d', $ticket_id);

        return sprintf('<%s@%s>', $body, $domain);
    }

    /**
     * Extract the ticket id from a Message-ID we issued. Accepts the
     * header value with or without angle brackets. Returns `null` when
     * the input doesn't match our shape.
     */
    public static function parse_ticket_id_from_message_id(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('/ticket-(\d+)(?:-reply-\d+)?@/i', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Build a signed Reply-To address.
     */
    public static function build_reply_to(int $ticket_id, string $secret, string $domain): string
    {
        return sprintf('reply+%d.%s@%s', $ticket_id, self::sign($ticket_id, $secret), $domain);
    }

    /**
     * Verify a reply-to address (full `local@domain` or just the local
     * part). Returns the ticket id on success, `null` otherwise.
     */
    public static function verify_reply_to(?string $address, string $secret): ?int
    {
        if ($address === null || $address === '') {
            return null;
        }
        $at = strpos($address, '@');
        $local = $at !== false ? substr($address, 0, $at) : $address;
        if (! preg_match('/^reply\+(\d+)\.([a-f0-9]{8})$/i', $local, $m)) {
            return null;
        }
        $ticket_id = (int) $m[1];
        $expected = self::sign($ticket_id, $secret);

        return hash_equals(strtolower($expected), strtolower($m[2])) ? $ticket_id : null;
    }

    /**
     * 8-character HMAC-SHA256 prefix over the ticket id.
     */
    private static function sign(int $ticket_id, string $secret): string
    {
        return substr(hash_hmac('sha256', (string) $ticket_id, $secret), 0, 8);
    }
}
