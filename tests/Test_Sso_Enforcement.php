<?php

/**
 * Single sign-on (ADR 2026-10-29-configurable-access-features, port
 * checklist "SSO"), through the REST routes under escalated/v1/sso, as
 * escalated-laravel's SsoEnforcementTest.
 */

use Escalated\Admin\Admin_Sso;
use Escalated\Models\Setting;
use Escalated\Services\SsoService;
use Escalated\Support\AccessFeatures;
use Escalated\Support\RoleCatalog;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;

require_once __DIR__.'/Support/SamlIdp.php';

class Test_Sso_Enforcement extends WP_UnitTestCase
{
    private const JWT_SECRET = 'a-shared-secret-at-least-32-bytes-long!!';

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();
        // SAML compares Destination with the ACS URL: pretty permalinks give
        // it a path of its own.
        $this->set_permalink_structure('/%postname%/');
        $this->boot();
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset($_COOKIE[\Escalated\Api\Sso_Controller::OAUTH_COOKIE]);
        wp_set_current_user(0);
        parent::tear_down();
    }

    private function boot(): void
    {
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    private function on(string $provider, array $settings = []): void
    {
        add_filter('escalated_access_sso', '__return_true');
        Setting::set('sso_provider', $provider);
        foreach ($settings as $k => $v) {
            Setting::set($k, $v);
        }
    }

    private function user(string $email = 'person@example.com', string $role = 'escalated_agent'): int
    {
        return $this->factory->user->create(['user_email' => $email, 'role' => $role]);
    }

    private function get(string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/sso/'.$route);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }

        return $this->server->dispatch($request);
    }

    private function post(string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/sso/'.$route);
        $request->set_body_params($params);

        return $this->server->dispatch($request);
    }

    private function assert_signed_in(WP_REST_Response $response, int $user, string $what): void
    {
        $this->assertSame(303, $response->get_status(), $what.': '.wp_json_encode($response->get_data()));
        $this->assertSame($user, get_current_user_id(), $what);
        wp_set_current_user(0);
    }

    private function assert_refused(WP_REST_Response $response, string $what): void
    {
        $this->assertSame(403, $response->get_status(), $what);
        $this->assertSame('Single sign-on failed. Contact your administrator.', $response->get_data()['message'] ?? null, $what);
        $this->assertSame(0, get_current_user_id(), $what.': someone was signed in');
    }

    // ---------------------------------------------------------------- switch

    public function test_with_the_switch_off_every_sso_route_and_screen_answers_404(): void
    {
        $this->assertFalse(AccessFeatures::sso());
        Setting::set('sso_provider', 'jwt');
        Setting::set('sso', '1');
        $this->assertFalse(AccessFeatures::sso(), 'a setting does not flip the switch');

        foreach ([['GET', 'login'], ['GET', 'saml/metadata'], ['POST', 'saml/acs'], ['GET', 'oauth/callback'], ['GET', 'jwt/callback'], ['POST', 'jwt/callback']] as [$method, $route]) {
            $response = $method === 'GET' ? $this->get($route) : $this->post($route);
            $this->assertSame(404, $response->get_status(), "{$method} {$route}");
        }

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        try {
            (new Admin_Sso)->render();
            $this->fail('the SSO screen rendered with the switch off');
        } catch (WPDieException $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertFalse(AccessFeatures::shared()['sso']);
    }

    public function test_provider_none_makes_login_404_and_the_screen_lists_the_endpoints(): void
    {
        $this->on('none');
        $this->assertSame(404, $this->get('login')->get_status());
        $this->assertTrue(AccessFeatures::shared()['sso']);

        $admin = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        ob_start();
        (new Admin_Sso)->render();
        $html = ob_get_clean();
        $this->assertStringContainsString(esc_html(SsoService::url('saml/acs')), $html);
        $this->assertStringContainsString(esc_html(SsoService::url('oauth/callback')), $html);

        // Secrets are masked, and a posted mask keeps the stored secret.
        $this->assertSame([], Admin_Sso::save(['sso_provider' => 'jwt', 'sso_url' => 'https://idp.example.test/login', 'sso_jwt_secret' => 'top-secret']));
        $this->assertSame('********', Admin_Sso::masked((new SsoService)->getConfig())['sso_jwt_secret']);
        $this->assertSame([], Admin_Sso::save(['sso_provider' => 'jwt', 'sso_url' => 'https://idp.example.test/login', 'sso_jwt_secret' => '********']));
        $this->assertSame('top-secret', Setting::get('sso_jwt_secret'));
        ob_start();
        (new Admin_Sso)->render();
        $this->assertStringNotContainsString('top-secret', ob_get_clean());

        $this->assertContains('sso_entity_id', Admin_Sso::save(['sso_provider' => 'saml', 'sso_login_url' => 'not a url']));
    }

    // ------------------------------------------------------------------ SAML

    private function saml_on(Escalated_Test_SamlIdp $idp): void
    {
        $this->on('saml', [
            'sso_entity_id' => $idp->entityId,
            'sso_login_url' => 'https://idp.example.test/sso',
            'sso_certificate' => $idp->certificate,
        ]);
    }

    /** Start a sign-in and return the AuthnRequest ID the IdP must answer. */
    private function saml_start(): string
    {
        $response = $this->get('login');
        $this->assertSame(302, $response->get_status());
        $location = $response->get_headers()['Location'];
        $this->assertStringStartsWith('https://idp.example.test/sso?', $location);
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        $xml = gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertSame(1, preg_match('/ID="([^"]+)"/', $xml, $m));

        return $m[1];
    }

    private function acs(string $xml): WP_REST_Response
    {
        return $this->post('saml/acs', ['SAMLResponse' => base64_encode($xml)]);
    }

    public function test_saml_signs_in_the_matched_user_and_refuses_every_failed_check(): void
    {
        $idp = new Escalated_Test_SamlIdp;
        $this->saml_on($idp);
        $user = $this->user('person@example.com');
        $acs = SsoService::url('saml/acs');
        $aud = SsoService::url('saml/metadata');

        $metadata = $this->get('saml/metadata');
        $this->assertSame(200, $metadata->get_status());
        $this->assertStringContainsString($acs, $metadata->get_data()['saml_metadata']);

        $this->assert_signed_in($this->acs($idp->response($acs, $aud, ['in_response_to' => $this->saml_start(), 'attributes' => ['email' => 'Person@Example.com']])), $user, 'valid response');

        $cases = [
            'unsigned assertion' => ['sign_assertion' => false],
            'only the Response signed' => ['sign_assertion' => false, 'sign_response' => true],
            'signed by another key' => ['signing_key' => 'other'],
            'tampered after signing' => ['tamper' => true],
            'wrong issuer' => ['issuer' => 'https://evil.example.test'],
            'wrong audience' => ['audience' => 'https://other-sp.example.test'],
            'wrong destination' => ['destination' => 'https://other-sp.example.test/acs'],
            'wrong recipient' => ['recipient' => 'https://other-sp.example.test/acs'],
            'expired' => ['not_before' => time() - 3600, 'not_on_or_after' => time() - 1800],
            'unknown InResponseTo' => ['in_response_to' => '_never-issued'],
            'unsolicited' => ['in_response_to' => 'unsolicited'],
        ];
        foreach ($cases as $what => $options) {
            $id = $this->saml_start();
            $tamper = $options['tamper'] ?? false;
            unset($options['tamper']);
            if (($options['signing_key'] ?? null) === 'other') {
                $options['signing_key'] = Escalated_Test_SamlIdp::keyPair()[0];
            }
            if (! array_key_exists('in_response_to', $options)) {
                $options['in_response_to'] = $id;
            } elseif ($options['in_response_to'] === 'unsolicited') {
                $options['in_response_to'] = null;
            }
            $xml = $idp->response($acs, $aud, $options);
            if ($tamper) {
                $xml = str_replace('person@example.com', 'boss@example.com', $xml);
            }
            $this->assert_refused($this->acs($xml), $what);
        }

        // The same request ID a second time.
        $id = $this->saml_start();
        $this->assert_signed_in($this->acs($idp->response($acs, $aud, ['in_response_to' => $id])), $user, 'fresh request');
        $this->assert_refused($this->acs($idp->response($acs, $aud, ['in_response_to' => $id])), 'the same request ID again');
    }

    public function test_saml_refuses_everything_without_a_certificate(): void
    {
        $idp = new Escalated_Test_SamlIdp;
        $this->saml_on($idp);
        Setting::set('sso_certificate', '');
        $this->user();

        $this->assertSame(403, $this->get('login')->get_status());
        $this->assert_refused($this->acs($idp->response(SsoService::url('saml/acs'), SsoService::url('saml/metadata'))), 'no certificate');
    }

    public function test_saml_idp_initiated_only_when_allowed_and_an_assertion_works_once(): void
    {
        $idp = new Escalated_Test_SamlIdp;
        $this->saml_on($idp);
        $user = $this->user();
        $xml = $idp->response(SsoService::url('saml/acs'), SsoService::url('saml/metadata'));

        $this->assert_refused($this->acs($xml), 'IdP-initiated while off');

        add_filter('escalated_sso_allow_idp_initiated', '__return_true');
        $this->assert_signed_in($this->acs($xml), $user, 'IdP-initiated while allowed');
        $this->assert_refused($this->acs($xml), 'the same assertion again');
    }

    // ----------------------------------------------------------------- OAuth

    private function oauth_on(array $responses, array &$history): void
    {
        $this->on('oauth', [
            'sso_oauth_authorize_url' => 'https://idp.example.test/authorize',
            'sso_oauth_token_url' => 'https://idp.example.test/token',
            'sso_oauth_userinfo_url' => 'https://idp.example.test/userinfo',
            'sso_oauth_client_id' => 'client-id',
            'sso_oauth_client_secret' => 'client-secret',
        ]);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        add_filter('escalated_sso_http_client', static fn () => $client);
    }

    private static function oauth_replies(): array
    {
        return [
            new PsrResponse(200, ['Content-Type' => 'application/json'], (string) wp_json_encode(['access_token' => 'at-123', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new PsrResponse(200, ['Content-Type' => 'application/json'], (string) wp_json_encode(['email' => 'person@example.com', 'name' => 'Person', 'email_verified' => true])),
        ];
    }

    /** @return array{0: array, 1: string} the authorize URL's query and the state cookie */
    private function oauth_start(): array
    {
        $response = $this->get('login');
        $this->assertSame(302, $response->get_status());
        $location = $response->get_headers()['Location'];
        $this->assertStringStartsWith('https://idp.example.test/authorize?', $location);
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        $cookie = $response->get_headers()['Set-Cookie'];
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertSame(1, preg_match('/^escalated_sso_oauth=([^;]+)/', $cookie, $m));

        return [$query, $m[1]];
    }

    public function test_oauth_signs_in_with_state_and_pkce(): void
    {
        $history = [];
        $this->oauth_on(self::oauth_replies(), $history);
        $user = $this->user();

        [$query, $handle] = $this->oauth_start();
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotSame('', $query['state']);
        $_COOKIE[\Escalated\Api\Sso_Controller::OAUTH_COOKIE] = $handle;

        $this->assert_signed_in($this->get('oauth/callback', ['code' => 'auth-code', 'state' => $query['state']]), $user, 'matching state');

        parse_str((string) $history[0]['request']->getBody(), $token_request);
        $this->assertSame('auth-code', $token_request['code']);
        $this->assertNotSame('', $token_request['code_verifier']);
        $this->assertSame($query['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $token_request['code_verifier'], true)), '+/', '-_'), '='));

        // The state is single use.
        $this->assert_refused($this->get('oauth/callback', ['code' => 'auth-code', 'state' => $query['state']]), 'the same state again');
    }

    public function test_oauth_refuses_a_missing_or_wrong_state_and_idp_errors(): void
    {
        $history = [];
        $this->oauth_on(array_merge(self::oauth_replies(), self::oauth_replies(), self::oauth_replies()), $history);
        $this->user();

        [$query, $handle] = $this->oauth_start();
        $this->assert_refused($this->get('oauth/callback', ['code' => 'auth-code', 'state' => $query['state']]), 'no state cookie');

        [, $handle] = $this->oauth_start();
        $_COOKIE[\Escalated\Api\Sso_Controller::OAUTH_COOKIE] = $handle;
        $this->assert_refused($this->get('oauth/callback', ['code' => 'auth-code', 'state' => 'forged']), 'mismatched state');

        [, $handle] = $this->oauth_start();
        $_COOKIE[\Escalated\Api\Sso_Controller::OAUTH_COOKIE] = $handle;
        $this->assert_refused($this->get('oauth/callback', ['code' => 'auth-code']), 'missing state');

        [$query, $handle] = $this->oauth_start();
        $_COOKIE[\Escalated\Api\Sso_Controller::OAUTH_COOKIE] = $handle;
        $this->assert_refused($this->get('oauth/callback', ['error' => 'access_denied', 'state' => $query['state']]), 'IdP error');
        $this->assertSame([], $history, 'no token was requested');
    }

    // ------------------------------------------------------------------- JWT

    private function jwt_on(array $settings = []): void
    {
        $this->on('jwt', array_merge(['sso_url' => 'https://idp.example.test/login', 'sso_jwt_secret' => self::JWT_SECRET, 'sso_jwt_algorithm' => 'HS256'], $settings));
    }

    private static function token(array $claims = [], string $secret = self::JWT_SECRET, string $alg = 'HS256'): string
    {
        $base = ['email' => 'person@example.com', 'name' => 'Person', 'aud' => home_url(), 'iat' => time(), 'exp' => time() + 300, 'jti' => wp_generate_password(20, false)];

        return JWT::encode(array_filter(array_merge($base, $claims), static fn ($v) => $v !== null), $secret, $alg);
    }

    public function test_jwt_signs_in_and_refuses_every_failed_check(): void
    {
        $this->jwt_on();
        $user = $this->user();

        $login = $this->get('login');
        $this->assertSame(302, $login->get_status());
        $this->assertSame('https://idp.example.test/login?return_to='.rawurlencode(SsoService::url('jwt/callback')), $login->get_headers()['Location']);

        $token = self::token();
        $this->assert_signed_in($this->get('jwt/callback', ['jwt' => $token]), $user, 'valid token');
        $this->assert_refused($this->get('jwt/callback', ['jwt' => $token]), 'reused jti');
        $this->assert_signed_in($this->post('jwt/callback', ['jwt' => self::token()]), $user, 'POST');

        [$head, $body] = explode('.', self::token());
        $none = rtrim(strtr(base64_encode((string) wp_json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=').'.'.$body.'.';
        $cases = [
            'bad signature' => self::token([], 'another-secret-that-is-also-long-enough'),
            'alg none' => $none,
            'wrong algorithm' => self::token([], self::JWT_SECRET.self::JWT_SECRET, 'HS512'),
            'missing exp' => self::token(['exp' => null]),
            'past exp' => self::token(['exp' => time() - 3600]),
            'wrong aud' => self::token(['aud' => 'https://other.example.test']),
            'missing jti' => self::token(['jti' => null]),
        ];
        foreach ($cases as $what => $bad) {
            $this->assert_refused($this->get('jwt/callback', ['jwt' => $bad]), $what);
        }
    }

    public function test_unknown_emails_are_refused_unless_creating_users(): void
    {
        $this->jwt_on();
        $this->assert_refused($this->get('jwt/callback', ['jwt' => self::token(['email' => 'new@example.com'])]), 'unknown email');
        $this->assertFalse(get_user_by('email', 'new@example.com'));

        add_filter('escalated_sso_create_users', '__return_true');
        $response = $this->get('jwt/callback', ['jwt' => self::token(['email' => 'new@example.com', 'name' => 'New Person'])]);
        $created = get_user_by('email', 'new@example.com');
        $this->assertInstanceOf(WP_User::class, $created);
        $this->assertSame('New Person', $created->display_name);
        $this->assert_signed_in($response, (int) $created->ID, 'created');
    }

    public function test_role_mapping_only_with_roles_on_and_sync_on(): void
    {
        $this->jwt_on();
        $user = $this->user('person@example.com', 'escalated_agent');

        $this->get('jwt/callback', ['jwt' => self::token(['role' => 'light_agent'])]);
        $this->assertSame(['escalated_agent'], RoleCatalog::user_roles($user), 'roles off: no change');

        add_filter('escalated_access_roles_permissions', '__return_true');
        $this->get('jwt/callback', ['jwt' => self::token(['role' => 'light_agent'])]);
        $this->assertSame(['escalated_agent'], RoleCatalog::user_roles($user), 'sync off: no change');

        add_filter('escalated_sso_sync_roles', '__return_true');
        $this->get('jwt/callback', ['jwt' => self::token(['role' => 'light_agent'])]);
        clean_user_cache($user);
        $this->assertSame(['escalated_light_agent'], RoleCatalog::user_roles($user));

        $this->get('jwt/callback', ['jwt' => self::token(['role' => 'no-such-role'])]);
        clean_user_cache($user);
        $this->assertSame(['escalated_light_agent'], RoleCatalog::user_roles($user), 'unknown role changes nothing');
    }
}
