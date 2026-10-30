<?php

namespace Escalated\Services;

use Escalated\Support\AccessFeatures;
use Escalated\Support\RoleCatalog;
use Escalated\Support\SsoException;

/**
 * Maps an SSO identity to a WordPress user: matched by email
 * (case-insensitively), created only when ESCALATED_SSO_CREATE_USERS is on,
 * and with roles & permissions and ESCALATED_SSO_SYNC_ROLES both on, the
 * user's Escalated roles replaced by the asserted role when it names an
 * Escalated role. SSO never grants a role by itself otherwise; it only
 * signs the user in.
 */
class SsoUserResolver
{
    /**
     * @param  array{email: string, name: string, role: string}  $identity
     *
     * @throws SsoException
     */
    public function resolve(array $identity): \WP_User
    {
        $email = $identity['email'];
        $user = get_user_by('email', $email);
        if (! $user) {
            $wpdb = \Escalated\Escalated::db();
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE LOWER(user_email) = %s LIMIT 1", strtolower($email)));
            $user = $id ? get_userdata($id) : false;
        }

        if (! $user) {
            if (! SsoService::option('create_users', false)) {
                throw new SsoException("SSO refused: no user has the email [{$email}] and creating users is off.");
            }
            $login = sanitize_user(strstr($email, '@', true) ?: $email, true);
            $base = $login !== '' ? $login : 'user';
            for ($i = 2; username_exists($login); $i++) {
                $login = $base.$i;
            }
            $id = wp_insert_user([
                'user_login' => $login,
                'user_email' => $email,
                'display_name' => $identity['name'] !== '' ? $identity['name'] : $base,
                'user_pass' => wp_generate_password(64, true, true),
            ]);
            if (is_wp_error($id)) {
                throw new SsoException('SSO refused: the user could not be created: '.$id->get_error_message());
            }
            $user = get_userdata((int) $id);
        }

        $this->sync_role($user, $identity['role']);

        return $user;
    }

    private function sync_role(\WP_User $user, string $role): void
    {
        if ($role === '' || ! AccessFeatures::roles_permissions() || ! SsoService::option('sync_roles', false)) {
            return;
        }
        // Escalated role slugs as escalated-laravel names them map to the
        // plugin's WordPress roles (light_agent is escalated_light_agent).
        $candidates = [$role, 'escalated_'.$role];
        $assignable = RoleCatalog::assignable();
        foreach ($candidates as $slug) {
            if (isset($assignable[$slug])) {
                RoleCatalog::replace_user_roles((int) $user->ID, [$slug]);

                return;
            }
        }
    }
}
