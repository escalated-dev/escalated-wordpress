<?php

/**
 * Base Controller - shared authentication and rate limiting logic.
 */

namespace Escalated\Api;

use Escalated\Models\ApiToken;
use Escalated\Models\Setting;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

abstract class Base_Controller extends WP_REST_Controller
{
    /**
     * REST API namespace.
     *
     * @var string
     */
    protected $namespace = 'escalated/v1';

    /**
     * Why the last check_token_permission() call failed: 'ability' when the
     * token is valid but may not call the route (answered 403), otherwise
     * 'token' (answered 401).
     *
     * @var string|null
     */
    protected $token_failure = null;

    /**
     * Validate a Bearer token and return the associated user ID.
     *
     * Returns null if the token is missing, invalid, expired, or may not
     * call a route of the given level.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @param  string  $level  'agent' (default) or 'admin', as escalated-laravel's
     *                         route groups; see ApiToken::allows().
     * @return int|null The authenticated user ID, or null on failure.
     */
    protected function check_token_permission(WP_REST_Request $request, string $level = 'agent'): ?int
    {
        $this->token_failure = 'token';
        $header = $request->get_header('Authorization');

        if (empty($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $plain_token = substr($header, 7);
        $token_record = ApiToken::find_by_token($plain_token);

        if (! $token_record) {
            return null;
        }

        // Check expiry.
        if (! empty($token_record->expires_at) && strtotime($token_record->expires_at) < time()) {
            return null;
        }

        // Check the token may call a route of this level.
        if (! ApiToken::allows($token_record, $level, $request->get_method(), (string) $request->get_route())) {
            $this->token_failure = 'ability';

            return null;
        }

        // Rate limiting.
        if (! $this->check_rate_limit($token_record->token)) {
            return null;
        }

        // Update last used timestamp.
        $ip = $request->get_header('X-Forwarded-For') ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        ApiToken::update_last_used($token_record->id, $ip);

        $user_id = (int) $token_record->user_id;
        if (\Escalated\Services\TicketAccess::enabled() && ! \Escalated\Services\TicketAccess::is_staff($user_id)) {
            return null;
        }
        \Escalated\Services\TicketAccess::token_actor($request, $user_id);
        $this->token_failure = null;

        return $user_id;
    }

    /**
     * The error for a failed check_token_permission(): 403 when the token is
     * valid but lacks the ability, 401 otherwise.
     */
    protected function token_error(): WP_Error
    {
        if ($this->token_failure === 'ability') {
            return $this->error('escalated_forbidden', __('Insufficient permissions.', 'escalated'), 403);
        }

        return $this->error('escalated_unauthorized', __('Invalid or expired API token.', 'escalated'), 401);
    }

    /**
     * Check whether the token has exceeded the rate limit.
     *
     * Uses WordPress transients to track request counts within a
     * one-minute sliding window.
     *
     * @param  string  $token_hash  The token string (or hash) to identify the caller.
     * @return bool True if within limits, false if rate limit exceeded.
     */
    protected function check_rate_limit(string $token_hash): bool
    {
        $key = 'escalated_rate_'.substr($token_hash, 0, 16);
        $limit = (int) Setting::get('api_rate_limit', 60);
        $current = (int) get_transient($key);

        if ($current >= $limit) {
            return false;
        }

        set_transient($key, $current + 1, MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * Permission callback for agent routes: a valid Bearer token with the
     * `agent` (or `*`) ability.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return bool|WP_Error True if authorized, WP_Error otherwise.
     */
    public function token_permissions_check(WP_REST_Request $request)
    {
        return $this->check_token_permission($request) === null ? $this->token_error() : true;
    }

    /**
     * Permission callback for admin routes: a valid Bearer token with the
     * `admin` (or `*`) ability. An agent-only token is refused, as is an
     * admin-only token on the agent routes.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return bool|WP_Error True if authorized, WP_Error otherwise.
     */
    public function admin_token_permissions_check(WP_REST_Request $request)
    {
        return $this->check_token_permission($request, 'admin') === null ? $this->token_error() : true;
    }

    /**
     * Return standardised error response.
     *
     * @param  string  $code  Error code.
     * @param  string  $message  Human-readable message.
     * @param  int  $status  HTTP status code.
     */
    protected function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    /**
     * Return standardised success response.
     *
     * @param  mixed  $data  Response data.
     * @param  int  $status  HTTP status code.
     */
    protected function success($data = null, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response($data, $status);
    }
}
