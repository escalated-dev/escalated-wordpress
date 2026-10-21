<?php

namespace Escalated\Api;

use Escalated\Escalated;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\GuestAccess;
use Escalated\Services\GuestEmailVerification;
use Escalated\Services\TicketAccess;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The verified guest access endpoints shared by the widget (/widget) and the
 * guest pages (/guest): POST verification and POST lookup, plus the proof
 * check the ticket and chat creators use. See docs/guest-access.md.
 */
final class GuestVerificationEndpoints
{
    public const LOOKUP_LIMIT = 20;

    /**
     * POST {prefix}/verification: {email, purpose}.
     */
    public static function request_code(WP_REST_Request $request): WP_REST_Response
    {
        $email = trim((string) $request->get_param('email'));
        $purpose = (string) $request->get_param('purpose');

        $errors = [];
        if (! is_email($email)) {
            $errors['email'] = [__('Enter a valid email address.', 'escalated')];
        }
        if (! in_array($purpose, GuestEmailVerification::PURPOSES, true)) {
            $errors['purpose'] = [__('The purpose must be ticket, chat or lookup.', 'escalated')];
        }
        if ($errors !== []) {
            return self::invalid($errors);
        }

        if (! Setting::get_bool('guest_tickets_enabled', true)) {
            return self::no_store(new WP_REST_Response([
                'code' => 'escalated_guest_tickets_disabled',
                'message' => __('Guest tickets are disabled.', 'escalated'),
            ], 403));
        }

        $result = (new GuestEmailVerification)->request($email, $purpose);
        if (isset($result['retry_after'])) {
            $response = new WP_REST_Response([
                'code' => 'escalated_rate_limited',
                'message' => __('Too many codes requested for this email. Please try again later.', 'escalated'),
            ], 429);
            $response->header('Retry-After', (string) $result['retry_after']);

            return self::no_store($response);
        }

        return self::no_store(new WP_REST_Response([
            'verification_id' => $result['id'],
            'expires_in' => GuestEmailVerification::TTL,
            'message' => __('If the address can receive email, a verification code is on its way.', 'escalated'),
        ], 202));
    }

    /**
     * POST {prefix}/lookup: {email, reference, verification_id,
     * verification_code}. Returns fresh grants for the guest tickets with
     * that reference and guest email.
     */
    public static function lookup(WP_REST_Request $request, ?callable $extra = null): WP_REST_Response
    {
        $email = trim((string) $request->get_param('email'));
        $reference = strtoupper(trim(sanitize_text_field((string) $request->get_param('reference'))));

        $errors = [];
        if (! is_email($email)) {
            $errors['email'] = [__('Enter a valid email address.', 'escalated')];
        }
        if ($reference === '' || strlen($reference) > 64) {
            $errors['reference'] = [__('Enter your ticket reference.', 'escalated')];
        }
        if ($errors !== []) {
            return self::invalid($errors);
        }

        $proof = self::consume_proof($request, $email, 'lookup');
        if ($proof instanceof WP_REST_Response) {
            return $proof;
        }

        $wpdb = Escalated::db();
        $table = Ticket::table();
        $tickets = TicketAccess::without_restriction(static fn () => $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE reference = %s AND guest_email IS NOT NULL AND guest_email <> '' AND deleted_at IS NULL
             ORDER BY id DESC LIMIT %d",
            $reference,
            self::LOOKUP_LIMIT
        )) ?: []);

        $matches = [];
        foreach ($tickets as $ticket) {
            if (GuestEmailVerification::normalize_email((string) $ticket->guest_email) !== GuestEmailVerification::normalize_email($email)) {
                continue;
            }
            $grant = GuestAccess::issue($ticket);
            $match = [
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'guest_access_token' => $grant['token'],
                'expires_at' => $grant['expires_at'],
            ];
            $matches[] = $extra ? $extra($match, $ticket, $grant) : $match;
        }

        return self::no_store(new WP_REST_Response(['data' => $matches], 200));
    }

    /**
     * Check and use up the proof sent with a request. True, or the 422 to
     * answer with.
     *
     * @return true|WP_REST_Response
     */
    public static function consume_proof(WP_REST_Request $request, string $email, string $purpose)
    {
        $id = (string) $request->get_param('verification_id');
        $code = preg_replace('/\s+/', '', (string) $request->get_param('verification_code'));

        if ($id === '' || $code === '') {
            return self::invalid(['verification_code' => [__('Verify your email address first: request a code and enter it.', 'escalated')]]);
        }

        if (! (new GuestEmailVerification)->consume($id, (string) $code, $email, $purpose)) {
            return self::invalid(['verification_code' => [__('The verification code is wrong or has expired. Request a new one.', 'escalated')]]);
        }

        return true;
    }

    public static function invalid(array $errors): WP_REST_Response
    {
        $first = reset($errors);

        return self::no_store(new WP_REST_Response([
            'code' => 'escalated_validation_failed',
            'message' => is_array($first) ? (string) reset($first) : (string) $first,
            'errors' => $errors,
        ], 422));
    }

    public static function no_store(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store');
        $response->header('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * The guest credential sent as `Authorization: Bearer <grant>`.
     */
    public static function bearer(WP_REST_Request $request): string
    {
        $header = (string) $request->get_header('authorization');

        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
    }
}
