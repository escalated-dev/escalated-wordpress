<?php

/**
 * API Token Controller - admin-only CRUD for API tokens.
 */

namespace Escalated\Api;

use Escalated\Models\ApiToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Api_Token_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'admin/api-tokens';

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        // List all tokens (masked).
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_items'],
                    'permission_callback' => [$this, 'admin_permissions_check'],
                    'args' => [],
                ],
            ]
        );

        // Create a new token.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_item'],
                    'permission_callback' => [$this, 'admin_permissions_check'],
                    'args' => [
                        'name' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'user_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'abilities' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'default' => ['*'],
                        ],
                        'expires_at' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // Revoke (delete) a token.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_item'],
                    'permission_callback' => [$this, 'admin_permissions_check'],
                    'args' => [
                        'id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check for admin-only endpoints.
     *
     * Validates the Bearer token and checks that the associated user
     * has the escalated_api_token_manage capability.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return bool|\WP_Error True if authorized, WP_Error otherwise.
     */
    public function admin_permissions_check(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request);

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Invalid or expired API token.', 'escalated'), 401);
        }

        $user = get_userdata($user_id);

        if (! $user || ! $user->has_cap('escalated_api_token_manage')) {
            return $this->error(
                'escalated_forbidden',
                __('You do not have permission to manage API tokens.', 'escalated'),
                403
            );
        }

        return true;
    }

    /**
     * List all API tokens with masked token values.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items($request)
    {
        global $wpdb;

        $table = \Escalated\Escalated::table('api_tokens');
        $tokens = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC") ?: [];

        $result = [];
        foreach ($tokens as $token) {
            // Mask the token: show first 8 characters, mask the rest.
            $masked = substr($token->token, 0, 8).str_repeat('*', max(0, strlen($token->token) - 8));

            // Parse abilities.
            $abilities = $token->abilities;
            if (is_string($abilities)) {
                $decoded = json_decode($abilities, true);
                $abilities = is_array($decoded) ? $decoded : ['*'];
            }

            // Resolve user info.
            $user = get_userdata((int) $token->user_id);
            $user_info = $user ? [
                'id' => $user->ID,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
            ] : null;

            $result[] = [
                'id' => (int) $token->id,
                'name' => $token->name,
                'token_masked' => $masked,
                'user_id' => (int) $token->user_id,
                'user' => $user_info,
                'abilities' => $abilities,
                'last_used_at' => $token->last_used_at,
                'last_used_ip' => $token->last_used_ip,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ];
        }

        return $this->success([
            'tokens' => $result,
        ]);
    }

    /**
     * Create a new API token.
     *
     * Returns the plain-text token value exactly once. It is
     * never shown again.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function create_item($request)
    {
        $name = sanitize_text_field($request->get_param('name'));
        $user_id = absint($request->get_param('user_id'));

        if (empty($name)) {
            return $this->error('escalated_missing_name', __('Token name is required.', 'escalated'), 422);
        }

        // Validate user exists.
        $user = get_userdata($user_id);
        if (! $user) {
            return $this->error('escalated_invalid_user', __('User not found.', 'escalated'), 404);
        }

        // Parse abilities.
        $abilities = $request->get_param('abilities') ?: ['*'];
        if (! is_array($abilities)) {
            $abilities = ['*'];
        }
        $abilities = array_map('sanitize_text_field', $abilities);

        // Parse optional expiry.
        $expires_at = null;
        if ($request->has_param('expires_at')) {
            $expires_input = sanitize_text_field($request->get_param('expires_at'));
            if (! empty($expires_input)) {
                $timestamp = strtotime($expires_input);
                if ($timestamp === false || $timestamp <= time()) {
                    return $this->error('escalated_invalid_expiry', __('Expiry date must be in the future.', 'escalated'), 422);
                }
                $expires_at = gmdate('Y-m-d H:i:s', $timestamp);
            }
        }

        $result = ApiToken::create_token($user_id, $name, $abilities);

        if ($result === false) {
            return $this->error('escalated_create_failed', __('Failed to create API token.', 'escalated'), 500);
        }

        if ($expires_at !== null) {
            ApiToken::update($result['record']->id, ['expires_at' => $expires_at]);
        }

        $token = ApiToken::find($result['record']->id);

        return $this->success([
            'message' => __('API token created successfully. Store this token securely - it will not be shown again.', 'escalated'),
            'token' => [
                'id' => (int) $token->id,
                'name' => $name,
                'plain_token' => $result['token'],
                'user_id' => $user_id,
                'abilities' => $abilities,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ],
        ], 201);
    }

    /**
     * Revoke (delete) an API token.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function delete_item($request)
    {
        global $wpdb;

        $token_id = absint($request->get_param('id'));
        $table = \Escalated\Escalated::table('api_tokens');

        // Verify the token exists.
        $token = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $token_id)
        );

        if (! $token) {
            return $this->error('escalated_not_found', __('API token not found.', 'escalated'), 404);
        }

        $deleted = $wpdb->delete($table, ['id' => $token_id]);

        if ($deleted === false) {
            return $this->error('escalated_delete_failed', __('Failed to revoke API token.', 'escalated'), 500);
        }

        return $this->success([
            'message' => __('API token revoked successfully.', 'escalated'),
        ]);
    }
}
