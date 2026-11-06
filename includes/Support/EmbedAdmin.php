<?php

namespace Escalated\Support;

use WP_User;

/**
 * Embedding Escalated's admin in the host's own admin (ADR
 * 2026-11-05-embeddable-admin), the WordPress way. Optional and off by
 * default; see docs/embedding-admin.md.
 *
 * Escalated's admin screens already are wp-admin pages, so the parts of the
 * ADR that move routes under a host prefix or swap the Inertia root view have
 * nothing to act on here. What does apply:
 *
 * - the admin menu as NavigationItem[] data (navigation()), for a custom
 *   admin, another wp-admin page or a theme template, and as
 *   `escalatedAdmin.embed` / `escalatedAdmin.navigation` on Escalated's
 *   screens while embedding is on;
 * - a host guard (a capability or a callable) that every embedded Escalated
 *   screen must pass, on top of the screen's own capability;
 * - iframe mode: an Escalated screen requested with `escalated_embed=1` is
 *   rendered without the wp-admin menu, admin bar and footer, may be framed
 *   by the configured origins only (`Content-Security-Policy:
 *   frame-ancestors`), loses WordPress's `X-Frame-Options: SAMEORIGIN`, and
 *   talks the ADR's postMessage protocol to an allowed parent.
 *
 * Configuration is read from code only, never from the settings table:
 * the ESCALATED_EMBED, ESCALATED_EMBED_MODE and
 * ESCALATED_EMBED_FRAME_ANCESTORS constants (else environment variables of
 * the same names), then the `escalated_ui_embed` filter, which
 * escalated_embed_admin() adds to.
 */
final class EmbedAdmin
{
    /** Query flag marking a framed (iframe mode) request. */
    public const FRAME_FLAG = 'escalated_embed';

    /**
     * The admin menu: [key, label, wp-admin page, capability, position, ADR route name].
     * Keys and positions follow the ADR; screens this plugin does not have are not listed.
     */
    private const ITEMS = [
        ['reports', 'Reports', 'escalated-reports', 'escalated_report_view', 10, 'escalated.admin.reports'],
        ['tickets', 'Tickets', 'escalated', 'escalated_ticket_view', 20, 'escalated.admin.tickets.index'],
        ['departments', 'Departments', 'escalated-departments', 'escalated_department_view', 30, 'escalated.admin.departments.index'],
        ['sla-policies', 'SLA Policies', 'escalated-sla-policies', 'escalated_sla_view', 40, 'escalated.admin.sla-policies.index'],
        ['escalation-rules', 'Escalation Rules', 'escalated-escalation-rules', 'escalated_escalation_view', 50, 'escalated.admin.escalation-rules.index'],
        ['workflows', 'Workflows', 'escalated-workflows', 'escalated_automation_manage', 53, 'escalated.admin.workflows.index'],
        ['automations', 'Automations', 'escalated-automations', 'escalated_automation_view', 53.5, 'escalated.admin.automations.index'],
        ['webhooks', 'Webhooks', 'escalated-webhooks', 'escalated_webhook_manage', 54, 'escalated.admin.webhooks.index'],
        ['tags', 'Tags', 'escalated-tags', 'escalated_tag_view', 60, 'escalated.admin.tags.index'],
        ['canned-responses', 'Canned Responses', 'escalated-canned-responses', 'escalated_macro_manage', 70, 'escalated.admin.canned-responses.index'],
        ['macros', 'Macros', 'escalated-macros', 'escalated_macro_view', 80, 'escalated.admin.macros.index'],
        ['newsletters', 'Newsletters', 'escalated-newsletters', 'escalated_newsletters_manage', 81, 'escalated.admin.newsletters.index'],
        ['skills', 'Skills', 'escalated-skills', 'escalated_skill_manage', 82, 'escalated.admin.skills.index'],
        ['users', 'Users', 'escalated-users', 'escalated_user_manage', 83, 'escalated.admin.users.index'],
        ['roles', 'Roles', 'escalated-roles', 'escalated_role_view', 84, 'escalated.admin.roles.index'],
        ['business-hours', 'Business Hours', 'escalated-business-hours', 'escalated_sla_view', 86, 'escalated.admin.business-hours.index'],
        ['api-tokens', 'API Tokens', 'escalated-api-tokens', 'escalated_api_token_view', 90, 'escalated.admin.api-tokens.index'],
        ['settings', 'Settings', 'escalated-settings', 'escalated_settings_view', 100, 'escalated.admin.settings'],
    ];

