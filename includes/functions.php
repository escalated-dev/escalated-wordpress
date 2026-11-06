<?php

/**
 * Public helpers for host code (themes, plugins, mu-plugins).
 */
if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('escalated_embed_admin')) {
    /**
     * Turn on embedding Escalated's admin in your own admin (ADR
     * 2026-11-05-embeddable-admin; docs/embedding-admin.md). Equivalent to an
     * `escalated_ui_embed` filter that sets `enabled` and the given options.
     * Call it before wp-admin builds its menu, e.g. from a plugin on
     * `plugins_loaded` or from a theme's functions.php.
     *
     * @param  array{mode?: 'layout'|'iframe', frame_ancestors?: list<string>, guard?: string|callable|null, layout?: string|null}  $options
     */
    function escalated_embed_admin(array $options = []): void
    {
        $options = ['enabled' => true] + $options;
        add_filter('escalated_ui_embed', static fn (array $config): array => array_merge($config, $options));
    }
}

if (! function_exists('escalated_admin_navigation')) {
    /**
     * Escalated's admin menu as NavigationItem[] (key, label, route, url,
     * icon, position, active, badge, plugin, children) for the given user
     * (default: the current one), limited to the screens they may open.
     *
     * @return list<array<string, mixed>>
     */
    function escalated_admin_navigation(int|WP_User|null $user = null, ?string $current_page = null): array
    {
        return \Escalated\Support\EmbedAdmin::navigation($user, $current_page);
    }
}
