<?php

namespace Escalated\Services;

use Escalated\Models\Setting;

/**
 * Per-client-IP rate limit for the unauthenticated guest endpoints.
 *
 * Every accepted guest ticket or reply writes rows and sends outbound mail, so
 * an uncapped endpoint lets anyone flood the helpdesk and the mail provider.
 * Guarded endpoints:
 *   - ticket: POST /wp-json/escalated/v1/widget/tickets and the
 *     `escalated_guest_create` AJAX action.
 *   - reply:  the `escalated_guest_reply` AJAX action. The check runs before
 *     the guest token is looked up, so wrong-token requests count too.
 *
 * Each scope has its own fixed 60-second window per client IP. Configuration
 * (Escalated settings table, overridable with the `escalated_guest_rate_limit`
 * filter):
 *   - guest_rate_limit_enabled            default 1
 *   - guest_rate_limit_tickets_per_minute default 5
 *   - guest_rate_limit_replies_per_minute default 10
 *
 * Counters are WordPress transients, so they live in the persistent object
 * cache (Redis, Memcached, ...) when the site has one, which shares them across
 * web servers; otherwise in the options table.
 *
 * The client IP is $_SERVER['REMOTE_ADDR']. Behind a reverse proxy or CDN the
 * site must rewrite REMOTE_ADDR from the trusted proxy's forwarded header (in
 * wp-config.php or the web server), or every guest shares the proxy's address
 * and one busy minute locks everyone out.
 */
class GuestRateLimiter
{
    /** Window length in seconds. */
    public const WINDOW = MINUTE_IN_SECONDS;

    private const DEFAULTS = [
        'enabled' => true,
        'tickets_per_minute' => 5,
        'replies_per_minute' => 10,
    ];

    /**
     * Effective configuration.
     *
     * @return array{enabled: bool, tickets_per_minute: int, replies_per_minute: int}
     */
    public static function config(): array
    {
        $config = [
            'enabled' => Setting::get_bool('guest_rate_limit_enabled', self::DEFAULTS['enabled']),
            'tickets_per_minute' => Setting::get_int('guest_rate_limit_tickets_per_minute', self::DEFAULTS['tickets_per_minute']),
            'replies_per_minute' => Setting::get_int('guest_rate_limit_replies_per_minute', self::DEFAULTS['replies_per_minute']),
        ];

        /**
         * Filters the guest endpoint rate limit.
         *
         * @param  array{enabled: bool, tickets_per_minute: int, replies_per_minute: int}  $config
         */
        $config = (array) apply_filters('escalated_guest_rate_limit', $config);

        return [
            'enabled' => (bool) ($config['enabled'] ?? self::DEFAULTS['enabled']),
            'tickets_per_minute' => (int) ($config['tickets_per_minute'] ?? self::DEFAULTS['tickets_per_minute']),
            'replies_per_minute' => (int) ($config['replies_per_minute'] ?? self::DEFAULTS['replies_per_minute']),
        ];
    }

    /**
     * Count one request against a scope's per-IP window.
     *
     * @param  string  $scope  'ticket' or 'reply'.
     * @param  string|null  $ip  Client IP; defaults to REMOTE_ADDR.
     * @return int|null Null when allowed, otherwise the seconds until the window resets.
     */
    public static function attempt(string $scope, ?string $ip = null): ?int
    {
        $config = self::config();
        if (! $config['enabled']) {
            return null;
        }

        $limit = $scope === 'ticket' ? $config['tickets_per_minute'] : $config['replies_per_minute'];
        $ip = $ip ?? self::client_ip();
        $key = self::key($scope, $ip);
        $now = time();

        $bucket = get_transient($key);
        if (! is_array($bucket) || (int) ($bucket['reset'] ?? 0) <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + self::WINDOW];
        }

        $retry_after = max(1, (int) $bucket['reset'] - $now);

        if ((int) $bucket['count'] >= $limit) {
            return $retry_after;
        }

        $bucket['count'] = (int) $bucket['count'] + 1;
        set_transient($key, $bucket, $retry_after);

        return null;
    }

    /**
     * Transient key for a scope and client IP.
     */
    public static function key(string $scope, string $ip): string
    {
        return 'escalated_guest_'.$scope.'_'.md5($ip);
    }

    /**
     * The client IP as the web server reports it. See the class docblock for
     * the reverse-proxy caveat.
     */
    public static function client_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return $ip !== '' ? $ip : 'unknown';
    }

    /**
     * Error payload for a rejected request.
     *
     * @return array{code: string, message: string, retry_after: int}
     */
    public static function rejection(int $retry_after): array
    {
        return [
            'code' => 'escalated_guest_rate_limited',
            'message' => __('Too many requests. Please try again later.', 'escalated'),
            'retry_after' => $retry_after,
        ];
    }
}
