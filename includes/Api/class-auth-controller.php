<?php

/**
 * Auth Controller - token validation endpoint.
 */

namespace Escalated\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Auth_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'auth';

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/validate',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'validate_token'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [],
                ],
            ]
        );
    }

    /**
     * Validate the provided Bearer token and return user information.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function validate_token(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request);

        if ($user_id === null) {
            return $this->error('escalated_invalid_token', __('Invalid or expired API token.', 'escalated'), 401);
        }

        $user = get_userdata($user_id);

        if (! $user) {
            return $this->error('escalated_user_not_found', __('User associated with this token no longer exists.', 'escalated'), 404);
        }

        return $this->success([
            'valid' => true,
            'user' => [
                'id' => $user->ID,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'roles' => $user->roles,
            ],
        ]);
    }
}