    /** The CSP header already sent with this response, so it is sent once. */
    private static ?string $sent = null;

    /**
     * The embed configuration. Defaults: everything off.
     *
     * @return array{enabled: bool, mode: string, layout: string|null, guard: string|callable|null, frame_ancestors: list<string>}
     */
    public static function config(): array
    {
        $config = [
            'enabled' => self::truthy(self::read('ESCALATED_EMBED')),
            'mode' => (string) (self::read('ESCALATED_EMBED_MODE') ?? 'layout'),
            'layout' => null,
            'guard' => null,
            'frame_ancestors' => self::read('ESCALATED_EMBED_FRAME_ANCESTORS') ?? [],
        ];

        $config = (array) apply_filters('escalated_ui_embed', $config) + $config;

        $ancestors = $config['frame_ancestors'];
        if (is_string($ancestors)) {
            $ancestors = explode(',', $ancestors);
        }
        $guard = $config['guard'];

        return [
            'enabled' => self::truthy($config['enabled']),
            'mode' => in_array($config['mode'], ['layout', 'iframe'], true) ? $config['mode'] : 'layout',
            'layout' => is_string($config['layout']) && $config['layout'] !== '' ? $config['layout'] : null,
            'guard' => is_string($guard) || is_callable($guard) ? $guard : null,
            'frame_ancestors' => self::origins(is_array($ancestors) ? $ancestors : []),
        ];
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'];
    }

    /**
     * Wire up the current wp-admin request. Called while the wp-admin menu is
     * built (`admin_menu`, before `admin_init`), so a theme's functions.php
     * can configure embedding too. Adds nothing while embedding is off.
     */
    public static function boot(): void
    {
        if (! self::is_embedded_request()) {
            return;
        }

        add_action('admin_init', [self::class, 'guard_request'], 0);

        if (self::config()['mode'] !== 'iframe') {
            return;
        }

        // WordPress sends X-Frame-Options: SAMEORIGIN on admin_init; this
        // response is framed by the allow-listed origins instead.
        remove_action('admin_init', 'send_frame_options_header', 10);
        remove_action('in_admin_header', 'wp_admin_bar_render', 0);
        // Applied as the very last thing before PHP sends the headers, so a
        // header another plugin adds later cannot undo it; and again at the
        // end of admin_init in case another header callback replaces ours.
        header_register_callback([self::class, 'send_frame_headers']);
        add_action('admin_init', [self::class, 'send_frame_headers'], PHP_INT_MAX);

        add_filter('admin_url', [self::class, 'keep_frame_flag'], 10, 1);
        add_filter('admin_body_class', [self::class, 'body_class']);
        add_action('admin_head', [self::class, 'print_chrome_free_styles']);
    }

    /** Whether this request is an Escalated wp-admin screen that is embedded. */
    public static function is_embedded_request(): bool
    {
        $config = self::config();
        if (! $config['enabled'] || ! is_admin() || ! self::is_escalated_page(self::current_page())) {
            return false;
        }

        return $config['mode'] === 'layout' || self::has_frame_flag();
    }

