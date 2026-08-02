<?php

/**
 * Two-Factor Controller - self-service TOTP enrollment and verification.
 *
 * Every route acts on the user behind the authenticating Bearer token, so a
 * user can only ever manage their own two-factor settings (no admin
 * capability is required — mirrors the Laravel reference, where the
 * controller operates on $request->user()).
 */

namespace Escalated\Api;

use Escalated\Models\AuditLog;
use Escalated\Models\TwoFactor;
use Escalated\Services\TwoFactorService;
use WP_REST_Request;
use WP_REST_Server;

class Two_Factor_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'admin/two-factor';

    /**
     * User ID resolved by the permission callback for the current request.
     *
     * @var int|null
     */
    protected $current_user_id = null;

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        // Status: is 2FA enabled / pending for the current user?
        register_rest_route($this->namespace, '/'.$this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'status'],
                'permission_callback' => [$this, 'authenticated_check'],
            ],
            // Disable / remove 2FA.
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'disable'],
                'permission_callback' => [$this, 'authenticated_check'],
            ],
        ]);

        // Begin enrollment: generate secret + recovery codes.
        register_rest_route($this->namespace, '/'.$this->rest_base.'/setup', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'setup'],
                'permission_callback' => [$this, 'authenticated_check'],
            ],
        ]);

        // Confirm enrollment with the first code.
        register_rest_route($this->namespace, '/'.$this->rest_base.'/confirm', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'confirm'],
                'permission_callback' => [$this, 'authenticated_check'],
                'args' => [
                    'code' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // Challenge: verify a TOTP or recovery code.
        register_rest_route($this->namespace, '/'.$this->rest_base.'/verify', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'verify'],
                'permission_callback' => [$this, 'authenticated_check'],
                'args' => [
                    'code' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // Regenerate the recovery-code set.
        register_rest_route($this->namespace, '/'.$this->rest_base.'/recovery-codes', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'regenerate_recovery_codes'],
                'permission_callback' => [$this, 'authenticated_check'],
            ],
        ]);
    }

    /**
     * Permission callback: require a valid Bearer token; remember its user.
     *
     * @return bool|\WP_Error
     */
    public function authenticated_check(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request);

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Invalid or expired API token.', 'escalated'), 401);
        }

        $this->current_user_id = $user_id;

        return true;
    }

    /**
     * Report whether 2FA is enabled or pending for the current user.
     *
     * @param  WP_REST_Request  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function status($request)
    {
        $user_id = $this->resolve_user_id($request);
        $record = TwoFactor::for_user($user_id);

        return $this->success([
            'enabled' => TwoFactor::is_confirmed($record),
            'pending' => $record !== null && ! TwoFactor::is_confirmed($record),
            'recovery_codes_remaining' => $record && TwoFactor::is_confirmed($record)
                ? TwoFactor::remaining_recovery_codes($record)
                : 0,
        ]);
    }

    /**
     * Begin enrollment. Generates and stores a fresh secret + recovery
     * codes (unconfirmed) and returns the otpauth URI, the raw secret, and
     * the plain recovery codes — shown to the user exactly once.
     *
     * @param  WP_REST_Request  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function setup($request)
    {
        $user_id = $this->resolve_user_id($request);

        if (TwoFactor::confirmed_for_user($user_id)) {
            return $this->error(
                'escalated_two_factor_already_enabled',
                __('Two-factor authentication is already enabled. Disable it first to re-enroll.', 'escalated'),
                409
            );
        }

        // Clear any half-finished enrollment.
        TwoFactor::delete_pending_for_user($user_id);

        $service = new TwoFactorService;
        $secret = $service->generate_secret();
        $recovery_codes = $service->generate_recovery_codes();

        $id = TwoFactor::create([
            'user_id' => $user_id,
            'secret' => $secret,
            'recovery_codes' => $recovery_codes,
        ]);

        if ($id === false) {
            return $this->error('escalated_two_factor_setup_failed', __('Failed to start two-factor setup.', 'escalated'), 500);
        }

        $user = get_userdata($user_id);
        $email = $user ? $user->user_email : '';

        return $this->success([
            'secret' => $secret,
            'qr_uri' => $service->generate_qr_uri($secret, $email),
            'recovery_codes' => $recovery_codes,
        ], 201);
    }

    /**
     * Confirm enrollment by verifying the first TOTP code.
     *
     * @param  WP_REST_Request  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function confirm($request)
    {
        $user_id = $this->resolve_user_id($request);
        $code = trim((string) $request->get_param('code'));

        if (! preg_match('/^\d{6}$/', $code)) {
            return $this->error('escalated_invalid_code', __('The code must be six digits.', 'escalated'), 422);
        }

        $record = TwoFactor::pending_for_user($user_id);

        if (! $record) {
            return $this->error('escalated_no_pending_setup', __('No pending two-factor setup found.', 'escalated'), 422);
        }

        $service = new TwoFactorService;

        if (! $service->verify(TwoFactor::secret_of($record), $code)) {
            return $this->error('escalated_invalid_code', __('Invalid verification code.', 'escalated'), 422);
        }

        TwoFactor::confirm($record->id);

        AuditLog::record('two_factor.enabled', 'User', $user_id, null, null, $user_id);

        return $this->success([
            'message' => __('Two-factor authentication enabled.', 'escalated'),
            'enabled' => true,
        ]);
    }

    /**
     * Challenge verification: accept a valid TOTP code OR a single-use
     * recovery code for a user who has 2FA enabled.
     *
     * @param  WP_REST_Request  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function verify($request)
    {
        $user_id = $this->resolve_user_id($request);
        $code = trim((string) $request->get_param('code'));

        $record = TwoFactor::confirmed_for_user($user_id);

        if (! $record) {
            return $this->error('escalated_two_factor_not_enabled', __('Two-factor authentication is not enabled.', 'escalated'), 422);
        }

        $service = new TwoFactorService;

        // Try TOTP first.
        if ($service->verify(TwoFactor::secret_of($record), $code)) {
            return $this->success([
                'verified' => true,
                'method' => 'totp',
            ]);
        }

        // Fall back to a single-use recovery code.
        if (TwoFactor::consume_recovery_code($record->id, $code)) {
            $remaining = TwoFactor::remaining_recovery_codes(TwoFactor::find($record->id));

            return $this->success([
                'verified' => true,
                'method' => 'recovery_code',
                'recovery_codes_remaining' => $remaining,
            ]);
        }

        return $this->error('escalated_invalid_code', __('Invalid verification code.', 'escalated'), 422);
    }

    /**
     * Regenerate the recovery-code set for an enabled user. Returns the new
     * plain codes once; the previous set is invalidated.
     *
     * @param  WP_REST_Request  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function regenerate_recovery_codes($request)
    {
        $user_id = $this->resolve_user_id($request);
        $record = TwoFactor::confirmed_for_user($user_id);

        if (! $record) {
            return $this->error('escalated_two_factor_not_enabled', __('Two-factor authentication is not enabled.', 'escalated'), 422);
        }

        $service = new TwoFactorService;
        $recovery_codes = $service->generate_recovery_codes();

        TwoFactor::update($record->id, ['recovery_codes' => $recovery_codes]);

        return $this->success([
            'recovery_codes' => $recovery_codes,
        ]);
    }

    /**
     * Disable and remove 2FA for the current user.
     *
     * @param  WP_REST_Request  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function disable($request)
    {
        $user_id = $this->resolve_user_id($request);

        $had_record = TwoFactor::for_user($user_id) !== null;

        TwoFactor::delete_for_user($user_id);

        if ($had_record) {
            AuditLog::record('two_factor.disabled', 'User', $user_id, null, null, $user_id);
        }

        return $this->success([
            'message' => __('Two-factor authentication disabled.', 'escalated'),
        ]);
    }

    /**
     * Resolve the acting user ID, preferring the value cached by the
     * permission callback and falling back to a fresh token lookup.
     */
    protected function resolve_user_id(WP_REST_Request $request): int
    {
        if ($this->current_user_id !== null) {
            return (int) $this->current_user_id;
        }

        return (int) $this->check_token_permission($request);
    }
}
