<?php

namespace Escalated\Support;

use Escalated\Activator;

/**
 * Escalated roles on WordPress. This plugin's permissions are WordPress
 * capabilities (`ticket.view` is `escalated_ticket_view`), so its roles are
 * WordPress roles: the ones that hold any Escalated capability. The system
 * roles (administrator and the escalated_admin, escalated_agent and
 * escalated_light_agent roles the plugin creates) cannot be deleted.
 */
final class RoleCatalog
{
    public const SYSTEM_ROLES = ['administrator', 'escalated_admin', 'escalated_agent', 'escalated_light_agent'];

    /** Roles managed in WordPress itself, never assigned from these screens. */
    public const UNASSIGNABLE = ['administrator'];

    /** @return list<array{slug: string, name: string, group: string, description: string}> */
    public static function definitions(): array
    {
        return Activator::permission_definitions();
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::definitions(), 'slug');
    }

    /** @return array<string, list<array{slug: string, name: string, group: string, description: string}>> */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::definitions() as $def) {
            $out[$def['group']][] = $def;
        }

        return $out;
    }

    /**
     * Roles that hold any Escalated capability (and the system roles), by name.
     *
     * @return array<string, array{slug: string, name: string, permissions: list<string>, users_count: int, is_system: bool}>
     */
    public static function roles(): array
    {
        $counts = count_users()['avail_roles'] ?? [];
        $out = [];
        foreach (wp_roles()->roles as $slug => $role) {
            $permissions = self::permissions_of((array) ($role['capabilities'] ?? []));
            if ($permissions === [] && ! in_array($slug, self::SYSTEM_ROLES, true)) {
                continue;
            }
            $out[$slug] = [
                'slug' => $slug,
                'name' => translate_user_role($role['name']),
                'permissions' => $permissions,
                'users_count' => (int) ($counts[$slug] ?? 0),
                'is_system' => in_array($slug, self::SYSTEM_ROLES, true),
            ];
        }
        uasort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** @return array<string, array{slug: string, name: string, permissions: list<string>, users_count: int, is_system: bool}> */
    public static function assignable(): array
    {
        return array_diff_key(self::roles(), array_flip(self::UNASSIGNABLE));
    }

    /**
     * Create (null slug) or update a role: its name and exactly these
     * Escalated permissions. Capabilities that are not Escalated ones are
     * left alone. Returns the role's slug.
     *
     * @param  list<string>  $permissions
     */
    public static function save(?string $slug, string $name, array $permissions): string
    {
        $permissions = array_values(array_intersect(self::slugs(), $permissions));
        if ($slug === null) {
            $base = 'escalated_'.str_replace('-', '_', sanitize_title($name));
            $slug = $base;
            for ($i = 2; get_role($slug) !== null; $i++) {
                $slug = $base.'_'.$i;
            }
            $caps = ['read' => true];
            foreach ($permissions as $p) {
                $caps[RoutePermissions::cap($p)] = true;
            }
            add_role($slug, $name, $caps);

            return $slug;
        }

        $role = get_role($slug);
        if ($role === null) {
            return $slug;
        }
        foreach (self::slugs() as $p) {
            if (in_array($p, $permissions, true)) {
                $role->add_cap(RoutePermissions::cap($p));
            } else {
                $role->remove_cap(RoutePermissions::cap($p));
            }
        }
        $wp_roles = wp_roles();
        if (isset($wp_roles->roles[$slug]) && $wp_roles->roles[$slug]['name'] !== $name) {
            $wp_roles->roles[$slug]['name'] = $name;
            $wp_roles->role_names[$slug] = $name;
            update_option($wp_roles->role_key, $wp_roles->roles);
        }

        return $slug;
    }

    /** Delete a non-system role; false for a system role or an unknown one. */
    public static function delete(string $slug): bool
    {
        if (in_array($slug, self::SYSTEM_ROLES, true) || get_role($slug) === null) {
            return false;
        }
        foreach (get_users(['role' => $slug, 'fields' => 'ID']) as $user_id) {
            (new \WP_User((int) $user_id))->remove_role($slug);
        }
        remove_role($slug);

        return true;
    }

    /** @return list<string> the user's assignable Escalated roles */
    public static function user_roles(int $user_id): array
    {
        $user = get_userdata($user_id);
        if (! $user) {
            return [];
        }

        return array_values(array_intersect($user->roles, array_keys(self::assignable())));
    }

    /**
     * Replace the user's assignable Escalated roles with $roles. Other roles
     * (subscriber, administrator, ...) are kept.
     *
     * @param  list<string>  $roles
     */
    public static function replace_user_roles(int $user_id, array $roles): void
    {
        $user = new \WP_User($user_id);
        $assignable = array_keys(self::assignable());
        foreach (array_intersect($user->roles, $assignable) as $role) {
            if (! in_array($role, $roles, true)) {
                $user->remove_role($role);
            }
        }
        foreach (array_intersect($roles, $assignable) as $role) {
            if (! in_array($role, $user->roles, true)) {
                $user->add_role($role);
            }
        }
    }

    /**
     * Whether the user would hold the permission with exactly these
     * assignable roles (plus their other roles).
     *
     * @param  list<string>  $roles
     */
    public static function would_grant(int $user_id, array $roles, string $permission): bool
    {
        $user = get_userdata($user_id);
        $kept = $user ? array_diff($user->roles, array_keys(self::assignable())) : [];
        foreach (array_merge($kept, $roles) as $slug) {
            $role = get_role($slug);
            if ($role && $role->has_cap(RoutePermissions::cap($permission))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the Escalated permission slugs in a capability map */
    private static function permissions_of(array $caps): array
    {
        $out = [];
        foreach (self::slugs() as $p) {
            if (! empty($caps[RoutePermissions::cap($p)])) {
                $out[] = $p;
            }
        }

        return $out;
    }
}
