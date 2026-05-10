<?php

namespace Escalated\Admin;

/**
 * Users management admin page.
 *
 * Surfaces the WordPress user list and lets an admin grant or revoke
 * the Escalated admin / agent roles from the panel. Ports the Laravel
 * reference's UserController (escalated-laravel PR #94), with one
 * deviation: instead of toggling `is_admin` / `is_agent` columns on a
 * host User row, this implementation toggles WordPress roles
 * (`escalated_admin`, `escalated_agent`) on the WP user. That is the
 * native WordPress permission surface — see Activator::create_roles().
 *
 * The "admin" toggle maps to the `escalated_admin` WP role; the "agent"
 * toggle maps to the `escalated_agent` WP role. The same admin→agent
 * cascade rules from Laravel apply:
 *   - Promoting to admin also promotes to agent.
 *   - Revoking the agent role from an admin demotes them fully (clears
 *     admin too) so the gate state stays internally consistent.
 *   - Admins cannot revoke their own admin role.
 *
 * The WordPress `administrator` role is treated as "admin" by every
 * other gate in this plugin (see Activator::add_admin_caps) so we treat
 * it as admin here too: it is listed as such, and the WP super-admin
 * cannot demote themselves.
 */
class Admin_Users
{
    public const ADMIN_ROLE = 'escalated_admin';

    public const AGENT_ROLE = 'escalated_agent';

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    /**
     * Render the users admin page.
     */
    public function render(): void
    {
        $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;

        $query_args = [
            'number' => $per_page,
            'paged' => $paged,
            'orderby' => 'ID',
            'order' => 'ASC',
            'count_total' => true,
        ];

        if ($search !== '') {
            // Match against email OR display name/user_login — mirrors the
            // Laravel reference's "name + email" LIKE filter.
            $query_args['search'] = '*'.$search.'*';
            $query_args['search_columns'] = ['user_email', 'user_login', 'display_name'];
        }

        $user_query = new \WP_User_Query($query_args);
        $rows = [];
        foreach ($user_query->get_results() as $user) {
            $rows[] = self::user_to_row($user);
        }

        // Match Laravel's ordering: admins first, then agents, then others.
        usort($rows, function (array $a, array $b): int {
            if ($a['is_admin'] !== $b['is_admin']) {
                return $b['is_admin'] <=> $a['is_admin'];
            }
            if ($a['is_agent'] !== $b['is_agent']) {
                return $b['is_agent'] <=> $a['is_agent'];
            }

            return $a['id'] <=> $b['id'];
        });

        $total = (int) $user_query->get_total();
        $total_pages = (int) max(1, ceil($total / $per_page));
        $current_user_id = get_current_user_id();
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        $users = $rows;

        include ESCALATED_PLUGIN_DIR.'templates/admin/users.php';
    }