    /** The host guard: refuses the embedded screen when the host says no. */
    public static function guard_request(): void
    {
        if (! self::is_embedded_request()) {
            return;
        }
        if (! self::passes_guard(wp_get_current_user())) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'escalated'), 403);
        }
    }

    /** Send the iframe headers for this response (header_register_callback / admin_init). */
    public static function send_frame_headers(): void
    {
        self::apply_frame_headers('header', 'header_remove');
    }

    /**
     * The iframe mode headers, through the given header functions: remove
     * X-Frame-Options and add `frame-ancestors 'self' <origins>` as its own
     * policy (a CSP the host already sends is kept). Only on embedded iframe
     * responses.
     */
    public static function apply_frame_headers(callable $header, callable $remove): void
    {
        $config = self::config();
        if ($config['mode'] !== 'iframe' || ! self::is_embedded_request()) {
            return;
        }

        $remove('X-Frame-Options');
        $csp = trim("Content-Security-Policy: frame-ancestors 'self' ".implode(' ', $config['frame_ancestors']));
        if ($header === 'header') {
            if (self::$sent === $csp) {
                return;
            }
            self::$sent = $csp;
        }
        $header($csp, false);
    }

    /** iframe mode: links and redirects to Escalated screens stay framed. */
    public static function keep_frame_flag(string $url): string
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($query, $args);
        if (! self::is_escalated_page((string) ($args['page'] ?? '')) || isset($args[self::FRAME_FLAG])) {
            return $url;
        }

        return add_query_arg(self::FRAME_FLAG, '1', $url);
    }

    public static function body_class(string $classes): string
    {
        return trim($classes.' escalated-embedded');
    }

    /** Hide the wp-admin chrome on a framed screen; the host frame provides its own. */
    public static function print_chrome_free_styles(): void
    {
        echo '<style id="escalated-embedded">'
            .'html.wp-toolbar{padding-top:0!important}'
            .'#wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,#screen-meta-links{display:none!important}'
            .'#wpcontent,#wpfooter{margin-left:0!important}'
            .'#wpbody-content{padding-bottom:0!important}'
            .'</style>';
    }

    /**
     * What Escalated's screens get as `escalatedAdmin.embed` and
     * `escalatedAdmin.navigation`: nothing while embedding is off.
     *
     * @return array<string, mixed>
     */
    public static function script_data(): array
    {
        $config = self::config();
        if (! $config['enabled']) {
            return [];
        }

        return [
            'embed' => [
                'enabled' => self::is_embedded_request(),
                'mode' => $config['mode'],
                'layout' => $config['layout'],
                'route' => self::is_embedded_request() ? self::route_for(self::current_page()) : null,
                'base_url' => self::base_url(),
                'allowed_origins' => $config['mode'] === 'iframe' ? $config['frame_ancestors'] : [],
            ],
            'navigation' => self::navigation(get_current_user_id()),
        ];
    }

    /** Whether this response is a framed screen that should load the frame bridge. */
    public static function wants_bridge(): bool
    {
        return self::config()['mode'] === 'iframe' && self::is_embedded_request();
    }

    /**
     * The Escalated admin menu as the ADR's NavigationItem[], for the given
     * user (default: the current one): only the screens they may open, plus
     * add-on items from the `escalated_admin_navigation_items` filter, sorted
     * by position. Works whether or not embedding is on.
     *
     * @return list<array<string, mixed>>
     */
    public static function navigation(int|WP_User|null $user = null, ?string $current_page = null): array
    {
        $user = $user instanceof WP_User ? $user : new WP_User($user ?? get_current_user_id());
        $current_page ??= self::current_page();
        if (! $user->exists()) {
            return [];
        }

        $config = self::config();
        if ($config['enabled'] && ! self::passes_guard($user)) {
            return [];
        }
        $framed = $config['enabled'] && $config['mode'] === 'iframe';

        $items = [];
        foreach (self::ITEMS as [$key, $label, $page, $cap, $position, $route]) {
            if ($key === 'roles' && ! AccessFeatures::roles_permissions()) {
                continue;
            }
            if (! user_can($user, $cap)) {
                continue;
            }
            $url = self::page_path($page);
            if ($framed) {
                $url .= '&'.self::FRAME_FLAG.'=1';
            }
            $items[] = [
                'key' => $key,
                'label' => __($label, 'escalated'),
                'route' => $route,
                'url' => $url,
                'icon' => $key,
                'position' => $position,
                'active' => $current_page === $page,
                'badge' => null,
                'plugin' => null,
                'children' => [],
            ];
        }

        $extra = apply_filters('escalated_admin_navigation_items', [], $user);
        $counts = [];
        foreach (is_array($extra) ? $extra : [] as $item) {
            if (! is_array($item) || ! self::may_see($user, $item)) {
                continue;
            }
            $slug = sanitize_key((string) ($item['plugin'] ?? 'addon'));
            $n = $counts[$slug] = isset($counts[$slug]) ? $counts[$slug] + 1 : 0;
            $entry = self::extra_item($item, 'plugin:'.$slug.':'.$n, $slug, $current_page);
            $entry['children'] = [];
            foreach (array_values((array) ($item['children'] ?? [])) as $i => $child) {
                if (is_array($child) && self::may_see($user, $child)) {
                    $entry['children'][] = self::extra_item($child, $entry['key'].':'.$i, $slug, $current_page);
                }
            }
            $items[] = $entry;
        }

        usort($items, static fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $items;
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private static function extra_item(array $item, string $key, string $slug, string $current_page): array
    {
        $url = (string) ($item['url'] ?? '');
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $args);

        return [
            'key' => $key,
            'label' => (string) ($item['label'] ?? ''),
            'route' => isset($item['route']) ? (string) $item['route'] : null,
            'url' => $url,
            'icon' => isset($item['icon']) ? (string) $item['icon'] : null,
            'position' => is_numeric($item['position'] ?? null) ? $item['position'] + 0 : 0,
            'active' => $current_page !== '' && ($args['page'] ?? null) === $current_page,
            'badge' => $item['badge'] ?? null,
            'plugin' => $slug,
        ];
    }

    private static function may_see(WP_User $user, array $item): bool
    {
        return empty($item['capability']) || user_can($user, (string) $item['capability']);
    }

    private static function passes_guard(WP_User $user): bool
    {
        $guard = self::config()['guard'];
        if ($guard === null) {
            return true;
        }
        if (is_string($guard) && ! is_callable($guard)) {
            return user_can($user, $guard);
        }

        return (bool) call_user_func($guard, $user);
    }

    private static function route_for(string $page): ?string
    {
        foreach (self::ITEMS as $item) {
            if ($item[2] === $page) {
                return $item[5];
            }
        }

        return null;
    }

    private static function current_page(): string
    {
        return isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
    }

    private static function has_frame_flag(): bool
    {
        return isset($_GET[self::FRAME_FLAG]) && (string) $_GET[self::FRAME_FLAG] === '1';
    }

    private static function is_escalated_page(string $page): bool
    {
        return $page === 'escalated' || str_starts_with($page, 'escalated-');
    }

    /** The admin.php path, e.g. /wp-admin/admin.php. */
    private static function base_url(): string
    {
        return (string) wp_parse_url(admin_url('admin.php'), PHP_URL_PATH);
    }

    private static function page_path(string $page): string
    {
        return self::base_url().'?page='.$page;
    }

    /**
     * Exact http(s) origins only: no `*`, no wildcard hosts, no paths.
     *
     * @param  array<mixed>  $list
     * @return list<string>
     */
    private static function origins(array $list): array
    {
        $origins = [];
        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if (! preg_match('#^(https?)://([a-z0-9.-]+|\[[0-9a-f:]+\])(:\d{1,5})?/?$#i', $entry, $m)) {
                continue;
            }
            $origin = strtolower($m[1].'://'.$m[2]).($m[3] ?? '');
            if (! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    private static function read(string $name): mixed
    {
        if (defined($name)) {
            return constant($name);
        }
        $env = getenv($name);

        return $env === false ? null : $env;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
