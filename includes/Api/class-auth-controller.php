<?php

/**
 * Auth Controller — general JSON API auth for the Flutter app.
 *
 * WordPress owns its users, so authentication is WP-native: login verifies
 * credentials with wp_authenticate() and issues an Escalated API token; the
 * token-authenticated endpoints reuse the Bearer-token machinery in
 * Base_Controller. Mirrors the /auth contract of the other backends.
 *
 * The token a login or registration issues carries the user's Escalated
 * role: `*` for Escalated admins, `agent` for other staff, and `customer`
 * for everyone else. A customer token only reaches the routes about its own
 * account (me, profile, refresh, logout); the staff routes refuse it.
 */

namespace Escalated\Api;

use Escalated\Models\ApiToken;
use Escalated\Services\TicketAccess;
use WP_REST_Request;
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
        $public = '__return_true';
        $staff = [$this, 'token_permissions_check'];
        $own = [$this, 'self_token_permissions_check'];

        $routes = [
            ['validate', WP_REST_Server::CREATABLE, 'validate_token', $staff],
            ['login', WP_REST_Server::CREATABLE, 'login', $public],
            ['register', WP_REST_Server::CREATABLE, 'register', $public],
            ['logout', WP_REST_Server::CREATABLE, 'logout', $own],
            ['refresh', WP_REST_Server::CREATABLE, 'refresh', $own],
            ['me', WP_REST_Server::READABLE, 'me', $own],
            ['profile', WP_REST_Server::EDITABLE, 'update_profile', $own],
        ];

        foreach ($routes as [$path, $methods, $callback, $permission]) {
            register_rest_route(
                $this->namespace,
                '/'.$this->rest_base.'/'.$path,
                [
                    [
                        'methods' => $methods,
                        'callback' => [$this, $callback],
                        'permission_callback' => $permission,
                        'args' => [],
                    ],
                ]
            );
        }
    }

    /**
     * Validate the provided Bearer token and return user information.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
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
            'user' => $this->user_payload($user),
        ]);
    }

    /**
     * Authenticate credentials and issue an API token.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function login(WP_REST_Request $request)
    {
        $username = (string) ($request->get_param('username') ?: $request->get_param('email'));
        $password = (string) $request->get_param('password');

        if ($username === '' || $password === '') {
            return $this->error('escalated_missing_credentials', __('Username and password are required.', 'escalated'), 422);
        }

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return $this->error('escalated_invalid_credentials', __('Invalid credentials.', 'escalated'), 401);
        }

        return $this->success($this->issue_token_response($user));
    }

    /**
     * Register a new account (only when WordPress registration is open).
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function register(WP_REST_Request $request)
    {
        if (! get_option('users_can_register')) {
            return $this->error('escalated_registration_disabled', __('Registration is disabled.', 'escalated'), 403);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        $username = (string) ($request->get_param('username') ?: $email);
        $password = (string) $request->get_param('password');

        if (! is_email($email) || $password === '') {
            return $this->error('escalated_invalid_registration', __('A valid email and password are required.', 'escalated'), 422);
        }

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            return $this->error('escalated_registration_failed', $user_id->get_error_message(), 422);
        }

        return $this->success($this->issue_token_response(get_userdata($user_id)), 201);
    }

    /**
     * Return the authenticated user.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response
     */
    public function me(WP_REST_Request $request)
    {
        $user = get_userdata($this->check_token_permission($request, 'self'));

        return $this->success(['user' => $this->user_payload($user)]);
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function update_profile(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'self');
        $args = ['ID' => $user_id];

        if ($request->get_param('display_name') !== null) {
            $args['display_name'] = sanitize_text_field((string) $request->get_param('display_name'));
        }
        if ($request->get_param('email') !== null) {
            $email = sanitize_email((string) $request->get_param('email'));
            if (! is_email($email)) {
                return $this->error('escalated_invalid_email', __('Invalid email address.', 'escalated'), 422);
            }
            $args['user_email'] = $email;
        }

        $result = wp_update_user($args);

        if (is_wp_error($result)) {
            return $this->error('escalated_profile_update_failed', $result->get_error_message(), 422);
        }

        return $this->success(['user' => $this->user_payload(get_userdata($user_id))]);
    }

    /**
     * Revoke the current token.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response
     */
    public function logout(WP_REST_Request $request)
    {
        $this->revoke_request_token($request);

        return $this->success(['success' => true]);
    }

    /**
     * Issue a fresh token and revoke the current one. The new token keeps
     * the current one's name, abilities and expiry.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function refresh(WP_REST_Request $request)
    {
        $user = get_userdata($this->check_token_permission($request, 'self'));
        $current = $this->request_token($request);

        if (! $user || ! $current) {
            return $this->token_error();
        }

        $abilities = json_decode((string) $current->abilities, true);
        $created = ApiToken::create_token($user->ID, (string) $current->name, is_array($abilities) ? $abilities : []);

        if ($created && ! empty($current->expires_at)) {
            ApiToken::update($created['record']->id, ['expires_at' => $current->expires_at]);
        }

        ApiToken::delete($current->id);

        return $this->success([
            'token' => $created ? $created['token'] : null,
            'user' => $this->user_payload($user),
        ]);
    }

    /**
     * The abilities a password login gives a user: `*` for Escalated
     * admins, `agent` for other staff, `customer` for everyone else.
     *
     * @param  \WP_User  $user
     */
    private function login_abilities($user): array
    {
        if (TicketAccess::is_admin((int) $user->ID)) {
            return ['*'];
        }

        if (TicketAccess::is_staff((int) $user->ID)) {
            return ['agent'];
        }

        return ['customer'];
    }

    /**
     * Issue an API token for a user and build the token+user response.
     *
     * @param  \WP_User  $user
     */
    private function issue_token_response($user): array
    {
        $created = ApiToken::create_token($user->ID, 'flutter-app', $this->login_abilities($user));

        return [
            'token' => $created ? $created['token'] : null,
            'user' => $this->user_payload($user),
        ];
    }

    /**
     * Serialize a user for API responses.
     *
     * @param  \WP_User  $user
     */
    private function user_payload($user): array
    {
        return [
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'roles' => $user->roles,
        ];
    }

    /**
     * Revoke the token carried by the request's Authorization header.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     */
    private function revoke_request_token(WP_REST_Request $request): void
    {
        $record = $this->request_token($request);

        if ($record) {
            ApiToken::delete($record->id);
        }
    }

    /**
     * The token record carried by the request's Authorization header.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return object|null
     */
    private function request_token(WP_REST_Request $request)
    {
        $header = (string) $request->get_header('Authorization');

        if ($header === '' || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return ApiToken::find_by_token(substr($header, 7));
    }
}
