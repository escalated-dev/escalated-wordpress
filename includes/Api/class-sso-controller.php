<?php

/**
 * Single sign-on routes (ADR 2026-10-29-configurable-access-features,
 * escalated-laravel's SsoController). Every route answers 404 while
 * ESCALATED_SSO is off, and each answers 404 unless its provider is the one
 * chosen in Escalated > Single Sign-On.
 */

namespace Escalated\Api;

use Escalated\Services\SsoService;
use Escalated\Services\SsoUserResolver;
use Escalated\Services\TicketAccess;
use Escalated\Support\AccessFeatures;
use Escalated\Support\SsoException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Sso_Controller extends Base_Controller
{
    public const OAUTH_COOKIE = 'escalated_sso_oauth';

    protected $rest_base = 'sso';

    public function register_routes(): void
    {
        $open = [$this, 'enabled_check'];
        register_rest_route($this->namespace, '/sso/login', [['methods' => 'GET', 'callback' => [$this, 'login'], 'permission_callback' => $open]]);
        register_rest_route($this->namespace, '/sso/saml/metadata', [['methods' => 'GET', 'callback' => [$this, 'metadata'], 'permission_callback' => $open]]);
        register_rest_route($this->namespace, '/sso/saml/acs', [['methods' => 'POST', 'callback' => [$this, 'saml_acs'], 'permission_callback' => $open]]);
        register_rest_route($this->namespace, '/sso/oauth/callback', [['methods' => 'GET', 'callback' => [$this, 'oauth_callback'], 'permission_callback' => $open]]);
        register_rest_route($this->namespace, '/sso/jwt/callback', [['methods' => ['GET', 'POST'], 'callback' => [$this, 'jwt_callback'], 'permission_callback' => $open]]);

        if (! has_filter('rest_pre_serve_request', [self::class, 'serve_metadata'])) {
            add_filter('rest_pre_serve_request', [self::class, 'serve_metadata'], 10, 3);
        }
    }

    /**
     * Serve the SP metadata as XML rather than JSON.
     *
     * @param  bool  $served
     * @param  mixed  $result
     */
    public static function serve_metadata($served, $result, WP_REST_Request $request): bool
    {
        if ($served || $request->get_route() !== '/escalated/v1/sso/saml/metadata' || ! ($result instanceof WP_REST_Response)) {
            return (bool) $served;
        }
        $data = $result->get_data();
        if (! is_array($data) || ! isset($data['saml_metadata'])) {
            return (bool) $served;
        }
        echo $data['saml_metadata']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an XML document

        return true;
    }

    /** @return true|WP_Error */
    public function enabled_check()
    {
        return AccessFeatures::sso() ? true : $this->error('escalated_not_found', __('Not found.', 'escalated'), 404);
    }

    public function login(WP_REST_Request $request)
    {
        $sso = new SsoService;
        try {
            switch ($sso->getProvider()) {
                case 'saml':
                    return $this->redirect($sso->samlLoginUrl());
                case 'oauth':
                    $provider = $sso->oauthProvider();
                    $url = $provider->getAuthorizationUrl();
                    $handle = wp_generate_password(32, false);
                    set_transient('escalated_sso_oauth_'.$handle, [
                        'state' => $provider->getState(),
                        'pkce' => $provider->getPkceCode(),
                    ], SsoService::SAML_REQUEST_TTL);
                    $response = $this->redirect($url);
                    $response->header('Set-Cookie', self::OAUTH_COOKIE.'='.$handle.'; Path=/; Max-Age='.SsoService::SAML_REQUEST_TTL.'; HttpOnly; SameSite=Lax'.(is_ssl() ? '; Secure' : ''));

                    return $response;
                case 'jwt':
                    return $this->redirect($sso->jwtLoginUrl());
            }
        } catch (SsoException $e) {
            return $this->refuse($e);
        }

        return $this->error('escalated_not_found', __('Not found.', 'escalated'), 404);
    }

    public function metadata()
    {
        $sso = new SsoService;
        if ($sso->getProvider() !== 'saml') {
            return $this->error('escalated_not_found', __('Not found.', 'escalated'), 404);
        }
        try {
            $xml = $sso->samlMetadata();
        } catch (SsoException $e) {
            error_log('Escalated SSO: '.$e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

            return $this->error('escalated_sso_metadata', __('SAML metadata is not available.', 'escalated'), 500);
        }
        $response = new WP_REST_Response(['saml_metadata' => $xml], 200);
        $response->header('Content-Type', 'application/samlmetadata+xml');

        return $response;
    }

    public function saml_acs(WP_REST_Request $request)
    {
        $sso = new SsoService;
        if ($sso->getProvider() !== 'saml') {
            return $this->error('escalated_not_found', __('Not found.', 'escalated'), 404);
        }

        return $this->sign_in(fn () => $sso->validateSamlResponse((string) $request->get_param('SAMLResponse')));
    }

    public function oauth_callback(WP_REST_Request $request)
    {
        $sso = new SsoService;
        if ($sso->getProvider() !== 'oauth') {
            return $this->error('escalated_not_found', __('Not found.', 'escalated'), 404);
        }

        // The state is single use: whatever happens next, it is gone.
        $handle = isset($_COOKIE[self::OAUTH_COOKIE]) ? sanitize_key(wp_unslash($_COOKIE[self::OAUTH_COOKIE])) : '';
        $stored = $handle !== '' ? get_transient('escalated_sso_oauth_'.$handle) : false;
        if ($handle !== '') {
            delete_transient('escalated_sso_oauth_'.$handle);
        }
        $state = is_array($stored) ? (string) ($stored['state'] ?? '') : '';
        $verifier = is_array($stored) ? (string) ($stored['pkce'] ?? '') : '';

        return $this->sign_in(function () use ($request, $sso, $state, $verifier) {
            if ((string) $request->get_param('error') !== '') {
                throw new SsoException('OAuth refused: the provider returned '.sanitize_text_field((string) $request->get_param('error')).'.');
            }
            if ($state === '' || ! hash_equals($state, (string) $request->get_param('state'))) {
                throw new SsoException('OAuth refused: missing or mismatched state.');
            }
            if ((string) $request->get_param('code') === '' || $verifier === '') {
                throw new SsoException('OAuth refused: no authorization code or PKCE verifier.');
            }

            return $sso->oauthIdentity((string) $request->get_param('code'), $verifier);
        });
    }

    public function jwt_callback(WP_REST_Request $request)
    {
        $sso = new SsoService;
        if ($sso->getProvider() !== 'jwt') {
            return $this->error('escalated_not_found', __('Not found.', 'escalated'), 404);
        }

        return $this->sign_in(fn () => $sso->validateJwtToken((string) $request->get_param('jwt')));
    }

    /** @param  callable(): array{email: string, name: string, role: string}  $identify */
    protected function sign_in(callable $identify)
    {
        try {
            $user = (new SsoUserResolver)->resolve($identify());
        } catch (SsoException $e) {
            return $this->refuse($e);
        }

        self::log_in($user);

        $target = (string) SsoService::option('redirect_to', '');
        if ($target === '') {
            $target = TicketAccess::is_staff((int) $user->ID) ? admin_url('admin.php?page=escalated') : home_url('/');
        }

        return $this->redirect($target, 303);
    }

    /**
     * Sign the user in on WordPress: a new login session and its cookies,
     * then wp_login, as wp-login.php does. Filterable for hosts with their
     * own sign-in.
     */
    public static function log_in(\WP_User $user): void
    {
        if (apply_filters('escalated_sso_log_in', null, $user) !== null) {
            return;
        }
        wp_clear_auth_cookie();
        if (! headers_sent()) {
            wp_set_auth_cookie((int) $user->ID, false);
        }
        wp_set_current_user((int) $user->ID);
        do_action('wp_login', $user->user_login, $user);
    }

    private function redirect(string $url, int $status = 302): WP_REST_Response
    {
        $response = new WP_REST_Response(null, $status);
        $response->header('Location', $url);

        return $response;
    }

    private function refuse(SsoException $e): WP_Error
    {
        error_log('Escalated SSO: '.$e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

        return new WP_Error('escalated_sso_failed', __('Single sign-on failed. Contact your administrator.', 'escalated'), ['status' => 403]);
    }
}
