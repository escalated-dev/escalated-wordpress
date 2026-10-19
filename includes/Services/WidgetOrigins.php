<?php

namespace Escalated\Services;

use Escalated\Models\Setting;
use WP_REST_Request;

/**
 * Decides which other sites may embed the widget.
 *
 * The allow list is the union of:
 *   - the ESCALATED_WIDGET_ALLOWED_ORIGINS constant (comma separated string or
 *     array, e.g. in wp-config.php), passed through the
 *     `escalated_widget_allowed_origins` filter;
 *   - the `widget_allowed_origins` setting (one origin per line or comma
 *     separated), edited under Escalated > Settings > Widget.
 *
 * Entries are origins: scheme://host[:port], http or https only, no path,
 * query or user name. `https://*.example.com` matches any subdomain of
 * example.com at any depth, but not example.com itself and not http://.
 * A bare `*` is never accepted. Invalid entries are ignored.
 */
class WidgetOrigins
{
    /**
     * The request's Origin when it names another site, or null for
     * same-origin requests and requests without an Origin header.
     */
    public static function cross_origin(WP_REST_Request $request): ?string
    {
        $origin = $request->get_header('origin');
        if (! is_string($origin) || $origin === '' || $origin === 'null') {
            return null;
        }

        $origin = static::normalize($origin);
        if ($origin === null || in_array($origin, static::own_origins(), true)) {
            return null;
        }

        return $origin;
    }

    /**
     * The request's Origin when it names another site on the allow list.
     */
    public static function allowed_cross_origin(WP_REST_Request $request): ?string
    {
        $origin = static::cross_origin($request);

        return $origin !== null && static::allows($origin) ? $origin : null;
    }

    public static function allows(string $origin): bool
    {
        $origin = static::normalize($origin);
        if ($origin === null) {
            return false;
        }

        foreach (static::configured() as $pattern) {
            if (static::matches($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective allow list: the config list followed by the setting.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $entries = [];
        if (defined('ESCALATED_WIDGET_ALLOWED_ORIGINS')) {
            $constant = constant('ESCALATED_WIDGET_ALLOWED_ORIGINS');
            $entries = is_array($constant) ? $constant : static::split((string) $constant);
        }

        /**
         * Filters the widget embedding origins configured in code.
         *
         * The `widget_allowed_origins` setting is added to this list.
         *
         * @param  array<int, string>  $entries  Origins, e.g. 'https://shop.example.com' or 'https://*.example.com'.
         */
        $entries = (array) apply_filters('escalated_widget_allowed_origins', $entries);

        $entries = array_merge($entries, static::split((string) Setting::get('widget_allowed_origins', '')));

        return static::patterns($entries);
    }

    /**
     * Normalised, de-duplicated patterns from a comma or newline separated list.
     *
     * @return list<string>
     */
    public static function parse_list(string $value): array
    {
        return static::patterns(static::split($value));
    }

    /**
     * The entries of a comma or newline separated list that are not valid origins.
     *
     * @return list<string>
     */
    public static function invalid_entries(string $value): array
    {
        $invalid = [];
        foreach (static::split($value) as $entry) {
            if (static::normalize_pattern($entry) === null) {
                $invalid[] = $entry;
            }
        }

        return $invalid;
    }

    /**
     * Normalise an admin-entered origin or "*." pattern, or null when it is
     * not a bare http(s) origin.
     */
    public static function normalize_pattern(string $entry): ?string
    {
        $entry = trim($entry);
        if (! preg_match('#^(https?)://(\*\.)?([^/?\#*]+?)/?$#i', $entry, $m)) {
            return null;
        }

        $host = static::normalize(strtolower($m[1]).'://'.$m[3]);
        if ($host === null) {
            return null;
        }

        return $m[2] !== '' ? str_replace('://', '://*.', $host) : $host;
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', $value) ?: [],
            static fn ($entry) => $entry !== ''
        ));
    }

    /**
     * @param  array<mixed>  $entries
     * @return list<string>
     */
    private static function patterns(array $entries): array
    {
        $patterns = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $pattern = static::normalize_pattern($entry);
            if ($pattern !== null) {
                $patterns[$pattern] = true;
            }
        }

        return array_keys($patterns);
    }

    private static function matches(string $pattern, string $origin): bool
    {
        if (strpos($pattern, '://*.') === false) {
            return $pattern === $origin;
        }

        [$scheme, $suffix] = explode('://*.', $pattern, 2);
        if (strpos($origin, $scheme.'://') !== 0) {
            return false;
        }
        $host = substr($origin, strlen($scheme) + 3);

        return str_ends_with($host, '.'.$suffix) && strlen($host) > strlen($suffix) + 1;
    }

    /**
     * Origins that are this site: the requested scheme and host, and the
     * home and site URLs.
     *
     * @return list<string>
     */
    private static function own_origins(): array
    {
        $own = [];
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if ($host !== '') {
            $own[] = static::normalize((is_ssl() ? 'https' : 'http').'://'.$host);
        }
        $own[] = static::origin_of(home_url('/'));
        $own[] = static::origin_of(site_url('/'));

        return array_values(array_filter($own));
    }

    private static function origin_of(string $url): ?string
    {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return static::normalize($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }

    /**
     * scheme://host[:port], lowercased, default ports dropped.
     */
    private static function normalize(string $origin): ?string
    {
        $parts = wp_parse_url(trim($origin));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && $parts['path'] !== '/')) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port === null || ($scheme === 'http' && (int) $port === 80) || ($scheme === 'https' && (int) $port === 443)) {
            return $scheme.'://'.$host;
        }

        return $scheme.'://'.$host.':'.$port;
    }
}
