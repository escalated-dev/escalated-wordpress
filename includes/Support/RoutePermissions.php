<?php

namespace Escalated\Support;

/**
 * The permission each staff REST route needs while roles & permissions is
 * enforced (ADR 2026-10-29-configurable-access-features). This is
 * escalated-laravel's RoutePermissions for this plugin's `escalated/v1`
 * routes, keyed by HTTP method and the route as registered (placeholders
 * written `{name}`), relative to the namespace.
 *
 * Permissions are WordPress capabilities here: the slug `ticket.view` is the
 * capability `escalated_ticket_view` (see Activator::slug_to_cap), which the
 * Escalated roles and the administrator role hold. Entries are tried in
 * order and the first match wins. In a pattern `*` is one path segment and
 * `**` any number. A staff route that matches nothing is refused, and a test
 * enumerates the REST server so a new route cannot ship without an entry.
 */
final class RoutePermissions
{
    /** Statuses whose change needs ticket.close as well as ticket.edit. */
    public const CLOSING_STATUSES = ['resolved', 'closed', 'reopened'];

    /**
     * Routes that are not staff routes: sign-in, guests, the widget, the
     * mobile guest API, inbound mail, and attachment downloads (authorized
     * per ticket, for customers too), the newsletter mail provider
     * webhooks, and single sign-on.
     */
    public const PUBLIC_ROUTES = [
        '',
        '/auth/**',
        '/widget',
        '/widget/**',
        '/guest/**',
        '/mobile/**',
        '/inbound/**',
        '/sso/**',
        '/attachments/*/download',
        '/webhooks/**',
    ];

    /**
     * [methods, pattern, slugs]. Methods are '*' or names joined by '|'.
     *
     * @return list<array{0: string, 1: string, 2: list<string>}>
     */
    public static function map(): array
    {
        $view = ['ticket.view'];
        $edit = ['ticket.edit'];

        return [
            // ---- Personal: the user's own two-factor enrolment ----------------
            ['*', '/admin/two-factor', []],
            ['*', '/admin/two-factor/**', []],

            // ---- Tickets ------------------------------------------------------
            ['GET', '/tickets', $view],
            ['GET', '/tickets/merge-search', $view],
            ['GET', '/tickets/*', $view],
            ['POST', '/tickets', ['ticket.create']],
            ['POST', '/tickets/split', ['ticket.create']],
            ['DELETE', '/tickets/*', ['ticket.delete']],
            ['POST', '/tickets/*/reply', ['reply.create']],
            ['POST', '/tickets/*/note', ['reply.create_internal']],
            ['POST|PATCH', '/tickets/*/status', $edit], // + ticket.close, see required()
            ['POST|PATCH', '/tickets/*/priority', $edit],
            ['POST', '/tickets/*/tags', $edit],
            ['POST', '/tickets/*/snooze', $edit],
            ['POST', '/tickets/*/unsnooze', $edit],
            ['POST', '/tickets/*/actions/*', $edit],
            ['POST', '/tickets/*/assign', ['ticket.assign']],
            ['POST', '/tickets/*/merge', ['ticket.merge']],
            ['POST', '/tickets/*/follow', $view],
            ['POST', '/tickets/*/macro', ['macro.view', 'ticket.edit']],
            ['*', '/admin/tickets/*/subjects', $edit],
            ['*', '/admin/tickets/*/subjects/*', $edit],
            ['GET', '/dashboard', $view],
            ['GET', '/{departments,tags,canned-responses,agents}', $view],
            ['GET', '/events', $view],
            ['GET', '/events/types', $view],
            ['GET', '/macros', ['macro.view']],
            ['*', '/views', $view],
            ['*', '/views/*', $view],

            // ---- Live chat ----------------------------------------------------
            ['GET', '/chat/**', $view],
            ['POST', '/chat/**', ['reply.create']],

            // ---- Automations (time-based) ---------------------------------------
            ['GET', '/automations', ['automation.view']],
            ['GET', '/automations/*', ['automation.view']],
            ['*', '/automations', ['automation.manage']],
            ['*', '/automations/*', ['automation.manage']],

            // ---- Workflows (event-driven) ---------------------------------------
            ['GET', '/admin/workflows', ['automation.view']],
            ['*', '/admin/workflows', ['automation.manage']],
            ['*', '/admin/workflows/**', ['automation.manage']],

            // ---- Knowledge base -------------------------------------------------
            ['GET', '/admin/kb/{articles,categories}', ['kb.view']],
            ['GET', '/admin/kb/articles/*', ['kb.view']],
            ['POST', '/admin/kb/articles', ['kb.create']],
            ['PUT|PATCH', '/admin/kb/articles/*', ['kb.edit']],
            ['POST', '/admin/kb/categories', ['kb.edit']],
            ['PUT|PATCH', '/admin/kb/categories/*', ['kb.edit']],
            ['DELETE', '/admin/kb/{articles,categories}/*', ['kb.delete']],

            // ---- Skills (this plugin's skill.* capabilities) ---------------------
            ['GET', '/admin/skills', ['skill.view']],
            ['*', '/admin/skills', ['skill.manage']],
            ['*', '/admin/skills/**', ['skill.manage']],

            // ---- API tokens and the audit log -----------------------------------
            ['GET', '/admin/api-tokens', ['api_token.view']],
            ['*', '/admin/api-tokens', ['api_token.manage']],
            ['*', '/admin/api-tokens/*', ['api_token.manage']],
            ['GET', '/admin/audit-logs', ['audit.view']],

            // ---- Newsletters (ADR 2026-05-19-newsletter-system) ------------------
            ['*', '/admin/newsletters', ['newsletters.manage']],
            ['*', '/admin/newsletters/**', ['newsletters.manage']],
        ];
    }

