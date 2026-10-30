<?php

namespace Escalated\Admin;

use Escalated\Models\AuditLog;
use Escalated\Support\AccessFeatures;
use Escalated\Support\RoleCatalog;

/**
 * Escalated > Roles (escalated-laravel's Admin\RoleController), only while
 * roles & permissions is switched on: the WordPress roles that hold
 * Escalated capabilities, and which capabilities each holds. While the
 * switch is off the screen is not in the menu and answers 404.
 *
 * Viewing needs escalated_role_view, changing needs escalated_role_manage.
 * System roles (administrator and the three roles the plugin creates) can be
 * edited but not deleted.
 */
class Admin_Roles
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function render(): void
    {
        self::require_enabled();
        if (! current_user_can('escalated_role_view')) {
            wp_die(esc_html__('Permission denied.', 'escalated'), 403);
        }

        $edit = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';
        $creating = isset($_GET['action']) && $_GET['action'] === 'create';
        $roles = RoleCatalog::roles();
        $groups = RoleCatalog::grouped();
        $role = $edit !== '' ? ($roles[$edit] ?? null) : null;
        $can_manage = current_user_can('escalated_role_manage');
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        $error = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';

        include ESCALATED_PLUGIN_DIR.'templates/admin/roles.php';
    }

    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_role_action'])) {
            return;
        }
        self::require_enabled();
        if (! current_user_can('escalated_role_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'), 403);
        }

        $action = sanitize_key(wp_unslash($_POST['escalated_role_action']));
        $nonce = sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? ''));
        if (! wp_verify_nonce($nonce, 'escalated_role_'.$action)) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }

        $redirect = admin_url('admin.php?page=escalated-roles');
        $slug = isset($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '';
        $result = self::apply($action, $slug, wp_unslash($_POST));

        wp_safe_redirect(add_query_arg($result['ok'] ? ['message' => $result['message']] : ['error' => $result['error']], $redirect));
        exit;
    }

    /**
     * Save or delete a role. The caller checks escalated_role_manage.
     *
     * @return array{ok: bool, message?: string, error?: string, role?: string}
     */
    public static function apply(string $action, string $slug, array $input): array
    {
        if ($action === 'delete') {
            if (! RoleCatalog::delete($slug)) {
                return ['ok' => false, 'error' => 'system_role'];
            }
            AuditLog::record('role.deleted', 'Role', 0, null, ['role' => $slug]);

            return ['ok' => true, 'message' => 'deleted'];
        }

        $name = trim(sanitize_text_field((string) ($input['name'] ?? '')));
        if ($name === '' || strlen($name) > 255) {
            return ['ok' => false, 'error' => 'name'];
        }
        $permissions = array_map('sanitize_text_field', (array) ($input['permissions'] ?? []));
        if (array_diff($permissions, RoleCatalog::slugs()) !== []) {
            return ['ok' => false, 'error' => 'permissions'];
        }

        if ($action === 'create') {
            $slug = RoleCatalog::save(null, $name, $permissions);
            AuditLog::record('role.created', 'Role', 0, null, ['role' => $slug]);

            return ['ok' => true, 'message' => 'created', 'role' => $slug];
        }
        if ($action === 'update' && get_role($slug) !== null) {
            RoleCatalog::save($slug, $name, $permissions);
            AuditLog::record('role.updated', 'Role', 0, null, ['role' => $slug]);

            return ['ok' => true, 'message' => 'updated', 'role' => $slug];
        }

        return ['ok' => false, 'error' => 'not_found'];
    }

    /** 404 while roles & permissions is off. */
    public static function require_enabled(): void
    {
        if (! AccessFeatures::roles_permissions()) {
            wp_die(esc_html__('Not found.', 'escalated'), '', ['response' => 404]);
        }
    }
}
