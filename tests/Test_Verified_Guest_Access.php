<?php

/**
 * Verified guest access (docs/guest-access.md), through the real REST
 * routes: emailed codes, expiring access grants, /verification and /lookup
 * for the widget and the guest pages, and the legacy-token switch.
 *
 * Codes are read from the email the plugin sends (pre_wp_mail).
 */

use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\GuestAccess;
use Escalated\Services\GuestEmailVerification;

class Test_Verified_Guest_Access extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    /** @var array<int, array{to: string, message: string}> */
    private array $mail = [];

    private ?string $previous_ip;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');
        wp_set_current_user(0);

        $this->previous_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.'.wp_rand(1, 254);
        // The per-IP guest throttles have their own tests.
        add_filter('escalated_guest_rate_limit', [$this, 'no_guest_throttle']);

        $this->mail = [];
        add_filter('pre_wp_mail', [$this, 'capture_mail'], 10, 2);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_filter('pre_wp_mail', [$this, 'capture_mail'], 10);
        remove_filter('escalated_guest_rate_limit', [$this, 'no_guest_throttle']);
        if ($this->previous_ip === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previous_ip;
        }

        parent::tear_down();
    }

    public function no_guest_throttle(array $config): array
    {
        $config['enabled'] = false;

        return $config;
    }

    public function capture_mail($return, $atts)
    {
        $this->mail[] = ['to' => (string) $atts['to'], 'message' => (string) $atts['message']];

        return true;
    }

    // =========================================================================
    // Codes
    // =========================================================================

    public function test_a_code_is_emailed_and_only_its_id_is_returned(): void
    {
        $response = $this->post('/widget/verification', ['email' => 'Guest@Example.com', 'purpose' => 'ticket']);

        $this->assertSame(202, $response->get_status());
        $data = $response->get_data();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['verification_id']);
        $this->assertSame(600, $data['expires_in']);
        $this->assertNotEmpty($data['message']);
        $this->assertSame('no-store', $response->get_headers()['Cache-Control']);

        $this->assertCount(1, $this->mail);
        $this->assertSame('guest@example.com', $this->mail[0]['to']);
        $this->assertMatchesRegularExpression('/\b\d{8}\b/', $this->mail[0]['message']);
        $this->assertStringNotContainsString($this->last_code(), wp_json_encode($data));
    }

    /**
     * @dataProvider bad_code_requests
     */
    public function test_bad_code_requests_are_refused(array $body, string $field): void
    {
        $response = $this->post('/widget/verification', $body);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey($field, $response->get_data()['errors']);
        $this->assertSame([], $this->mail);
    }

    public static function bad_code_requests(): array
    {
        return [
            'no email' => [['purpose' => 'ticket'], 'email'],
            'bad email' => [['email' => 'not-an-email', 'purpose' => 'ticket'], 'email'],
            'bad purpose' => [['email' => 'a@example.com', 'purpose' => 'admin'], 'purpose'],
        ];
    }

    public function test_no_codes_while_guest_tickets_are_off(): void
    {
        Setting::set('guest_tickets_enabled', '0');

        $this->assertSame(403, $this->post('/widget/verification', ['email' => 'a@example.com', 'purpose' => 'ticket'])->get_status());
        $this->assertSame(403, $this->post('/guest/verification', ['email' => 'a@example.com', 'purpose' => 'ticket'])->get_status());
        $this->assertSame([], $this->mail);
    }

    public function test_code_budgets_per_client_and_per_mailbox(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(202, $this->post('/widget/verification', ['email' => 'busy@example.com', 'purpose' => 'ticket'])->get_status());
        }
        $limited = $this->post('/widget/verification', ['email' => 'busy@example.com', 'purpose' => 'lookup']);
        $this->assertSame(429, $limited->get_status());
        $this->assertGreaterThan(0, (int) $limited->get_headers()['Retry-After']);

        // Another address from the same client is not affected.
        $this->assertSame(202, $this->post('/widget/verification', ['email' => 'other@example.com', 'purpose' => 'ticket'])->get_status());

        // Other clients can still get codes for the mailbox, up to ten an hour.
        for ($i = 0; $i < 7; $i++) {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.'.($i + 1);
            $this->assertSame(202, $this->post('/widget/verification', ['email' => 'busy@example.com', 'purpose' => 'ticket'])->get_status());
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.200';
        $this->assertSame(429, $this->post('/widget/verification', ['email' => 'busy@example.com', 'purpose' => 'ticket'])->get_status());
    }

    public function test_a_code_allows_five_guesses_and_is_used_once(): void
    {
        $verifier = new GuestEmailVerification;
        $proof = $this->proof('five@example.com', 'ticket');

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($verifier->consume($proof['verification_id'], '00000000' === $proof['verification_code'] ? '11111111' : '00000000', 'five@example.com', 'ticket'));
        }
        $this->assertFalse($verifier->consume($proof['verification_id'], $proof['verification_code'], 'five@example.com', 'ticket'), 'A sixth guess was allowed.');

        $proof = $this->proof('once@example.com', 'ticket');
        $this->assertTrue($verifier->consume($proof['verification_id'], $proof['verification_code'], 'ONCE@example.com', 'ticket'));
        $this->assertFalse($verifier->consume($proof['verification_id'], $proof['verification_code'], 'once@example.com', 'ticket'));
    }

    public function test_a_code_is_bound_to_its_email_purpose_and_ten_minutes(): void
    {
        $verifier = new GuestEmailVerification;

        $proof = $this->proof('bound@example.com', 'chat');
        $this->assertFalse($verifier->consume($proof['verification_id'], $proof['verification_code'], 'bound@example.com', 'ticket'));
        $this->assertFalse($verifier->consume($proof['verification_id'], $proof['verification_code'], 'someone@example.com', 'chat'));
        $this->assertTrue($verifier->consume($proof['verification_id'], $proof['verification_code'], 'bound@example.com', 'chat'));

        $proof = $this->proof('late@example.com', 'ticket');
        global $wpdb;
        $wpdb->update(GuestEmailVerification::table(), ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['public_id' => $proof['verification_id']]);
        $this->assertFalse($verifier->consume($proof['verification_id'], $proof['verification_code'], 'late@example.com', 'ticket'));
    }

    public function test_codes_are_stored_only_as_digests(): void
    {
        $proof = $this->proof('digest@example.com', 'ticket');

        global $wpdb;
        $row = (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM '.GuestEmailVerification::table().' WHERE public_id = %s', $proof['verification_id']));
        $this->assertStringNotContainsString($proof['verification_code'], implode('|', array_map('strval', $row)));
        $this->assertStringNotContainsString('digest@example.com', implode('|', array_map('strval', $row)));
    }

    // =========================================================================
    // Widget tickets and grants
    // =========================================================================

    public function test_widget_ticket_needs_a_ticket_code(): void
    {
        $body = ['name' => 'Jane', 'email' => 'jane@example.com', 'subject' => 'Help', 'description' => 'Broken'];

        $this->assertSame(422, $this->post('/widget/tickets', $body)->get_status());

        $lookup_code = $this->proof('jane@example.com', 'lookup');
        $this->assertSame(422, $this->post('/widget/tickets', $body + $lookup_code)->get_status());

        $wrong = $this->proof('jane@example.com', 'ticket');
        $wrong['verification_code'] = $wrong['verification_code'] === '12345678' ? '87654321' : '12345678';
        $response = $this->post('/widget/tickets', $body + $wrong);
        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('verification_code', $response->get_data()['errors']);

        $this->assertSame(0, $this->ticket_count('jane@example.com'));
    }

    public function test_widget_ticket_returns_an_expiring_grant_that_opens_its_status(): void
    {
        $created = $this->create_widget_ticket('jane@example.com');

        $this->assertStringStartsWith('ega1.', $created['guest_access_token']);
        $this->assertEqualsWithDelta(time() + DAY_IN_SECONDS, strtotime($created['expires_at']), 5);
        $ticket = Ticket::find_by_reference($created['reference']);
        $this->assertNotEmpty($ticket->guest_access_hash);
        $this->assertStringNotContainsString($ticket->guest_access_hash, $created['guest_access_token']);

        $status = $this->get_status($created['reference'], $created['guest_access_token']);
        $this->assertSame(200, $status->get_status());
        $this->assertSame('open', $status->get_data()['status']);
        $this->assertSame('no-referrer', $status->get_headers()['Referrer-Policy']);
    }

    public function test_status_needs_a_grant_for_that_ticket(): void
    {
        $first = $this->create_widget_ticket('jane@example.com');
        $second = $this->create_widget_ticket('john@example.com');

        $this->assertSame(403, $this->get_status($second['reference'], $first['guest_access_token'])->get_status());
        $this->assertSame(403, $this->get_status($first['reference'], null)->get_status());

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/tickets/'.$first['reference']);
        $request->set_param('email', 'jane@example.com');
        $this->assertSame(403, $this->server->dispatch($request)->get_status(), 'An email address opened the ticket.');

        foreach ([substr($first['guest_access_token'], 0, -4), 'ega1.'.str_repeat('A', 80), 'ega1.!!'] as $forged) {
            $this->assertSame(403, $this->get_status($first['reference'], $forged)->get_status());
        }
    }

    public function test_a_grant_expires(): void
    {
        $created = $this->create_widget_ticket('jane@example.com');
        Ticket::update(Ticket::find_by_reference($created['reference'])->id, ['guest_access_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);

        $this->assertSame(403, $this->get_status($created['reference'], $created['guest_access_token'])->get_status());
    }

    public function test_the_grant_lifetime_is_a_setting_clamped_to_five_minutes_through_seven_days(): void
    {
        Setting::set('guest_access_ttl_minutes', '1');
        $this->assertSame(5, GuestAccess::ttl_minutes());
        Setting::set('guest_access_ttl_minutes', '999999');
        $this->assertSame(7 * 24 * 60, GuestAccess::ttl_minutes());
        Setting::set('guest_access_ttl_minutes', '60');

        $created = $this->create_widget_ticket('jane@example.com');
        $this->assertEqualsWithDelta(time() + HOUR_IN_SECONDS, strtotime($created['expires_at']), 5);
    }

    public function test_changing_the_guest_email_revokes_grants(): void
    {
        $created = $this->create_widget_ticket('jane@example.com');
        global $wpdb;
        // Even a raw update: the grant carries a digest of the email.
        $wpdb->update(Ticket::table(), ['guest_email' => 'mallory@example.com'], ['reference' => $created['reference']]);

        $this->assertSame(403, $this->get_status($created['reference'], $created['guest_access_token'])->get_status());
    }

    public function test_revoke_ends_every_grant(): void
    {
        $created = $this->create_widget_ticket('jane@example.com');
        GuestAccess::revoke(Ticket::find_by_reference($created['reference']));

        $this->assertSame(403, $this->get_status($created['reference'], $created['guest_access_token'])->get_status());
    }

    public function test_legacy_tokens_work_until_switched_off(): void
    {
        $ticket = (new \Escalated\Services\TicketService)->create_guest([
            'subject' => 'Old', 'description' => 'Old ticket', 'guest_name' => 'Old', 'guest_email' => 'old@example.com',
        ]);

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/tickets/'.$ticket->reference);
        $request->set_param('guest_token', $ticket->guest_token);
        $this->assertSame(200, $this->server->dispatch($request)->get_status());

        Setting::set('guest_legacy_tokens_enabled', '0');
        $this->assertSame(403, $this->server->dispatch($request)->get_status());
        $this->assertSame(403, $this->get_status($ticket->reference, $ticket->guest_token)->get_status());

        $created = $this->create_widget_ticket('new@example.com');
        $this->assertArrayNotHasKey('guest_token', $created);
        $this->assertSame(200, $this->get_status($created['reference'], $created['guest_access_token'])->get_status());
    }

    // =========================================================================
    // Lookup
    // =========================================================================

    public function test_lookup_returns_a_fresh_grant_and_rotates_the_old_one(): void
    {
        $created = $this->create_widget_ticket('jane@example.com');

        $response = $this->post('/widget/lookup', ['email' => 'JANE@example.com', 'reference' => strtolower($created['reference'])] + $this->proof('jane@example.com', 'lookup'));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $this->assertCount(1, $data);
        $this->assertSame($created['reference'], $data[0]['reference']);
        $this->assertSame('Help', $data[0]['subject']);
        $this->assertNotEmpty($data[0]['expires_at']);
        $this->assertSame(200, $this->get_status($created['reference'], $data[0]['guest_access_token'])->get_status());
        $this->assertSame(403, $this->get_status($created['reference'], $created['guest_access_token'])->get_status(), 'The old grant still works.');
    }

    public function test_lookup_needs_the_matching_email_and_a_lookup_code(): void
    {
        $created = $this->create_widget_ticket('jane@example.com');

        $other = $this->post('/widget/lookup', ['email' => 'eve@example.com', 'reference' => $created['reference']] + $this->proof('eve@example.com', 'lookup'));
        $this->assertSame(200, $other->get_status());
        $this->assertSame([], $other->get_data()['data']);

        $ticket_code = $this->post('/widget/lookup', ['email' => 'jane@example.com', 'reference' => $created['reference']] + $this->proof('jane@example.com', 'ticket'));
        $this->assertSame(422, $ticket_code->get_status());

        $no_code = $this->post('/widget/lookup', ['email' => 'jane@example.com', 'reference' => $created['reference']]);
        $this->assertSame(422, $no_code->get_status());
    }

    public function test_guest_lookup_returns_private_links(): void
    {
        $created = $this->create_widget_ticket('jane@example.com');

        $response = $this->post('/guest/lookup', ['email' => 'jane@example.com', 'reference' => $created['reference']] + $this->proof('jane@example.com', 'lookup'));

        $match = $response->get_data()['data'][0];
        $this->assertStringContainsString('guest_token='.rawurlencode($match['guest_access_token']), $match['view_url']);
        $this->assertSame(202, $this->post('/guest/verification', ['email' => 'jane@example.com', 'purpose' => 'lookup'])->get_status());
    }

    // =========================================================================
    // Chat and config
    // =========================================================================

    public function test_chat_start_needs_a_chat_code(): void
    {
        $body = ['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'Hello'];

        $this->assertSame(422, $this->post('/widget/chat/start', $body)->get_status());
        $this->assertSame(422, $this->post('/widget/chat/start', $body + $this->proof('visitor@example.com', 'ticket'))->get_status());
        $this->assertSame(201, $this->post('/widget/chat/start', $body + $this->proof('visitor@example.com', 'chat'))->get_status());
    }

    public function test_config_advertises_verification_and_status_lookup(): void
    {
        $config = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/widget/config'))->get_data();

        $this->assertTrue($config['guest_verification_required']);
        $this->assertTrue($config['status_lookup_enabled']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function post(string $path, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1'.$path);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_ensure_response($this->server->dispatch($request));
    }

    private function get_status(string $reference, ?string $grant): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/widget/tickets/'.$reference);
        if ($grant !== null) {
            $request->set_header('Authorization', 'Bearer '.$grant);
        }

        return rest_ensure_response($this->server->dispatch($request));
    }

    /**
     * @return array{verification_id: string, verification_code: string}
     */
    private function proof(string $email, string $purpose): array
    {
        $response = $this->post('/widget/verification', ['email' => $email, 'purpose' => $purpose]);
        $this->assertSame(202, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return ['verification_id' => $response->get_data()['verification_id'], 'verification_code' => $this->last_code()];
    }

    private function last_code(): string
    {
        $last = end($this->mail);
        preg_match('/\b(\d{8})\b/', $last['message'] ?? '', $m);

        return $m[1] ?? '';
    }

    private function create_widget_ticket(string $email): array
    {
        $response = $this->post('/widget/tickets', [
            'name' => 'Jane',
            'email' => $email,
            'subject' => 'Help',
            'description' => 'Broken',
        ] + $this->proof($email, 'ticket'));
        $this->assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return $response->get_data();
    }

    private function ticket_count(string $email): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Ticket::table().' WHERE guest_email = %s', $email));
    }
}
