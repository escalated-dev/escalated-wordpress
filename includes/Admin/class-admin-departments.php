<?php

namespace Escalated\Admin;

use Escalated\Models\Department;

class Admin_Departments
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    /**
     * Render the departments admin page.
     */
    public function render(): void
    {
        $departments = Department::all();
        $edit_item = null;

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && ! empty($_GET['id'])) {
            $edit_item = Department::find(absint($_GET['id']));
        }

        // Get agent counts per department.
        $agent_counts = [];
        foreach ($departments as $dept) {
            $agent_counts[$dept->id] = count(Department::agents($dept->id));
        }

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

        include ESCALATED_PLUGIN_DIR.'templates/admin/departments.php';
    }

    /**
     * Handle POST actions: create, update, delete.
     */
    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_department_action'])) {
            return;
        }

        if (! current_user_can('escalated_manage_departments')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['escalated_department_action']));
        $redirect = admin_url('admin.php?page=escalated-departments');

        switch ($action) {
            case 'create':
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_department_create')) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                $data = [
                    'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                    'slug' => sanitize_title(wp_unslash($_POST['slug'] ?? $_POST['name'] ?? '')),
                    'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];

                $result = Department::create($data);
                if ($result) {
                    $redirect = add_query_arg('message', 'created', $redirect);
                } else {
                    $redirect = add_query_arg('message', 'error', $redirect);
                }
                break;

            case 'update':
                $id = absint($_POST['id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_department_update_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                $data = [
                    'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                    'slug' => sanitize_title(wp_unslash($_POST['slug'] ?? '')),
                    'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];

                Department::update($id, $data);
                $redirect = add_query_arg('message', 'updated', $redirect);
                break;

            case 'delete':
                $id = absint($_POST['id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_department_delete_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                Department::delete($id);
                $redirect = add_query_arg('message', 'deleted', $redirect);
                break;
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
