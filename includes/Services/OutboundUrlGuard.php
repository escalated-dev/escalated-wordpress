<?php

namespace Escalated\Services;

/**
 * Decides whether the plugin may send a request to a URL an admin typed in,
 * such as a webhook endpoint. Ported from escalated-laravel's
 * Support\OutboundUrlGuard.
 *
 * The URL must be http or https, and every address its host resolves to must
 * be public. Loopback, private, link-local (cloud metadata sits at
 * 169.254.169.254) and other reserved ranges are refused, and so is a host
 * that does not resolve at all.
 *
 * The `escalated_outbound_url_resolve` filter can answer the lookup instead
 * of DNS (return a list of addresses, or null to use DNS).
 */
class OutboundUrlGuard
{
    public function allows(string $url): bool
    {
        return $this->public_address_for($url) !== null;
    }

    /**
     * The public address a request to the URL should connect to, or null
     * when the URL must not be requested.
     */
    public function public_address_for(string $url): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = trim((string) wp_parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            return null;
        }

        $addresses = array_values(array_filter(
            $this->resolve($host),
            static fn ($address) => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false
        ));

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! self::is_public($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    /**
     * Every address the host resolves to. An IP literal resolves to itself.
     *
     * @return array<int, mixed>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        /**
         * Answer the host lookup instead of DNS.
         *
         * @param  array|null  $addresses  Null to use DNS.
         * @param  string  $host  The host name.
         */
        $filtered = apply_filters('escalated_outbound_url_resolve', null, $host);
        if (is_array($filtered)) {
            return $filtered;
        }

        $addresses = gethostbynamel($host);

        return $addresses === false ? [] : array_values($addresses);
    }

    public static function is_public(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0)
        ) !== false;
    }
}
