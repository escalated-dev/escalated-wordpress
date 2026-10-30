<?php

namespace Escalated\Services;

use Escalated\Models\Setting;
use Escalated\Support\AccessFeatures;
use Escalated\Support\SsoException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use League\OAuth2\Client\Provider\GenericProvider;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants as SamlConstants;
use OneLogin\Saml2\Response as SamlResponse;
use OneLogin\Saml2\Settings as SamlSettings;
use OneLogin\Saml2\Utils as SamlUtils;

/**
 * Single sign-on through the provider an admin picks in Escalated > Single
 * Sign-On (ADR 2026-10-29-configurable-access-features), ported from
 * escalated-laravel's SsoService on the same maintained libraries:
 * onelogin/php-saml (SAML 2.0), league/oauth2-client (OAuth 2 / OIDC with
 * PKCE) and firebase/php-jwt (JWT).
 *
 * Every validate method returns ['email' => string, 'name' => string,
 * 'role' => string], or throws an SsoException whose message is for the log,
 * never for the user.
 */
class SsoService
{
    /** How long an AuthnRequest ID stays answerable, in seconds. */
    public const SAML_REQUEST_TTL = 600;

    /** The runtime SSO settings and their defaults. */
    public const CONFIG_KEYS = [
        'sso_provider' => 'none',
        'sso_entity_id' => '',
        'sso_url' => '',
        'sso_login_url' => '',
        'sso_logout_url' => '',
        'sso_metadata_url' => '',
        'sso_certificate' => '',
        'sso_attr_email' => 'email',
        'sso_attr_name' => 'name',
        'sso_attr_role' => 'role',
        'sso_jwt_secret' => '',
        'sso_jwt_algorithm' => 'HS256',
        'sso_oauth_authorize_url' => '',
        'sso_oauth_token_url' => '',
        'sso_oauth_userinfo_url' => '',
        'sso_oauth_client_id' => '',
        'sso_oauth_client_secret' => '',
        'sso_oauth_scopes' => 'openid profile email',
    ];

    /** Stored secrets the settings screen sees masked. */
    public const SECRET_KEYS = ['sso_jwt_secret', 'sso_oauth_client_secret'];

    protected SsoMessageIds $messageIds;

    public function __construct(?SsoMessageIds $messageIds = null)
    {
        $this->messageIds = $messageIds ?? new SsoMessageIds;
    }

