<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Setting;

/**
 * Mailbox proof for guests, after escalated-laravel's GuestEmailVerification.
 *
 * request() emails an eight-digit code and returns an opaque verification id.
 * consume() checks the id, code, email and purpose together. A code:
 *   - expires after ten minutes
 *   - allows five guesses (wrong guesses stay counted)
 *   - can be used once
 *   - is bound to the normalised email and the purpose (ticket, chat, lookup)
 *
 * Only keyed digests of the email and code are stored.
 *
 * Code budgets: three codes an hour for one mailbox from one client IP, and
 * ten an hour for one mailbox across all IPs, so one client cannot lock the
 * owner out. Tune them with the `escalated_guest_verification_limits` filter.
 */
class GuestEmailVerification
{
    public const PURPOSES = ['ticket', 'chat', 'lookup'];

    public const TTL = 10 * MINUTE_IN_SECONDS;

    public const MAX_ATTEMPTS = 5;

    public static function table(): string
    {
        return Escalated::table('guest_verifications');
    }

    public static function normalize_email(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function email_digest(string $email): string
    {
        return hash_hmac('sha256', 'email|'.self::normalize_email($email), self::key());
    }

    /**
     * @return array{per_client: int, per_mailbox: int}
     */
    public static function limits(): array
    {
        $limits = (array) apply_filters('escalated_guest_verification_limits', [
            'per_client' => Setting::get_int('guest_verification_codes_per_client_per_hour', 3),
            'per_mailbox' => Setting::get_int('guest_verification_codes_per_mailbox_per_hour', 10),
        ]);

        return [
            'per_client' => max(1, (int) ($limits['per_client'] ?? 3)),
            'per_mailbox' => max(1, (int) ($limits['per_mailbox'] ?? 10)),
        ];
    }

    /**
     * Send a code. Returns the verification id, or the seconds to wait when
     * over budget.
     *
     * @return array{id?: string, retry_after?: int}
     */
    public function request(string $email, string $purpose, ?string $ip = null): array
    {
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $digest = self::email_digest($email);
        $limits = self::limits();

        $retry = $this->spend('mailbox_'.$digest, $limits['per_mailbox'], false)
            ?? $this->spend('client_'.md5($digest.'|'.$ip), $limits['per_client'], false);
        if ($retry !== null) {
            return ['retry_after' => $retry];
        }
        $this->spend('mailbox_'.$digest, $limits['per_mailbox'], true);
        $this->spend('client_'.md5($digest.'|'.$ip), $limits['per_client'], true);

        $id = bin2hex(random_bytes(16));
        $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $now = time();

        Escalated::db()->insert(self::table(), [
            'public_id' => $id,
            'email_digest' => $digest,
            'purpose' => $purpose,
            'code_digest' => $this->code_digest($id, $code),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', $now + self::TTL),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);

        $this->send_code(self::normalize_email($email), $code);
        $this->purge_expired();

        return ['id' => $id];
    }

    /**
     * Use up a code. True only for the right id, code, email and purpose,
     * within ten minutes and five guesses, once.
     */
    public function consume(string $id, string $code, string $email, string $purpose): bool
    {
        $wpdb = Escalated::db();
        $table = self::table();

        if (! preg_match('/^[0-9a-f]{32}$/', $id)) {
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE public_id = %s", $id));
        if (! $row || $row->consumed_at !== null || (int) $row->attempts >= self::MAX_ATTEMPTS
            || strtotime($row->expires_at.' UTC') <= time()) {
            return false;
        }

        // Count the guess first, atomically, so parallel guesses cannot
        // exceed the limit.
        $counted = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d AND attempts < %d AND consumed_at IS NULL",
            (int) $row->id,
            self::MAX_ATTEMPTS
        ));
        if ((int) $counted !== 1) {
            return false;
        }

        $ok = preg_match('/^[0-9]{8}$/', $code)
            && hash_equals((string) $row->code_digest, $this->code_digest($id, $code))
            && hash_equals((string) $row->email_digest, self::email_digest($email))
            && hash_equals((string) $row->purpose, $purpose);
        if (! $ok) {
            return false;
        }

        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL",
            gmdate('Y-m-d H:i:s'),
            (int) $row->id
        ));

        return (int) $claimed === 1;
    }

    /**
     * Hand a consumed code back when the operation it authorised failed, so
     * the guest can retry. The guess stays counted.
     */
    public function release(string $id): void
    {
        $wpdb = Escalated::db();
        $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET consumed_at = NULL WHERE public_id = %s',
            $id
        ));
    }

    private function send_code(string $email, string $code): void
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(
            /* translators: %s: site name */
            __('Your %s verification code', 'escalated'),
            $site
        );
        $body = sprintf(
            /* translators: %s: eight-digit code */
            __("Your verification code is %s\n\nIt expires in 10 minutes. If you did not ask for it, ignore this email.", 'escalated'),
            $code
        );

        wp_mail($email, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    }

    /**
     * Count one request in an hourly window. Null when allowed (or when only
     * checking and under budget), otherwise the seconds until it resets.
     */
    private function spend(string $bucket, int $limit, bool $commit): ?int
    {
        $key = 'escalated_gv_'.md5($bucket);
        $now = time();
        $window = get_transient($key);
        if (! is_array($window) || (int) ($window['reset'] ?? 0) <= $now) {
            $window = ['count' => 0, 'reset' => $now + HOUR_IN_SECONDS];
        }
        $retry = max(1, (int) $window['reset'] - $now);

        if (! $commit) {
            return (int) $window['count'] >= $limit ? $retry : null;
        }

        $window['count'] = (int) $window['count'] + 1;
        set_transient($key, $window, $retry);

        return null;
    }

    private function code_digest(string $id, string $code): string
    {
        return hash_hmac('sha256', 'code|'.$id.'|'.$code, self::key());
    }

    private function purge_expired(): void
    {
        $wpdb = Escalated::db();
        $wpdb->query($wpdb->prepare(
            'DELETE FROM '.self::table().' WHERE expires_at < %s',
            gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
    }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth').'|escalated-guest-verification');
    }
}
