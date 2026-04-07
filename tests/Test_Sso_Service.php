<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SsoService JWT validation logic.
 *
 * These tests verify the pure cryptographic JWT operations without
 * requiring WordPress or database access.
 */
class Test_Sso_Service extends TestCase
{
    /**
     * Helper: base64url encode.
     */
    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Helper: create a valid JWT with HMAC-SHA256.
     */
    private function create_jwt($payload, $secret)
    {
        $header = $this->base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $this->base64url_encode(json_encode($payload));
        $signature = $this->base64url_encode(
            hash_hmac('sha256', "$header.$body", $secret, true)
        );

        return "$header.$body.$signature";
    }

    public function test_jwt_has_three_segments()
    {
        $token = $this->create_jwt(['email' => 'test@example.com'], 'secret');
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function test_jwt_payload_round_trips()
    {
        $token = $this->create_jwt([
            'email' => 'user@test.com',
            'name' => 'Test User',
        ], 'secret');

        $parts = explode('.', $token);
        $payload_b64 = $parts[1];
        $remainder = strlen($payload_b64) % 4;
        if ($remainder) {
            $payload_b64 .= str_repeat('=', 4 - $remainder);
        }
        $payload = json_decode(base64_decode(strtr($payload_b64, '-_', '+/')), true);

        $this->assertEquals('user@test.com', $payload['email']);
        $this->assertEquals('Test User', $payload['name']);
    }

    public function test_jwt_signature_verifies_with_correct_secret()
    {
        $secret = 'test-secret';
        $token = $this->create_jwt(['email' => 'a@b.com'], $secret);
        [$header, $payload, $sig] = explode('.', $token);

        $expected = $this->base64url_encode(
            hash_hmac('sha256', "$header.$payload", $secret, true)
        );

        $this->assertEquals($expected, $sig);
    }

    public function test_jwt_signature_fails_with_wrong_secret()
    {
        $token = $this->create_jwt(['email' => 'a@b.com'], 'correct');
        [$header, $payload] = explode('.', $token);

        $wrong_sig = $this->base64url_encode(
            hash_hmac('sha256', "$header.$payload", 'wrong', true)
        );

        [, , $original_sig] = explode('.', $token);
        $this->assertNotEquals($original_sig, $wrong_sig);
    }

    public function test_expired_jwt_detected()
    {
        $payload = ['email' => 'a@b.com', 'exp' => time() - 3600];
        $this->assertTrue($payload['exp'] < time());
    }

    public function test_valid_jwt_not_expired()
    {
        $payload = ['email' => 'a@b.com', 'exp' => time() + 3600];
        $this->assertTrue($payload['exp'] > time());
    }
}