    /**
     * The SSO settings with their defaults. The legacy sso_url fills the
     * sign-in URL, and the provider's authorize or metadata URL, when those
     * are empty.
     */
    public function getConfig(): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS as $key => $default) {
            $value = Setting::get($key, $default);
            $config[$key] = ($value === null || $value === '') ? $default : (string) $value;
        }

        $legacy = trim($config['sso_url']);
        if ($legacy !== '') {
            if ($config['sso_login_url'] === '') {
                $config['sso_login_url'] = $legacy;
            }
            if ($config['sso_provider'] === 'oauth' && $config['sso_oauth_authorize_url'] === '') {
                $config['sso_oauth_authorize_url'] = $legacy;
            } elseif ($config['sso_provider'] === 'saml' && $config['sso_metadata_url'] === '') {
                $config['sso_metadata_url'] = $legacy;
            }
        }

        return $config;
    }

    /** Save the given SSO settings (unknown keys are ignored). */
    public function saveConfig(array $data): void
    {
        foreach ($data as $key => $value) {
            if (array_key_exists($key, self::CONFIG_KEYS)) {
                Setting::set($key, (string) $value);
            }
        }
    }

    /** Whether SSO is switched on for the install and a provider is chosen. */
    public function isEnabled(): bool
    {
        return AccessFeatures::sso() && $this->getProvider() !== 'none';
    }

    public function getProvider(): string
    {
        $provider = (string) Setting::get('sso_provider', 'none');

        return in_array($provider, ['saml', 'oauth', 'jwt'], true) ? $provider : 'none';
    }

    /**
     * The URLs an admin gives the identity provider.
     *
     * @return array<string, string>
     */
    public function endpoints(): array
    {
        return [
            'login' => self::url('login'),
            'sp_entity_id' => $this->spEntityId(),
            'metadata' => self::url('saml/metadata'),
            'acs' => self::url('saml/acs'),
            'oauth_redirect' => self::url('oauth/callback'),
            'jwt_callback' => self::url('jwt/callback'),
        ];
    }

    public static function url(string $path): string
    {
        return rest_url('escalated/v1/sso/'.$path);
    }

    public function spEntityId(): string
    {
        $configured = (string) self::option('sp_entity_id', '');

        return $configured !== '' ? $configured : self::url('saml/metadata');
    }

    // ------------------------------------------------------------------ SAML

    /** @throws SsoException */
    public function samlSettings(bool $spOnly = false): SamlSettings
    {
        $config = $this->getConfig();
        $certificate = trim($config['sso_certificate']);

        if (! $spOnly && $certificate === '') {
            throw new SsoException('SAML refused: no IdP certificate is configured.');
        }

        try {
            return new SamlSettings([
                'strict' => true,
                'debug' => false,
                'sp' => [
                    'entityId' => $this->spEntityId(),
                    'assertionConsumerService' => [
                        'url' => self::url('saml/acs'),
                        'binding' => SamlConstants::BINDING_HTTP_POST,
                    ],
                    'NameIDFormat' => SamlConstants::NAMEID_UNSPECIFIED,
                ],
                'idp' => [
                    'entityId' => $config['sso_entity_id'],
                    'singleSignOnService' => [
                        'url' => $config['sso_login_url'],
                        'binding' => SamlConstants::BINDING_HTTP_REDIRECT,
                    ],
                    'x509cert' => $certificate,
                ],
                'security' => [
                    // The assertion itself must carry the IdP's signature; a
                    // signed Response around an unsigned assertion is refused.
                    'wantAssertionsSigned' => true,
                    'wantMessagesSigned' => false,
                    'wantNameId' => false,
                    'requestedAuthnContext' => false,
                    'rejectUnsolicitedResponsesWithInResponseTo' => true,
                    'destinationStrictlyMatches' => true,
                    'relaxDestinationValidation' => false,
                    'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                    'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                ],
            ], $spOnly);
        } catch (\Throwable $e) {
            throw new SsoException('SAML settings are invalid: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The IdP sign-in URL for a new AuthnRequest. Its ID is stored server-side
     * (the IdP's cross-site POST back carries no SameSite=Lax cookie) and must
     * come back as InResponseTo within SAML_REQUEST_TTL.
     *
     * @throws SsoException
     */
    public function samlLoginUrl(): string
    {
        $settings = $this->samlSettings();
        $request = new AuthnRequest($settings);
        $this->messageIds->remember('saml_request', $request->getId(), time() + self::SAML_REQUEST_TTL);

        $url = (string) $settings->getIdPData()['singleSignOnService']['url'];
        if ($url === '') {
            throw new SsoException('SAML refused: no IdP sign-in URL is configured.');
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['SAMLRequest' => $request->getRequest()]);
    }

    /** @throws SsoException */
    public function samlMetadata(): string
    {
        $settings = $this->samlSettings(true);
        $metadata = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($metadata);
        if ($errors !== []) {
            throw new SsoException('SAML metadata is invalid: '.implode(', ', $errors));
        }

        return $metadata;
    }

    /**
     * Validate a base64 SAMLResponse posted to the ACS.
     *
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    public function validateSamlResponse(string $samlResponse): array
    {
        $settings = $this->samlSettings();
        $config = $this->getConfig();

        $xml = base64_decode($samlResponse, true);
        if ($xml === false || $xml === '') {
            throw new SsoException('SAML refused: the response is not base64.');
        }
        if (str_contains($xml, 'EncryptedAssertion')) {
            throw new SsoException('SAML refused: encrypted assertions are not supported.');
        }

        $requestId = $this->samlInResponseTo($xml);
        if ($requestId === null) {
            if (! self::option('allow_idp_initiated', false)) {
                throw new SsoException('SAML refused: unsolicited response (no InResponseTo) and IdP-initiated sign-in is off.');
            }
        } elseif (! $this->messageIds->consume('saml_request', $requestId)) {
            throw new SsoException("SAML refused: InResponseTo [{$requestId}] is not a pending request.");
        }

        $response = $this->atAcsUrl(function () use ($settings, $samlResponse, $requestId) {
            try {
                $response = new SamlResponse($settings, $samlResponse);
            } catch (\Throwable $e) {
                throw new SsoException('SAML refused: '.$e->getMessage(), 0, $e);
            }
            if (! $response->isValid($requestId)) {
                throw new SsoException('SAML refused: '.$response->getError());
            }

            return $response;
        });

        $assertionId = (string) $response->getAssertionId();
        $expires = (int) ($response->getAssertionNotOnOrAfter() ?: time() + 300) + SamlConstants::ALLOWED_CLOCK_DRIFT;
        if ($assertionId === '' || ! $this->messageIds->claim('saml_assertion', $assertionId, $expires)) {
            throw new SsoException("SAML refused: assertion [{$assertionId}] was already used.");
        }

        $attributes = $response->getAttributes();
        $first = static fn (string $name) => isset($attributes[$name][0]) ? trim((string) $attributes[$name][0]) : '';

        $email = $first($config['sso_attr_email']);
        if ($email === '') {
            $nameId = trim((string) $response->getNameId());
            $email = is_email($nameId) ? $nameId : '';
        }

        return $this->identity($email, $first($config['sso_attr_name']), $first($config['sso_attr_role']));
    }

    /**
     * Run a php-saml check as if the request arrived at the ACS URL, so
     * Destination and Recipient are compared with that URL and not with
     * whatever host and path a proxy happened to forward.
     */
    protected function atAcsUrl(callable $callback): mixed
    {
        $acs = wp_parse_url(self::url('saml/acs'));
        $scheme = $acs['scheme'] ?? 'https';
        $path = ($acs['path'] ?? '/').(isset($acs['query']) ? '?'.$acs['query'] : '');
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;
        $savedQuery = $_SERVER['QUERY_STRING'] ?? null;

        SamlUtils::setSelfProtocol($scheme);
        SamlUtils::setSelfHost($acs['host'] ?? 'localhost');
        SamlUtils::setSelfPort((string) ($acs['port'] ?? ($scheme === 'https' ? 443 : 80)));
        SamlUtils::setBaseURLPath(null);
        $_SERVER['REQUEST_URI'] = $path;
        unset($_SERVER['QUERY_STRING']);

        try {
            return $callback();
        } finally {
            SamlUtils::setSelfProtocol(null);
            SamlUtils::setSelfHost(null);
            SamlUtils::setSelfPort(null);
            $_SERVER['REQUEST_URI'] = $savedUri ?? '';
            if ($savedQuery !== null) {
                $_SERVER['QUERY_STRING'] = $savedQuery;
            }
        }
    }

    protected function samlInResponseTo(string $xml): ?string
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if (! $loaded || ! $doc->documentElement) {
            throw new SsoException('SAML refused: malformed XML.');
        }
        $value = trim($doc->documentElement->getAttribute('InResponseTo'));

        return $value === '' ? null : $value;
    }

    // ----------------------------------------------------------------- OAuth

    public function oauthProvider(): GenericProvider
    {
        $config = $this->getConfig();
        $scopes = preg_split('/[\s,]+/', trim($config['sso_oauth_scopes'])) ?: [];

        $collaborators = [];
        $client = apply_filters('escalated_sso_http_client', null);
        if ($client !== null) {
            $collaborators['httpClient'] = $client;
        }

        return new GenericProvider([
            'clientId' => $config['sso_oauth_client_id'],
            'clientSecret' => $config['sso_oauth_client_secret'],
            'redirectUri' => self::url('oauth/callback'),
            'urlAuthorize' => $config['sso_oauth_authorize_url'],
            'urlAccessToken' => $config['sso_oauth_token_url'],
            'urlResourceOwnerDetails' => $config['sso_oauth_userinfo_url'],
            'scopes' => array_values(array_filter($scopes)),
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], $collaborators);
    }

    /**
     * Exchange the authorization code (with the PKCE verifier) server to
     * server and read the profile from the userinfo endpoint.
     *
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    public function oauthIdentity(string $code, string $pkceVerifier): array
    {
        $config = $this->getConfig();
        $provider = $this->oauthProvider();
        $provider->setPkceCode($pkceVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $profile = $provider->getResourceOwner($token)->toArray();
        } catch (\Throwable $e) {
            throw new SsoException('OAuth refused: '.$e->getMessage(), 0, $e);
        }

        if (array_key_exists('email_verified', $profile) && in_array($profile['email_verified'], [false, 'false', 0, '0'], true)) {
            throw new SsoException('OAuth refused: the provider says the email is not verified.');
        }

        return $this->identity(
            self::claim($profile, $config['sso_attr_email'], 'email'),
            self::claim($profile, $config['sso_attr_name'], 'name'),
            self::claim($profile, $config['sso_attr_role']),
        );
    }

    // ------------------------------------------------------------------- JWT

    /**
     * Validate a JWT handed back by the identity provider.
     *
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    public function validateJwtToken(string $token): array
    {
        $config = $this->getConfig();
        $algorithm = $config['sso_jwt_algorithm'] ?: 'HS256';
        $secret = $config['sso_jwt_secret'];

        if (! in_array($algorithm, ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512'], true)) {
            throw new SsoException("JWT refused: unsupported algorithm {$algorithm}.");
        }
        if ($secret === '') {
            throw new SsoException('JWT refused: no secret or public key is configured.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::clockSkew();
        try {
            // The Key pins the algorithm: a token naming any other alg
            // (including "none") is refused by the library.
            $claims = (array) JWT::decode($token, new Key($secret, $algorithm));
        } catch (\Throwable $e) {
            throw new SsoException('JWT refused: '.$e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (! isset($claims['exp']) || ! is_numeric($claims['exp'])) {
            throw new SsoException('JWT refused: no exp claim.');
        }

        $audience = (string) self::option('jwt_audience', '');
        if ($audience === '') {
            $audience = home_url();
        }
        $tokenAudiences = array_map('strval', (array) ($claims['aud'] ?? []));
        if (! in_array($audience, $tokenAudiences, true)) {
            throw new SsoException('JWT refused: aud does not name this site.');
        }

        $jti = isset($claims['jti']) ? (string) $claims['jti'] : '';
        if ($jti === '') {
            throw new SsoException('JWT refused: no jti claim.');
        }
        if (! $this->messageIds->claim('jwt', $jti, (int) $claims['exp'] + self::clockSkew())) {
            throw new SsoException("JWT refused: jti [{$jti}] was already used.");
        }

        return $this->identity(
            self::claim($claims, $config['sso_attr_email'], 'email'),
            self::claim($claims, $config['sso_attr_name'], 'name'),
            self::claim($claims, $config['sso_attr_role']),
        );
    }

    /** The JWT IdP's sign-in URL with return_to set to the callback. */
    public function jwtLoginUrl(): string
    {
        $config = $this->getConfig();
        $url = trim($config['sso_login_url'] ?: $config['sso_url']);
        if ($url === '') {
            throw new SsoException('JWT sign-in has no login URL configured.');
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['return_to' => self::url('jwt/callback')]);
    }

    public static function clockSkew(): int
    {
        return max(0, min(300, (int) self::option('clock_skew', 120)));
    }

    /**
     * An install-level SSO option (ADR's escalated.sso.*): the constant
     * ESCALATED_SSO_{NAME}, else the environment variable, then the
     * `escalated_sso_{name}` filter.
     */
    public static function option(string $name, mixed $default): mixed
    {
        $constant = 'ESCALATED_SSO_'.strtoupper($name);
        if (defined($constant)) {
            $value = constant($constant);
        } else {
            $env = getenv($constant);
            $value = $env === false ? $default : $env;
        }
        if (is_bool($default) && ! is_bool($value)) {
            $value = in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
        }

        return apply_filters('escalated_sso_'.$name, $value);
    }

    /** A (dotted) claim, else the fallback claim. */
    protected static function claim(array $data, string $name, string $fallback = ''): string
    {
        foreach ([$name, $fallback] as $key) {
            if ($key === '') {
                continue;
            }
            $value = $data;
            foreach (explode('.', $key) as $part) {
                if (is_array($value) && array_key_exists($part, $value)) {
                    $value = $value[$part];
                } elseif (is_object($value) && isset($value->$part)) {
                    $value = $value->$part;
                } else {
                    $value = null;
                    break;
                }
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    protected function identity(string $email, string $name, string $role): array
    {
        if (! is_email($email)) {
            throw new SsoException('SSO refused: no valid email in the identity.');
        }

        return ['email' => $email, 'name' => $name, 'role' => $role];
    }
}
