<?php

namespace Escalated\Support;

use Escalated\Models\Setting;
use Escalated\Models\TwoFactor;
use Escalated\Services\TicketAccess;
use WP_Error;

/**
 * Two-factor enforcement (ADR 2026-10-29-configurable-access-features,
 * escalated-laravel's RequireTwoFactor). While two-factor is on:
 *
 * - a user with a confirmed enrolment who has not passed the challenge in
 *   this login session is sent to it from every Escalated wp-admin screen,
 *   and refused (403, two_factor_required) on the staff REST routes they
 *   reach with their login cookie;
 * - when two-factor is required, a staff user without an enrolment is sent
 *   to enrol and can use nothing else.
 *
 * The pass is kept in the user's WordPress login session
 * (WP_Session_Tokens), so a new login is challenged again and signing out
 * ends it. API token requests are not challenged.
 */
final class TwoFactorGate
{
    public const CHALLENGE_PAGE = 'escalated-two-factor-challenge';

    public const ENROL_PAGE = 'escalated-two-factor';

    private const SESSION_KEY = 'escalated_two_factor_passed';

    private const INTENDED_KEY = 'escalated_two_factor_intended';

    public const ATTEMPTS_PER_MINUTE = 5;

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'guard_admin'], 1);
        add_filter('rest_dispatch_request', [self::class, 'guard_rest'], 4, 4);
    }

    /**
     * Whether staff must enrol: the `two_factor_required` setting when an
     * admin has saved it, else ESCALATED_TWO_FACTOR_REQUIRED. Only while
     * two-factor is on.
     */
    public static function required(): bool
    {
        if (! AccessFeatures::two_factor()) {
            return false;
        }
        $setting = Setting::get('two_factor_required');
        if ($setting !== null && $setting !== '') {
            return in_array(strtolower((string) $setting), ['1', 'true', 'on', 'yes'], true);
        }

        return AccessFeatures::two_factor_required_default();
    }

    /** Whether this login session has passed the challenge for the user. */
    public static function passed(int $user_id): bool
    {
        $token = wp_get_session_token();
        if ($user_id <= 0 || $token === '') {
            return false;
        }
        $session = \WP_Session_Tokens::get_instance($user_id)->get($token);

        return is_array($session) && (int) ($session[self::SESSION_KEY] ?? 0) === $user_id;
    }

    /**
     * Mark this login session as having passed the challenge. When headers
     * can still be sent, the session gets a new token first (as Laravel's
     * session()->regenerate()), and the old one is destroyed.
     */
    public static function mark_passed(int $user_id): void
    {
        $manager = \WP_Session_Tokens::get_instance($user_id);
        $token = wp_get_session_token();
        $session = $token !== '' ? $manager->get($token) : null;
        if (! is_array($session)) {
            return;
        }

        if (! headers_sent() && apply_filters('escalated_two_factor_regenerate_session', true)) {
            $expiration = (int) ($session['expiration'] ?? time() + DAY_IN_SECONDS);
            $new = $manager->create($expiration);
            $manager->destroy($token);
            wp_set_auth_cookie($user_id, false, '', $new);
            $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $new);
            $token = $new;
            $session = $manager->get($token) ?? $session;
        }

        $session[self::SESSION_KEY] = $user_id;
        $manager->update($token, $session);
    }

    /** Redirect Escalated wp-admin screens to the challenge or to enrolment. */
    public static function guard_admin(): void
    {
        if (! AccessFeatures::two_factor()) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === '' || ! str_starts_with($page, 'escalated') || $page === self::CHALLENGE_PAGE) {
            return;
        }
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        $to = self::denial($user_id, $page === self::ENROL_PAGE);
        if ($to === null) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
            $uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
            if ($host !== '' && $uri !== '') {
                update_user_meta($user_id, self::INTENDED_KEY, set_url_scheme('http://'.$host.$uri));
            }
        }
        wp_safe_redirect(admin_url('admin.php?page='.$to));
        exit;
    }

    /**
     * Refuse the staff REST routes (login-cookie requests) with 403
     * two_factor_required. API token requests and the user's own two-factor
     * routes pass.
     *
     * @param  mixed  $result
     * @return mixed
     */
    public static function guard_rest($result, \WP_REST_Request $request, string $route, array $handler)
    {
        if ($result !== null || ! AccessFeatures::two_factor() || ! str_starts_with($route, '/escalated/v1')) {
            return $result;
        }
        $path = RoutePermissions::normalize($route);
        if (RoutePermissions::is_public($path) || str_starts_with($path, '/admin/two-factor')) {
            return $result;
        }
        if (TicketAccess::token_user($request) !== null) {
            return $result;
        }
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return $result;
        }

        $to = self::denial($user_id, false);
        if ($to === null) {
            return $result;
        }

        return new WP_Error('escalated_two_factor_required', $to === self::CHALLENGE_PAGE
            ? __('Two-factor authentication is required.', 'escalated')
            : __('Set up two-factor authentication to continue.', 'escalated'), ['status' => 403, 'two_factor_required' => true]);
    }

    /**
     * Where the user must go first (the challenge or enrolment page), or null.
     */
    public static function denial(int $user_id, bool $on_enrolment_page): ?string
    {
        if (TwoFactor::confirmed_for_user($user_id)) {
            return self::passed($user_id) ? null : self::CHALLENGE_PAGE;
        }
        if (! $on_enrolment_page && self::required() && TicketAccess::is_staff($user_id)) {
            return self::ENROL_PAGE;
        }

        return null;
    }

    /** The page asked for before the challenge, once, else the ticket list. */
    public static function take_intended(int $user_id): string
    {
        $url = (string) get_user_meta($user_id, self::INTENDED_KEY, true);
        delete_user_meta($user_id, self::INTENDED_KEY);
        $fallback = admin_url('admin.php?page=escalated');

        return $url === '' ? $fallback : wp_validate_redirect($url, $fallback);
    }

    /** Count a challenge attempt: false past ATTEMPTS_PER_MINUTE a minute. */
    public static function allow_attempt(int $user_id): bool
    {
        $key = 'escalated_2fa_attempts_'.$user_id;
        $attempts = (int) get_transient($key);
        if ($attempts >= self::ATTEMPTS_PER_MINUTE) {
            return false;
        }
        set_transient($key, $attempts + 1, MINUTE_IN_SECONDS);

        return true;
    }
}
