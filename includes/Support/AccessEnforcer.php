<?php

namespace Escalated\Support;

use Escalated\Services\TicketAccess;
use WP_Error;

/**
 * Requires each staff REST route's permission (RoutePermissions) while roles
 * & permissions is on, and does nothing while it is off.
 *
 * It runs on `rest_dispatch_request`, after the route's own permission
 * callback (the token check or the screen's capability), so those still
 * decide who reaches the route at all and this adds the permission on top.
 * An API token request is checked as the token's owner. A staff route
 * missing from the map is refused.
 */
final class AccessEnforcer
{
    public static function register(): void
    {
        add_filter('rest_dispatch_request', [self::class, 'check'], 5, 4);
    }

    /**
     * @param  mixed  $result
     * @return mixed
     */
    public static function check($result, \WP_REST_Request $request, string $route, array $handler)
    {
        if ($result !== null || ! AccessFeatures::roles_permissions() || ! str_starts_with($route, '/escalated/v1')) {
            return $result;
        }

        $path = RoutePermissions::normalize($route);
        if (RoutePermissions::is_public($path)) {
            return $result;
        }

        $user = TicketAccess::token_user($request) ?? get_current_user_id();
        if ($user <= 0) {
            return self::forbidden(__('Unauthorized.', 'escalated'));
        }

        $required = RoutePermissions::required($request->get_method(), $path, $request);
        if ($required === null) {
            return self::forbidden(__('This route has no permission mapping.', 'escalated'));
        }

        foreach ($required as $slug) {
            if (! user_can($user, RoutePermissions::cap($slug))) {
                /* translators: %s: permission slug */
                return self::forbidden(sprintf(__('You do not have the required permission: %s', 'escalated'), $slug));
            }
        }

        return $result;
    }

    /**
     * The capability a wp-admin ticket action needs while roles &
     * permissions is on (null: the screen's own check stands), as
     * escalated-laravel's map: status, priority, department and tags need
     * ticket.edit, a status change to resolved/closed/reopened ticket.close
     * as well.
     *
     * @return list<string>|null
     */
    public static function ticket_action_slugs(string $action, string $new_status = ''): ?array
    {
        if (! AccessFeatures::roles_permissions()) {
            return null;
        }

        return match ($action) {
            'reply' => ['reply.create'],
            'note' => ['reply.create_internal'],
            'change_status' => in_array($new_status, RoutePermissions::CLOSING_STATUSES, true) ? ['ticket.edit', 'ticket.close'] : ['ticket.edit'],
            'change_priority', 'change_department', 'update_tags' => ['ticket.edit'],
            'assign' => ['ticket.assign'],
            'merge' => ['ticket.merge'],
            default => [],
        };
    }

    /**
     * The `escalatedAdmin.permissions` the screens read: with roles &
     * permissions on, the user's slugs; off, ['*'] for staff and [] for
     * anyone else.
     *
     * @return list<string>
     */
    public static function permissions(int $user): array
    {
        if (! AccessFeatures::roles_permissions()) {
            return TicketAccess::is_staff($user) ? ['*'] : [];
        }

        $slugs = [];
        foreach (RoleCatalog::slugs() as $slug) {
            if ($user > 0 && user_can($user, RoutePermissions::cap($slug))) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    private static function forbidden(string $message): WP_Error
    {
        return new WP_Error('escalated_forbidden', $message, ['status' => 403]);
    }
}
