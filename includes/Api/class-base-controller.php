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
     * Validate a Bearer token and return the associated user ID.
     *
     * Returns null if the token is missing, invalid, expired, or lacks
     * the requested ability.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @param  string  $ability  The ability to check. Default '*' (all).
     * @return int|null The authenticated user ID, or null on failure.
     */
    protected function check_token_permission(WP_REST_Request $request, string $ability = '*'): ?int
    {
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

        // Check ability.
        if (! ApiToken::has_ability($token_record, $ability)) {
            return null;
        }

        // Rate limiting.
        if (! $this->check_rate_limit($token_record->token)) {
            return null;
        }

        // Update last used timestamp.
        $ip = $request->get_header('X-Forwarded-For') ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        ApiToken::update_last_used($token_record->id, $ip);

        return (int) $token_record->user_id;
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
     * Standard permission callback that validates Bearer token auth.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return bool|WP_Error True if authorized, WP_Error otherwise.
     */
    public function token_permissions_check(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request);

        if ($user_id === null) {
            return new WP_Error(
                'escalated_unauthorized',
                __('Invalid or expired API token.', 'escalated'),
                ['status' => 401]
            );
        }

        return true;
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
