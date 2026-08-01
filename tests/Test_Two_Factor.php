<?php

/**
 * Tests for two-factor authentication: the TOTP service (RFC 6238), the
 * TwoFactor model (at-rest encryption + single-use recovery codes), and the
 * REST controller flow (setup → confirm → verify → disable).
 */

use Escalated\Models\ApiToken;
use Escalated\Models\TwoFactor;
use Escalated\Services\TwoFactorService;

class Test_Two_Factor extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // TwoFactorService — pure TOTP algorithm
    // ---------------------------------------------------------------------

    private function service(): TwoFactorService
    {
        return new TwoFactorService;
    }

    public function test_generate_secret_is_16_char_base32(): void
    {
        $secret = $this->service()->generate_secret();

        $this->assertSame(16, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16}$/', $secret);
    }

    /**
     * RFC 6238 Appendix B test vectors (SHA1 seed "12345678901234567890",
     * base32 "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"). The published 8-digit
     * values truncated to the low 6 digits.
     */
    public function test_generate_totp_matches_rfc6238_vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $service = $this->service();

        $vectors = [
            1 => '287082',          // T = 59
            37037036 => '081804',   // T = 1111111109
            41152263 => '005924',   // T = 1234567890
            66666666 => '279037',   // T = 2000000000
            666666666 => '353130',  // T = 20000000000
        ];

        foreach ($vectors as $time_slice => $expected) {
            $this->assertSame(
                $expected,
                $service->generate_totp($secret, $time_slice),
                "TOTP for time slice {$time_slice} should match the RFC 6238 vector."
            );
        }
    }

    public function test_verify_accepts_current_code(): void
    {
        $service = $this->service();
        $secret = $service->generate_secret();
        $code = $service->generate_totp($secret, (int) floor(time() / 30));

        $this->assertTrue($service->verify($secret, $code));
    }

    public function test_verify_tolerates_one_period_of_drift(): void
    {
        $service = $this->service();
        $secret = $service->generate_secret();
        $slice = (int) floor(time() / 30);

        $this->assertTrue($service->verify($secret, $service->generate_totp($secret, $slice - 1)));
        $this->assertTrue($service->verify($secret, $service->generate_totp($secret, $slice + 1)));
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $service = $this->service();
        $secret = $service->generate_secret();
        $slice = (int) floor(time() / 30);

        // Build the set of codes the verifier would currently accept, then
        // pick one guaranteed not in it — deterministic, no flakiness.
        $accepted = [
            $service->generate_totp($secret, $slice - 1),
            $service->generate_totp($secret, $slice),
            $service->generate_totp($secret, $slice + 1),
        ];

        $wrong = null;
        for ($i = 0; $i < 20; $i++) {
            $candidate = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (! in_array($candidate, $accepted, true)) {
                $wrong = $candidate;
                break;
            }
        }

        $this->assertNotNull($wrong);
        $this->assertFalse($service->verify($secret, $wrong));
    }

    public function test_verify_rejects_non_numeric_and_wrong_length(): void
    {
        $service = $this->service();
        $secret = $service->generate_secret();

        $this->assertFalse($service->verify($secret, 'abcdef'));
        $this->assertFalse($service->verify($secret, '12345'));
        $this->assertFalse($service->verify($secret, ''));
    }

    public function test_qr_uri_is_well_formed(): void
    {
        $service = $this->service();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $uri = $service->generate_qr_uri($secret, 'agent@example.com');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_generate_recovery_codes_shape(): void
    {
        $codes = $this->service()->generate_recovery_codes();

        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{8}$/', $code);
        }
        // All codes are distinct.
        $this->assertSame($codes, array_values(array_unique($codes)));
    }

    // ---------------------------------------------------------------------
    // TwoFactor model — encryption at rest + single-use recovery codes
    // ---------------------------------------------------------------------

    public function test_secret_is_encrypted_at_rest_and_recoverable(): void
    {
        global $wpdb;

        $secret = $this->service()->generate_secret();
        $id = TwoFactor::create([
            'user_id' => 1,
            'secret' => $secret,
            'recovery_codes' => ['AAAAAAAA-BBBBBBBB'],
        ]);
        $this->assertIsInt($id);

        $raw = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM '.TwoFactor::table().' WHERE id = %d', $id)
        );

        // Stored secret must not be the plaintext.
        $this->assertNotSame($secret, $raw->secret);
        $this->assertStringNotContainsString($secret, $raw->secret);

        // But it decrypts back to the original.
        $this->assertSame($secret, TwoFactor::secret_of($raw));
    }

    public function test_recovery_codes_stored_as_hashes_not_plaintext(): void
    {
        global $wpdb;

        $plain = 'DEADBEEF-CAFEBABE';
        $id = TwoFactor::create([
            'user_id' => 2,
            'secret' => 'SECRETSECRETSECR',
            'recovery_codes' => [$plain],
        ]);

        $raw = $wpdb->get_row(
            $wpdb->prepare('SELECT recovery_codes FROM '.TwoFactor::table().' WHERE id = %d', $id)
        );

        $this->assertStringNotContainsString($plain, $raw->recovery_codes);
        $this->assertStringContainsString(TwoFactor::hash_recovery_code($plain), $raw->recovery_codes);
    }

    public function test_recovery_code_is_single_use(): void
    {
        $codes = ['AAAA1111-BBBB2222', 'CCCC3333-DDDD4444'];
        $id = TwoFactor::create([
            'user_id' => 3,
            'secret' => 'SECRETSECRETSECR',
            'recovery_codes' => $codes,
        ]);
        TwoFactor::confirm($id);

        $this->assertSame(2, TwoFactor::remaining_recovery_codes(TwoFactor::find($id)));

        // First use succeeds.
        $this->assertTrue(TwoFactor::consume_recovery_code($id, 'AAAA1111-BBBB2222'));
        $this->assertSame(1, TwoFactor::remaining_recovery_codes(TwoFactor::find($id)));

        // Re-using the same code fails.
        $this->assertFalse(TwoFactor::consume_recovery_code($id, 'AAAA1111-BBBB2222'));

        // A different valid code still works.
        $this->assertTrue(TwoFactor::consume_recovery_code($id, 'CCCC3333-DDDD4444'));
        $this->assertSame(0, TwoFactor::remaining_recovery_codes(TwoFactor::find($id)));
    }

    public function test_recovery_code_match_is_case_insensitive(): void
    {
        $id = TwoFactor::create([
            'user_id' => 4,
            'secret' => 'SECRETSECRETSECR',
            'recovery_codes' => ['ABCD1234-EF567890'],
        ]);

        $this->assertTrue(TwoFactor::consume_recovery_code($id, 'abcd1234-ef567890'));
    }

    public function test_confirm_sets_confirmed_state(): void
    {
        $id = TwoFactor::create([
            'user_id' => 5,
            'secret' => 'SECRETSECRETSECR',
            'recovery_codes' => ['AAAA1111-BBBB2222'],
        ]);

        $this->assertFalse(TwoFactor::is_confirmed(TwoFactor::find($id)));
        TwoFactor::confirm($id);
        $this->assertTrue(TwoFactor::is_confirmed(TwoFactor::find($id)));
    }

    // ---------------------------------------------------------------------
    // REST controller — full self-service flow
    // ---------------------------------------------------------------------

    private function authed_user(): array
    {
        $user_id = $this->factory->user->create(['role' => 'escalated_agent']);
        $result = ApiToken::create_token($user_id, 'test-2fa', ['*']);

        return [$user_id, $result['token']];
    }

    private function request(string $method, string $route, ?string $token = null, array $body = []): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        if ($token !== null) {
            $request->set_header('Authorization', 'Bearer '.$token);
        }
        if (! empty($body)) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return $request;
    }

    public function test_status_requires_authentication(): void
    {
        $response = $this->server->dispatch(
            $this->request('GET', '/escalated/v1/admin/two-factor')
        );

        $this->assertSame(401, $response->get_status());
    }

    public function test_setup_returns_secret_qr_and_recovery_codes(): void
    {
        [, $token] = $this->authed_user();

        $response = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/setup', $token)
        );

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16}$/', $data['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $data['qr_uri']);
        $this->assertCount(8, $data['recovery_codes']);
    }

    public function test_full_enable_verify_and_disable_flow(): void
    {
        [, $token] = $this->authed_user();
        $service = $this->service();

        // 1. Setup.
        $setup = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/setup', $token)
        );
        $this->assertSame(201, $setup->get_status());
        $secret = $setup->get_data()['secret'];
        $recovery_codes = $setup->get_data()['recovery_codes'];

        // Status: pending, not enabled.
        $status = $this->server->dispatch(
            $this->request('GET', '/escalated/v1/admin/two-factor', $token)
        )->get_data();
        $this->assertFalse($status['enabled']);
        $this->assertTrue($status['pending']);

        // 2. Confirm with a valid current code.
        $code = $service->generate_totp($secret, (int) floor(time() / 30));
        $confirm = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/confirm', $token, ['code' => $code])
        );
        $this->assertSame(200, $confirm->get_status());
        $this->assertTrue($confirm->get_data()['enabled']);

        // Status: enabled.
        $status = $this->server->dispatch(
            $this->request('GET', '/escalated/v1/admin/two-factor', $token)
        )->get_data();
        $this->assertTrue($status['enabled']);
        $this->assertFalse($status['pending']);
        $this->assertSame(8, $status['recovery_codes_remaining']);

        // 3. Verify (challenge) with a TOTP code.
        $code = $service->generate_totp($secret, (int) floor(time() / 30));
        $verify = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/verify', $token, ['code' => $code])
        );
        $this->assertSame(200, $verify->get_status());
        $this->assertSame('totp', $verify->get_data()['method']);

        // 4. Verify with a recovery code — consumed, single-use.
        $verify = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/verify', $token, ['code' => $recovery_codes[0]])
        );
        $this->assertSame(200, $verify->get_status());
        $this->assertSame('recovery_code', $verify->get_data()['method']);
        $this->assertSame(7, $verify->get_data()['recovery_codes_remaining']);

        // Same recovery code rejected the second time.
        $verify = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/verify', $token, ['code' => $recovery_codes[0]])
        );
        $this->assertSame(422, $verify->get_status());

        // 5. Disable.
        $disable = $this->server->dispatch(
            $this->request('DELETE', '/escalated/v1/admin/two-factor', $token)
        );
        $this->assertSame(200, $disable->get_status());

        $status = $this->server->dispatch(
            $this->request('GET', '/escalated/v1/admin/two-factor', $token)
        )->get_data();
        $this->assertFalse($status['enabled']);
    }

    public function test_confirm_rejects_wrong_code(): void
    {
        [, $token] = $this->authed_user();

        $setup = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/setup', $token)
        );
        $secret = $setup->get_data()['secret'];

        // Find a code the verifier will reject for the current window.
        $service = $this->service();
        $slice = (int) floor(time() / 30);
        $accepted = [
            $service->generate_totp($secret, $slice - 1),
            $service->generate_totp($secret, $slice),
            $service->generate_totp($secret, $slice + 1),
        ];
        $wrong = '000000';
        for ($i = 0; $i < 20; $i++) {
            $candidate = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (! in_array($candidate, $accepted, true)) {
                $wrong = $candidate;
                break;
            }
        }

        $confirm = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/confirm', $token, ['code' => $wrong])
        );

        $this->assertSame(422, $confirm->get_status());

        // Still not enabled.
        $status = $this->server->dispatch(
            $this->request('GET', '/escalated/v1/admin/two-factor', $token)
        )->get_data();
        $this->assertFalse($status['enabled']);
    }

    public function test_verify_before_enabled_returns_error(): void
    {
        [, $token] = $this->authed_user();

        $verify = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/verify', $token, ['code' => '123456'])
        );

        $this->assertSame(422, $verify->get_status());
    }

    public function test_regenerate_recovery_codes_replaces_old_set(): void
    {
        [$user_id, $token] = $this->authed_user();

        // Enable directly through the model for a focused test.
        $id = TwoFactor::create([
            'user_id' => $user_id,
            'secret' => $this->service()->generate_secret(),
            'recovery_codes' => ['OLDOLDOL-DOLDOLDO'],
        ]);
        TwoFactor::confirm($id);

        $response = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/recovery-codes', $token)
        );

        $this->assertSame(200, $response->get_status());
        $new_codes = $response->get_data()['recovery_codes'];
        $this->assertCount(8, $new_codes);

        // The old code no longer works; a new one does.
        $this->assertFalse(TwoFactor::consume_recovery_code($id, 'OLDOLDOL-DOLDOLDO'));
        $this->assertTrue(TwoFactor::consume_recovery_code($id, $new_codes[0]));
    }

    public function test_setup_blocked_when_already_enabled(): void
    {
        [$user_id, $token] = $this->authed_user();

        $id = TwoFactor::create([
            'user_id' => $user_id,
            'secret' => $this->service()->generate_secret(),
            'recovery_codes' => ['AAAA1111-BBBB2222'],
        ]);
        TwoFactor::confirm($id);

        $response = $this->server->dispatch(
            $this->request('POST', '/escalated/v1/admin/two-factor/setup', $token)
        );

        $this->assertSame(409, $response->get_status());
    }
}
