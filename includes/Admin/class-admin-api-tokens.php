<?php

namespace Escalated\Admin;

use Escalated\Models\ApiToken;

class Admin_Api_Tokens
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    /**
     * Render the API tokens admin page.
     */
    public function render(): void
    {
        $tokens = ApiToken::all();
        $plain_token = null;
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

        // Check transient for newly created plain token.
        $token_transient = get_transient('escalated_new_token_'.get_current_user_id());
        if ($token_transient) {
            $plain_token = $token_transient;
            delete_transient('escalated_new_token_'.get_current_user_id());
        }

        include ESCALATED_PLUGIN_DIR.'templates/admin/api-tokens.php';
    }

    /**
     * Handle POST actions: create, delete.
     */
    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_token_action'])) {
            return;
        }

        if (! current_user_can('escalated_manage_api_tokens')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['escalated_token_action']));
        $redirect = admin_url('admin.php?page=escalated-api-tokens');

        switch ($action) {
            case 'create':
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_token_create')) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
                $abilities = isset($_POST['abilities']) ? array_map('sanitize_text_field', (array) $_POST['abilities']) : ['*'];

                $expires_at = null;
                if (! empty($_POST['expires_at'])) {
                    $expires_at = sanitize_text_field(wp_unslash($_POST['expires_at']));
                }

                $result = ApiToken::create_token(get_current_user_id(), $name, $abilities);

                if ($result) {
                    // Store plain token temporarily so it can be displayed once.
                    set_transient('escalated_new_token_'.get_current_user_id(), $result['token'], 60);

                    if ($expires_at) {
                        ApiToken::update($result['record']->id, ['expires_at' => $expires_at]);
                    }

                    $redirect = add_query_arg('message', 'created', $redirect);
                } else {
                    $redirect = add_query_arg('message', 'error', $redirect);
                }
                break;

            case 'delete':
                $id = absint($_POST['id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_token_delete_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }

                ApiToken::delete($id);
                $redirect = add_query_arg('message', 'deleted', $redirect);
                break;
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