    /**
     * Handle POST actions: update a user's role.
     */
    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_user_action'])) {
            return;
        }

        if (! current_user_can('escalated_user_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['escalated_user_action']));
        $redirect = admin_url('admin.php?page=escalated-users');

        if ($action === 'update_role') {
            $user_id = absint($_POST['user_id'] ?? 0);
            $nonce = sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? ''));

            if (! wp_verify_nonce($nonce, 'escalated_user_role_'.$user_id)) {
                wp_die(esc_html__('Security check failed.', 'escalated'));
            }

            $role = sanitize_text_field(wp_unslash($_POST['role'] ?? ''));
            $value_raw = $_POST['value'] ?? '';
            if (is_string($value_raw)) {
                $value = in_array(strtolower($value_raw), ['1', 'true', 'on', 'yes'], true);
            } else {
                $value = (bool) $value_raw;
            }

            $result = self::update_role($user_id, $role, $value, get_current_user_id());

            if ($result['ok'] ?? false) {
                $redirect = add_query_arg('message', 'updated', $redirect);
            } else {
                $code = $result['error'] ?? 'error';
                $redirect = add_query_arg('error', $code, $redirect);
            }
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Grant or revoke an Escalated role on a WP user.
     *
     * Pure-ish: only touches the WP user's roles via WP_User::add_role /
     * remove_role. Caller is responsible for permission gating.
     *
     * Cascade rules (mirror the Laravel reference):
     *   - role=admin, value=true  → also grants agent.
     *   - role=admin, value=false → only clears admin (agent stays).
     *   - role=agent, value=false → also clears admin if user is admin
     *     (avoids leaving the admin gate on while the agent gate is off).
     *
     * Self-demotion guard: an admin cannot remove their own admin role.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function update_role(int $user_id, string $role, bool $value, ?int $current_user_id): array
    {
        if (! in_array($role, ['admin', 'agent'], true)) {
            return ['ok' => false, 'error' => 'invalid_role'];
        }

        $user = get_userdata($user_id);
        if (! $user) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        // Self-demote guard: don't let an admin remove their own admin role.
        if ($role === 'admin' && ! $value && $current_user_id && (int) $current_user_id === (int) $user->ID) {
            return ['ok' => false, 'error' => 'self_demote'];
        }

        if ($role === 'admin') {
            if ($value) {
                self::grant_role($user, self::ADMIN_ROLE);
                // Admins are agents.
                self::grant_role($user, self::AGENT_ROLE);
            } else {
                self::revoke_role($user, self::ADMIN_ROLE);
                // Demoting an admin does NOT also revoke agent — an ex-admin
                // can still answer tickets unless explicitly demoted from agent.
            }
        } else { // role === 'agent'
            if ($value) {
                self::grant_role($user, self::AGENT_ROLE);
            } else {
                self::revoke_role($user, self::AGENT_ROLE);
                // Revoking agent from an admin would leave the admin gate on
                // and the agent gate off — confusing. Demote them fully.
                if (self::user_is_admin($user)) {
                    self::revoke_role($user, self::ADMIN_ROLE);
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * Project a WP_User into the row shape the admin template consumes.
     *
     * @return array{id: int, name: string, email: string, is_admin: bool, is_agent: bool}
     */
    public static function user_to_row(\WP_User $user): array
    {
        return [
            'id' => (int) $user->ID,
            'name' => $user->display_name ?: $user->user_login,
            'email' => $user->user_email,
            'is_admin' => self::user_is_admin($user),
            'is_agent' => self::user_is_agent($user),
        ];
    }

    /**
     * Does this user count as an Escalated admin?
     *
     * True for users with the `escalated_admin` role or the native WP
     * `administrator` role — Activator::add_admin_caps grants every
     * Escalated capability to the WP administrator role, so it would
     * be inconsistent for the user list to claim they're not an admin.
     */
    public static function user_is_admin(\WP_User $user): bool
    {
        return in_array(self::ADMIN_ROLE, (array) $user->roles, true)
            || in_array('administrator', (array) $user->roles, true);
    }

    /**
     * Does this user count as an Escalated agent?
     *
     * Admins are agents (see update_role cascade), but a WP administrator
     * with no Escalated role still has the admin capability set, so we
     * count them too. Light agents are not "agents" for the purpose of
     * this toggle — the toggle only manages the `escalated_agent` role.
     */
    public static function user_is_agent(\WP_User $user): bool
    {
        $roles = (array) $user->roles;

        return in_array(self::AGENT_ROLE, $roles, true)
            || in_array(self::ADMIN_ROLE, $roles, true)
            || in_array('administrator', $roles, true);
    }

    private static function grant_role(\WP_User $user, string $role): void
    {
        if (! in_array($role, (array) $user->roles, true)) {
            $user->add_role($role);
        }
    }

    private static function revoke_role(\WP_User $user, string $role): void
    {
        if (in_array($role, (array) $user->roles, true)) {
            $user->remove_role($role);
        }
    }
}
