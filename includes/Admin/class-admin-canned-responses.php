<?php

namespace Escalated\Admin;

use Escalated\Models\CannedResponse;

class Admin_Canned_Responses
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    /**
     * Render the canned responses admin page.
     */
    public function render(): void
    {
        $responses = CannedResponse::all();
        $edit_item = null;

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && ! empty($_GET['id'])) {
            $edit_item = CannedResponse::find(absint($_GET['id']));
        }

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

        include ESCALATED_PLUGIN_DIR.'templates/admin/canned-responses.php';
    }

    /**
     * Handle POST actions: create, update, delete.
     */
    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_canned_action'])) {
            return;
        }

        if (! current_user_can('escalated_use_canned_responses')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['escalated_canned_action']));
        $redirect = admin_url('admin.php?page=escalated-canned-responses');

        switch ($action) {
            case 'create':
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_canned_create')) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                $data = [
                    'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                    'body' => wp_kses_post(wp_unslash($_POST['body'] ?? '')),
                    'category' => sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
                    'is_shared' => isset($_POST['is_shared']) ? 1 : 0,
                    'created_by' => get_current_user_id(),
                ];

                $result = CannedResponse::create($data);
                if ($result) {
                    $redirect = add_query_arg('message', 'created', $redirect);
                } else {
                    $redirect = add_query_arg('message', 'error', $redirect);
                }
                break;

            case 'update':
                $id = absint($_POST['id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_canned_update_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                $data = [
                    'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                    'body' => wp_kses_post(wp_unslash($_POST['body'] ?? '')),
                    'category' => sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
                    'is_shared' => isset($_POST['is_shared']) ? 1 : 0,
                ];

                CannedResponse::update($id, $data);
                $redirect = add_query_arg('message', 'updated', $redirect);
                break;

            case 'delete':
                $id = absint($_POST['id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_canned_delete_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                CannedResponse::delete($id);
                $redirect = add_query_arg('message', 'deleted', $redirect);
                break;
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
