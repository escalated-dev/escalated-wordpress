<?php

namespace Escalated\Admin;

use Escalated\Models\AuditLog;
use Escalated\Models\Setting;
use Escalated\Models\TwoFactor;
use Escalated\Services\TicketAccess;
use Escalated\Services\TwoFactorService;
use Escalated\Support\AccessFeatures;
use Escalated\Support\TwoFactorGate;

/**
 * The two-factor screens, only while two-factor is switched on
 * (escalated-laravel's Admin\TwoFactorController and
 * TwoFactorChallengeController, as native wp-admin screens):
 *
 * - Escalated > Two-Factor: enrolment for every staff user (agents too):
 *   setup shows the otpauth:// URI and eight recovery codes once, confirm
 *   takes a 6-digit code, disable removes the enrolment. Admins with
 *   settings.manage can require two-factor for all staff.
 * - the challenge (a page outside the menu): a TOTP code or a recovery code,
 *   five attempts a minute, after which the user returns to the page they
 *   asked for.
 *
 * Both answer 404 while two-factor is off.
 */
class Admin_Two_Factor
{
    private const SETUP_TRANSIENT = 'escalated_2fa_setup_';

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions'], 5);
    }

    public function render(): void
    {
        self::require_enabled();
        $user_id = get_current_user_id();
        if (! TicketAccess::is_staff($user_id)) {
            wp_die(esc_html__('Permission denied.', 'escalated'), 403);
        }

        $record = TwoFactor::for_user($user_id);
        $enabled = TwoFactor::is_confirmed($record);
        $pending = $record !== null && ! $enabled;
        $required = TwoFactorGate::required();
        $can_require = current_user_can('escalated_settings_manage');
        // The secret and codes are shown once, then forgotten.
        $setup = get_transient(self::SETUP_TRANSIENT.$user_id);
        delete_transient(self::SETUP_TRANSIENT.$user_id);
        $setup = is_array($setup) ? $setup : null;
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        $error = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';

        if ($setup !== null) {
            nocache_headers();
        }
        include ESCALATED_PLUGIN_DIR.'templates/admin/two-factor.php';
    }

    public function render_challenge(): void
    {
        self::require_enabled();
        $action = admin_url('admin.php?page='.TwoFactorGate::CHALLENGE_PAGE);
        $error = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';

        include ESCALATED_PLUGIN_DIR.'templates/admin/two-factor-challenge.php';
    }

    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_two_factor_action'])) {
            return;
        }
        self::require_enabled();
        $action = sanitize_key(wp_unslash($_POST['escalated_two_factor_action']));
        $nonce = sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? ''));
        if (! wp_verify_nonce($nonce, 'escalated_two_factor_'.$action)) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            wp_die(esc_html__('Permission denied.', 'escalated'), 403);
        }

        if ($action === 'verify') {
            wp_safe_redirect(self::verify($user_id, wp_unslash($_POST)));
            exit;
        }

        if (! TicketAccess::is_staff($user_id)) {
            wp_die(esc_html__('Permission denied.', 'escalated'), 403);
        }
        $result = self::apply($action, $user_id, wp_unslash($_POST));
        $page = admin_url('admin.php?page='.TwoFactorGate::ENROL_PAGE);
        wp_safe_redirect(add_query_arg($result['ok'] ? ['message' => $result['message']] : ['error' => $result['error']], $page));
        exit;
    }

    /**
     * Enrolment actions for the signed-in staff user.
     *
     * @return array{ok: bool, message?: string, error?: string}
     */
    public static function apply(string $action, int $user_id, array $input): array
    {
        $service = new TwoFactorService;

        switch ($action) {
            case 'setup':
                if (TwoFactor::confirmed_for_user($user_id)) {
                    return ['ok' => false, 'error' => 'already_enabled'];
                }
                TwoFactor::delete_pending_for_user($user_id);
                $secret = $service->generate_secret();
                $codes = $service->generate_recovery_codes();
                TwoFactor::create(['user_id' => $user_id, 'secret' => $secret, 'recovery_codes' => $codes]);
                $user = get_userdata($user_id);
                set_transient(self::SETUP_TRANSIENT.$user_id, [
                    'qr_uri' => $service->generate_qr_uri($secret, $user ? $user->user_email : (string) $user_id),
                    'recovery_codes' => $codes,
                ], 10 * MINUTE_IN_SECONDS);

                return ['ok' => true, 'message' => 'setup'];

            case 'confirm':
                $code = sanitize_text_field((string) ($input['code'] ?? ''));
                if (strlen($code) !== 6) {
                    return ['ok' => false, 'error' => 'code'];
                }
                $record = TwoFactor::pending_for_user($user_id);
                if (! $record) {
                    return ['ok' => false, 'error' => 'no_pending'];
                }
                if (! $service->verify_and_consume($record, $code)) {
                    return ['ok' => false, 'error' => 'code'];
                }
                TwoFactor::confirm($record->id);
                // The code just entered proves the second factor for this session.
                TwoFactorGate::mark_passed($user_id);
                AuditLog::record('two_factor.enabled', 'User', $user_id);

                return ['ok' => true, 'message' => 'enabled'];

            case 'disable':
                TwoFactor::delete_for_user($user_id);
                AuditLog::record('two_factor.disabled', 'User', $user_id);

                return ['ok' => true, 'message' => 'disabled'];

            case 'require':
                if (! user_can($user_id, 'escalated_settings_manage')) {
                    return ['ok' => false, 'error' => 'forbidden'];
                }
                $value = in_array(strtolower((string) ($input['two_factor_required'] ?? '')), ['1', 'true', 'on', 'yes'], true);
                Setting::set('two_factor_required', $value ? '1' : '0');

                return ['ok' => true, 'message' => 'saved'];
        }

        return ['ok' => false, 'error' => 'unknown'];
    }

    /**
     * The challenge: a TOTP code or a recovery code. Returns where to go.
     */
    public static function verify(int $user_id, array $input): string
    {
        $back = admin_url('admin.php?page='.TwoFactorGate::CHALLENGE_PAGE);
        $record = TwoFactor::confirmed_for_user($user_id);
        if (! $record) {
            return TwoFactorGate::take_intended($user_id);
        }
        if (! TwoFactorGate::allow_attempt($user_id)) {
            return add_query_arg('error', 'throttled', $back);
        }

        $recovery = trim(sanitize_text_field((string) ($input['recovery_code'] ?? '')));
        $code = trim(sanitize_text_field((string) ($input['code'] ?? '')));
        if ($recovery === '' && $code === '') {
            return add_query_arg('error', 'code', $back);
        }

        $passed = $recovery !== ''
            ? TwoFactor::consume_recovery_code($record->id, strtoupper($recovery))
            : (new TwoFactorService)->verify_and_consume($record, $code);

        if (! $passed) {
            AuditLog::record('two_factor.challenge_failed', 'User', $user_id);

            return add_query_arg('error', $recovery !== '' ? 'recovery_code' : 'code', $back);
        }

        TwoFactorGate::mark_passed($user_id);

        return TwoFactorGate::take_intended($user_id);
    }

    /** 404 while two-factor is off. */
    public static function require_enabled(): void
    {
        if (! AccessFeatures::two_factor()) {
            wp_die(esc_html__('Not found.', 'escalated'), '', ['response' => 404]);
        }
    }
}
