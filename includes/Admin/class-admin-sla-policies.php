<?php

namespace Escalated\Admin;

use Escalated\Helpers\Enums;
use Escalated\Models\SlaPolicy;

class Admin_Sla_Policies
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    /**
     * Render the SLA policies admin page.
     */
    public function render(): void
    {
        $policies = SlaPolicy::all();
        $edit_item = null;

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && ! empty($_GET['id'])) {
            $edit_item = SlaPolicy::find(absint($_GET['id']));
        }

        $priorities = Enums::ticket_priorities();
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

        include ESCALATED_PLUGIN_DIR.'templates/admin/sla-policies.php';
    }

    /**
     * Handle POST actions: create, update, delete.
     */
    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_sla_action'])) {
            return;
        }

        if (! current_user_can('escalated_sla_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['escalated_sla_action']));
        $redirect = admin_url('admin.php?page=escalated-sla-policies');

        switch ($action) {
            case 'create':
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_sla_create')) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                $first_response = [];
                $resolution = [];

                if (isset($_POST['first_response_hours']) && is_array($_POST['first_response_hours'])) {
                    foreach ($_POST['first_response_hours'] as $priority => $hours) {
                        $first_response[sanitize_text_field($priority)] = absint($hours);
                    }
                }

                if (isset($_POST['resolution_hours']) && is_array($_POST['resolution_hours'])) {
                    foreach ($_POST['resolution_hours'] as $priority => $hours) {
                        $resolution[sanitize_text_field($priority)] = absint($hours);
                    }
                }

                $data = [
                    'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                    'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
                    'is_default' => isset($_POST['is_default']) ? 1 : 0,
                    'first_response_hours' => $first_response,
                    'resolution_hours' => $resolution,
                    'business_hours_only' => isset($_POST['business_hours_only']) ? 1 : 0,
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];

                $result = SlaPolicy::create($data);
                if ($result) {
                    $redirect = add_query_arg('message', 'created', $redirect);
                } else {
                    $redirect = add_query_arg('message', 'error', $redirect);
                }
                break;

            case 'update':
                $id = absint($_POST['id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_sla_update_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                $first_response = [];
                $resolution = [];

                if (isset($_POST['first_response_hours']) && is_array($_POST['first_response_hours'])) {
                    foreach ($_POST['first_response_hours'] as $priority => $hours) {
                        $first_response[sanitize_text_field($priority)] = absint($hours);
                    }
                }

                if (isset($_POST['resolution_hours']) && is_array($_POST['resolution_hours'])) {
                    foreach ($_POST['resolution_hours'] as $priority => $hours) {
                        $resolution[sanitize_text_field($priority)] = absint($hours);
                    }
                }

                $data = [
                    'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                    'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
                    'is_default' => isset($_POST['is_default']) ? 1 : 0,
                    'first_response_hours' => $first_response,
                    'resolution_hours' => $resolution,
                    'business_hours_only' => isset($_POST['business_hours_only']) ? 1 : 0,
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];

                SlaPolicy::update($id, $data);
                $redirect = add_query_arg('message', 'updated', $redirect);
                break;

            case 'delete':
                $id = absint($_POST['id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_sla_delete_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                SlaPolicy::delete($id);
                $redirect = add_query_arg('message', 'deleted', $redirect);
                break;
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
