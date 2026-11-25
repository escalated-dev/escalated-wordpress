<?php

namespace Escalated\Support;

/**
 * Install-level switches for Escalated's public and customer surfaces
 * (escalated-laravel's escalated.widget.enabled,
 * escalated.guest.routes_enabled and escalated.routes.customer_middleware).
 *
 * Read from configuration only, like AccessFeatures: a constant in
 * wp-config.php, else the environment variable of the same name, then a
 * filter (code, for a host plugin or a test). The route switches are read
 * when Escalated registers its routes, so set them in wp-config.php or in a
 * (mu-)plugin, not in a theme.
 *
 * - ESCALATED_WIDGET_ENABLED (default true): false registers no widget route
 *   (/widget/*, /widget/chat/*, /widget/embed.js) and no widget CORS handling.
 * - ESCALATED_GUEST_ROUTES (default true): false registers no guest route
 *   (/guest/*, /mobile/guest/*, the guest admin-ajax actions), and the guest
 *   shortcode and guest ticket links render "not available". The
 *   guest_tickets_enabled setting still switches guests off at runtime.
 * - ESCALATED_CUSTOMER_CAPABILITIES (default none): capabilities a signed-in
 *   user must hold to use the customer portal ([escalated_tickets],
 *   [escalated_create_ticket], [escalated_view_ticket] and their admin-ajax
 *   actions). An array, or a comma-separated string. Set e.g.
 *   'escalated_ticket_view' to make the portal staff-only.
 */
final class RouteSwitches
{
    public static function widget_enabled(): bool
    {
        return (bool) apply_filters('escalated_widget_enabled', self::flag('ESCALATED_WIDGET_ENABLED', true));
    }

    public static function guest_routes_enabled(): bool
    {
        return (bool) apply_filters('escalated_guest_routes_enabled', self::flag('ESCALATED_GUEST_ROUTES', true));
    }

    /**
     * Extra capabilities required on the customer routes, on top of being
     * signed in.
     *
     * @return list<string>
     */
    public static function customer_capabilities(): array
    {
        $value = self::read('ESCALATED_CUSTOMER_CAPABILITIES');
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $caps = [];
        foreach ((array) ($value ?? []) as $cap) {
            $cap = trim((string) $cap);
            if ($cap !== '') {
                $caps[] = $cap;
            }
        }

        $caps = apply_filters('escalated_customer_capabilities', $caps);

        return array_values(array_filter(array_map('strval', (array) $caps), static fn ($cap) => $cap !== ''));
    }

    /**
     * Whether a signed-in user may use the customer portal. True for every
     * signed-in user unless customer capabilities are configured. The
     * `escalated_customer_can_access` filter can add any other guard.
     */
    public static function customer_allowed(?int $user_id = null): bool
    {
        $user_id ??= get_current_user_id();
        if ($user_id <= 0) {
            return false;
        }

        $allowed = true;
        foreach (self::customer_capabilities() as $cap) {
            if (! user_can($user_id, $cap)) {
                $allowed = false;
                break;
            }
        }

        return (bool) apply_filters('escalated_customer_can_access', $allowed, $user_id);
    }

    private static function flag(string $name, bool $default): bool
    {
        $value = self::read($name);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private static function read(string $name): mixed
    {
        if (defined($name)) {
            return constant($name);
        }
        $env = getenv($name);

        return $env === false ? null : $env;
    }
}