    /** The route as registered, relative to the namespace, placeholders as {name}. */
    public static function normalize(string $route): string
    {
        $route = (string) preg_replace('#^/escalated/v1#', '', $route);
        $route = (string) preg_replace('/\(\?P<(\w+)>[^)]*\)/', '{$1}', $route);

        return str_replace('\\', '', rtrim($route, '/'));
    }

    public static function is_public(string $route): bool
    {
        foreach (self::PUBLIC_ROUTES as $pattern) {
            if (self::matches($pattern, $route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The slugs the route needs before looking at the request, or null when
     * it is not mapped.
     *
     * @return list<string>|null
     */
    public static function mapped(string $method, string $route): ?array
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        foreach (self::map() as [$methods, $pattern, $slugs]) {
            if ($methods !== '*' && ! in_array($method, explode('|', $methods), true)) {
                continue;
            }
            if (self::matches($pattern, $route)) {
                return $slugs;
            }
        }

        return null;
    }

    /**
     * The slugs a request needs (null when unmapped): a status change to
     * resolved, closed or reopened also needs ticket.close.
     * A note posted to /reply needs reply.create_internal instead of
     * reply.create.
     *
     * @return list<string>|null
     */
    public static function required(string $method, string $route, \WP_REST_Request $request): ?array
    {
        $required = self::mapped($method, $route);
        if ($required === null) {
            return null;
        }
        // An internal note posted to /reply (escalated-laravel's
        // is_internal_note) needs the note permission, not the reply one.
        if ($required === ['reply.create'] && rest_sanitize_boolean($request->get_param('is_internal_note'))) {
            $required = ['reply.create_internal'];
        }
        if (in_array('ticket.edit', $required, true)) {
            $status = $request->get_param('status');
            if (is_string($status) && in_array($status, self::CLOSING_STATUSES, true)) {
                $required[] = 'ticket.close';
            }
        }

        return array_values(array_unique($required));
    }

    /** The WordPress capability for a permission slug. */
    public static function cap(string $slug): string
    {
        return 'escalated_'.str_replace('.', '_', $slug);
    }

    public static function matches(string $pattern, string $route): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $c = $pattern[$i];
            if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $regex .= '.+';
                $i++;
            } elseif ($c === '*') {
                $regex .= '[^/]+';
            } elseif ($c === '{') {
                $end = strpos($pattern, '}', $i);
                $parts = array_map(static fn ($p) => preg_quote($p, '#'), explode(',', substr($pattern, $i + 1, $end - $i - 1)));
                $regex .= '(?:'.implode('|', $parts).')';
                $i = $end;
            } else {
                $regex .= preg_quote($c, '#');
            }
        }

        return (bool) preg_match('#^'.$regex.'$#', $route);
    }
}
