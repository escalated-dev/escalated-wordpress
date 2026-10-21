<?php

namespace Escalated\Services;

use Escalated\Models\Setting;
use Escalated\Models\Ticket;

/**
 * Expiring guest access grants, after escalated-laravel's GuestAccess.
 *
 * A grant is an opaque, encrypted and authenticated token (libsodium
 * secretbox, keyed from the site's auth salt). It carries the ticket id, a
 * random nonce, the expiry and a digest of the guest email. The ticket stores
 * only a SHA-256 of the nonce, so:
 *   - issuing a new grant rotates it and invalidates the earlier ones
 *   - revoke() invalidates every grant for the ticket
 *   - changing the ticket's guest email invalidates them too, however the
 *     row was changed
 *
 * Lifetime: the guest_access_ttl_minutes setting, default 1,440 (24 hours),
 * clamped to 5 minutes through 7 days.
 *
 * Legacy permanent guest_token links keep working while the
 * guest_legacy_tokens_enabled setting is on (the default during the
 * transition). Turn it off to accept grants only.
 */
class GuestAccess
{
    private const PREFIX = 'ega1.';

    public static function ttl_minutes(): int
    {
        $minutes = (int) apply_filters('escalated_guest_access_ttl_minutes', Setting::get_int('guest_access_ttl_minutes', 1440));

        return max(5, min(7 * 24 * 60, $minutes));
    }

    public static function legacy_tokens_enabled(): bool
    {
        return (bool) apply_filters('escalated_guest_legacy_tokens_enabled', Setting::get_bool('guest_legacy_tokens_enabled', true));
    }

    /**
     * Issue a grant for the ticket, rotating any earlier one.
     *
     * @return array{token: string, expires_at: string} expires_at is ISO-8601 UTC.
     */
    public static function issue(object $ticket): array
    {
        $nonce = bin2hex(random_bytes(16));
        $expires = time() + self::ttl_minutes() * MINUTE_IN_SECONDS;

        TicketAccess::without_restriction(static fn () => Ticket::update((int) $ticket->id, [
            'guest_access_hash' => hash('sha256', $nonce),
            'guest_access_expires_at' => gmdate('Y-m-d H:i:s', $expires),
        ]));

        $payload = wp_json_encode([
            't' => (int) $ticket->id,
            'n' => $nonce,
            'e' => $expires,
            'm' => GuestEmailVerification::email_digest((string) ($ticket->guest_email ?? '')),
        ]);
        $box_nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = sodium_crypto_secretbox($payload, $box_nonce, self::key());

        return [
            'token' => self::PREFIX.self::b64url_encode($box_nonce.$sealed),
            'expires_at' => gmdate('c', $expires),
        ];
    }

    public static function revoke(object $ticket): void
    {
        TicketAccess::without_restriction(static fn () => Ticket::update((int) $ticket->id, ['guest_access_hash' => null, 'guest_access_expires_at' => null]));
    }

    /**
     * The ticket an active grant opens, or null. Never accepts a legacy token.
     */
    public static function ticket_for_grant(string $token): ?object
    {
        if (strpos($token, self::PREFIX) !== 0) {
            return null;
        }
        $raw = self::b64url_decode(substr($token, strlen(self::PREFIX)));
        if ($raw === null || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        try {
            $payload = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                self::key()
            );
        } catch (\Throwable $e) {
            return null;
        }
        $data = is_string($payload) ? json_decode($payload, true) : null;
        if (! is_array($data) || ! isset($data['t'], $data['n'], $data['e'], $data['m']) || (int) $data['e'] <= time()) {
            return null;
        }

        $ticket = TicketAccess::without_restriction(static fn () => Ticket::find((int) $data['t']));
        if (! $ticket || empty($ticket->guest_access_hash) || empty($ticket->guest_email)) {
            return null;
        }
        if (! hash_equals((string) $ticket->guest_access_hash, hash('sha256', (string) $data['n']))) {
            return null;
        }
        if (! hash_equals(GuestEmailVerification::email_digest((string) $ticket->guest_email), (string) $data['m'])) {
            return null;
        }
        if (! empty($ticket->guest_access_expires_at) && strtotime($ticket->guest_access_expires_at.' UTC') <= time()) {
            return null;
        }

        return $ticket;
    }

    /**
     * The ticket a guest credential opens: an active grant, or a legacy
     * permanent guest_token while legacy tokens are accepted.
     */
    public static function ticket_for_token(string $token): ?object
    {
        if ($token === '') {
            return null;
        }
        if (strpos($token, self::PREFIX) === 0) {
            return self::ticket_for_grant($token);
        }
        if (! self::legacy_tokens_enabled() || strlen($token) < 32) {
            return null;
        }

        return Ticket::find_by_guest_token($token) ?: null;
    }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth').'|escalated-guest-access', true);
    }

    private static function b64url_encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $text): ?string
    {
        if ($text === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
