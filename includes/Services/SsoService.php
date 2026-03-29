<?php

namespace Escalated\Services;

use Escalated\Models\Setting;

class SsoService {

    protected $configKeys = [
        'sso_provider' => 'none',
        'sso_entity_id' => '',
        'sso_url' => '',
        'sso_certificate' => '',
        'sso_attr_email' => 'email',
        'sso_attr_name' => 'name',
        'sso_attr_role' => 'role',
        'sso_jwt_secret' => '',
        'sso_jwt_algorithm' => 'HS256',
    ];

    /**
     * Get the current SSO configuration.
     */
    public function getConfig() {
        $config = [];
        foreach ($this->configKeys as $key => $default) {
            $config[$key] = Setting::get($key, $default);
        }
        return $config;
    }

    /**
     * Save SSO configuration.
     */
    public function saveConfig($data) {
        $allowed = array_keys($this->configKeys);
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                Setting::set($key, (string) $value);
            }
        }
    }

    /**
     * Check if SSO is enabled.
     */
    public function isEnabled() {
        return $this->getProvider() !== 'none';
    }

    /**
     * Get the active SSO provider type.
     */
    public function getProvider() {
        return Setting::get('sso_provider', 'none');
    }

    /**
     * Validate a base64-encoded SAML response and extract user attributes.
     *
     * @param  string $samlResponse
     * @return array  Array with 'email', 'name', 'role', 'attributes'
     * @throws \RuntimeException
     */
    public function validateSamlAssertion($samlResponse) {
        $config = $this->getConfig();

        $xml = base64_decode($samlResponse, true);
        if ($xml === false) {
            throw new \RuntimeException('Invalid SAML response: base64 decode failed.');
        }

        $doc = new \DOMDocument();
        $prevErrors = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_use_internal_errors($prevErrors);
        if (!$loaded) {
            throw new \RuntimeException('Invalid SAML response: malformed XML.');
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');

        // Check issuer
        $entityId = trim($config['sso_entity_id']);
        if ($entityId !== '') {
            $issuerNodes = $xpath->query('//saml:Issuer');
            if ($issuerNodes->length === 0) {
                throw new \RuntimeException('SAML assertion missing Issuer element.');
            }
            $issuer = trim($issuerNodes->item(0)->textContent);
            if ($issuer !== $entityId) {
                throw new \RuntimeException("SAML Issuer mismatch: expected '{$entityId}', got '{$issuer}'.");
            }
        }

        // Validate conditions
        $conditionNodes = $xpath->query('//saml:Conditions');
        if ($conditionNodes->length > 0) {
            $conditions = $conditionNodes->item(0);
            $now = time();
            $skew = 120;

            $notBefore = $conditions->getAttribute('NotBefore');
            if ($notBefore !== '' && strtotime($notBefore) > ($now + $skew)) {
                throw new \RuntimeException('SAML assertion is not yet valid.');
            }

            $notOnOrAfter = $conditions->getAttribute('NotOnOrAfter');
            if ($notOnOrAfter !== '' && strtotime($notOnOrAfter) < ($now - $skew)) {
                throw new \RuntimeException('SAML assertion has expired.');
            }
        }

        // Extract attributes
        $attrEmail = $config['sso_attr_email'];
        $attrName = $config['sso_attr_name'];
        $attrRole = $config['sso_attr_role'];

        $attributes = [];
        $attrNodes = $xpath->query('//saml:AttributeStatement/saml:Attribute');
        foreach ($attrNodes as $attr) {
            $name = $attr->getAttribute('Name');
            $valueNodes = $xpath->query('saml:AttributeValue', $attr);
            if ($valueNodes->length > 0) {
                $attributes[$name] = trim($valueNodes->item(0)->textContent);
            }
        }

        $email = $attributes[$attrEmail] ?? null;
        if (!$email) {
            $nameIdNodes = $xpath->query('//saml:Subject/saml:NameID');
            if ($nameIdNodes->length > 0) {
                $email = trim($nameIdNodes->item(0)->textContent);
            }
        }

        if (!$email) {
            throw new \RuntimeException('SAML assertion missing email attribute.');
        }

        return [
            'email' => $email,
            'name' => $attributes[$attrName] ?? '',
            'role' => $attributes[$attrRole] ?? '',
            'attributes' => $attributes,
        ];
    }

    /**
     * Validate a JWT token and extract user attributes.
     *
     * @param  string $token
     * @return array  Array with 'email', 'name', 'role', 'claims'
     * @throws \RuntimeException
     */
    public function validateJwtToken($token) {
        $config = $this->getConfig();

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT: expected 3 segments.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (!$header || !isset($header['alg'])) {
            throw new \RuntimeException('Invalid JWT: malformed header.');
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!$payload) {
            throw new \RuntimeException('Invalid JWT: malformed payload.');
        }

        $secret = $config['sso_jwt_secret'];
        $algorithm = $config['sso_jwt_algorithm'] ?: 'HS256';

        if ($secret === '') {
            throw new \RuntimeException('JWT secret is not configured.');
        }

        $signature = $this->base64UrlDecode($signatureB64);
        $signingInput = $headerB64 . '.' . $payloadB64;

        if (!$this->verifyJwtSignature($signingInput, $signature, $secret, $algorithm)) {
            throw new \RuntimeException('Invalid JWT: signature verification failed.');
        }

        $now = time();
        $skew = 60;

        if (isset($payload['exp']) && $payload['exp'] < ($now - $skew)) {
            throw new \RuntimeException('JWT has expired.');
        }

        if (isset($payload['nbf']) && $payload['nbf'] > ($now + $skew)) {
            throw new \RuntimeException('JWT is not yet valid.');
        }

        $attrEmail = $config['sso_attr_email'];
        $attrName = $config['sso_attr_name'];
        $attrRole = $config['sso_attr_role'];

        $email = $payload[$attrEmail] ?? $payload['email'] ?? $payload['sub'] ?? null;
        if (!$email) {
            throw new \RuntimeException('JWT missing email claim.');
        }

        return [
            'email' => $email,
            'name' => $payload[$attrName] ?? $payload['name'] ?? '',
            'role' => $payload[$attrRole] ?? $payload['role'] ?? '',
            'claims' => $payload,
        ];
    }

    /**
     * Verify JWT signature.
     */
    private function verifyJwtSignature($input, $signature, $secret, $algorithm) {
        $algoMap = [
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
        ];

        if (isset($algoMap[$algorithm])) {
            $expected = hash_hmac($algoMap[$algorithm], $input, $secret, true);
            return hash_equals($expected, $signature);
        }

        throw new \RuntimeException("Unsupported JWT algorithm: {$algorithm}");
    }

    /**
     * Base64url decode.
     */
    private function base64UrlDecode($input) {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'), true) ?: '';
    }
}
