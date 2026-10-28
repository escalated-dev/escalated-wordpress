<?php

/**
 * The mobile guest API (escalated-developer-context
 * domain-model/mobile-guest-api.md) through the real REST server:
 * /wp-json/escalated/v1/mobile/guest/{verification,lookup,tickets,...}.
 *
 * Codes are read from the email the plugin sends (pre_wp_mail).
 */

use Escalated\Models\Contact;
use Escalated\Models\Reply;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\GuestRateLimiter;

class Test_Mobile_Guest_Api extends WP_UnitTestCase
{
    private const PREFIX = '/escalated/v1/mobile/guest';

    private WP_REST_Server $server;

    /** @var array<int, array{to: string, message: string}> */
    private array $mail = [];

    private ?string $previous_ip;

    /** @var array<string, int>|null */
    private ?array $guest_limits = null;

    /** @var string[] */
    private array $temp_files = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');
        Setting::set('api_rate_limit', '1000');
        wp_set_current_user(0);

        $this->previous_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.'.wp_rand(1, 254);
        $this->clear_counters($_SERVER['REMOTE_ADDR']);
        add_filter('escalated_guest_rate_limit', [$this, 'guest_limit_config']);

        $this->mail = [];
        add_filter('pre_wp_mail', [$this, 'capture_mail'], 10, 2);
        add_filter('escalated_attachment_is_uploaded_file', '__return_true');

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_filter('pre_wp_mail', [$this, 'capture_mail'], 10);
        remove_filter('escalated_guest_rate_limit', [$this, 'guest_limit_config']);
        remove_filter('escalated_attachment_is_uploaded_file', '__return_true');
        $this->clear_counters($_SERVER['REMOTE_ADDR']);
        if ($this->previous_ip === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previous_ip;
        }
        foreach ($this->temp_files as $file) {
            @unlink($file);
        }

        parent::tear_down();
    }

    /** The per-IP guest limits are off unless a test sets them. */
    public function guest_limit_config(array $config): array
    {
        if ($this->guest_limits === null) {
            $config['enabled'] = false;

            return $config;
        }

        return ['enabled' => true] + $this->guest_limits + $config;
    }

    public function capture_mail($return, $atts)
    {
        $this->mail[] = ['to' => (string) $atts['to'], 'message' => (string) $atts['message']];

        return true;
    }

    // =========================================================================
    // Create
    // =========================================================================

    public function test_create_without_proof_is_422_and_creates_nothing(): void
    {
        $response = $this->post('/tickets', $this->ticket_body('nobody@example.com'));

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('verification_code', $response->get_data()['errors']);

        $wrong = $this->proof('nobody@example.com', 'ticket');
        $wrong['verification_code'] = $wrong['verification_code'] === '12345678' ? '87654321' : '12345678';
        $response = $this->post('/tickets', $this->ticket_body('nobody@example.com') + $wrong);
        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('verification_code', $response->get_data()['errors']);

        $lookup_code = $this->proof('nobody@example.com', 'lookup');
        $this->assertSame(422, $this->post('/tickets', $this->ticket_body('nobody@example.com') + $lookup_code)->get_status());

        $this->assertSame(0, $this->ticket_count('nobody@example.com'));
        $this->assertSame(0, $this->contact_count('nobody@example.com'));
    }

    public function test_verification_then_create_returns_a_grant_and_the_code_is_spent(): void
    {
        $verification = $this->post('/verification', ['email' => 'Jane@Example.com', 'purpose' => 'ticket']);
        $this->assertSame(202, $verification->get_status());
        $this->assertSame(600, $verification->get_data()['expires_in']);
        $this->assertNotEmpty($verification->get_data()['verification_id']);
        $proof = ['verification_id' => $verification->get_data()['verification_id'], 'verification_code' => $this->last_code()];

        $before = did_action('escalated_ticket_created');
        $response = $this->post('/tickets', $this->ticket_body(' Jane@Example.com ') + $proof + [
            'priority' => 'high',
            // Anonymous creation cannot set these; unknown fields are ignored.
            'status' => 'closed',
            'assigned_to' => 1,
            'metadata' => ['plan' => 'gold'],
            'external_reference' => 'EXT-1',
            'favourite_colour' => 'blue',
        ]);

        $this->assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame(1, did_action('escalated_ticket_created') - $before);
        $body = $response->get_data();
        $this->assertSame('Ticket created.', $body['message']);
        $data = $body['data'];
        $this->assertStringStartsWith('ega1.', $data['guest_access_token']);
        $this->assertEqualsWithDelta(time() + DAY_IN_SECONDS, strtotime($data['guest_access_expires_at']), 5);
        $this->assertSame(['value' => 'open', 'label' => 'Open'], $data['status']);
        $this->assertSame(['value' => 'high', 'label' => 'High'], $data['priority']);
        $this->assertSame('web', $data['channel']);
        $this->assertSame(['name' => 'Jane', 'email' => 'jane@example.com'], $data['requester']);
        $this->assertNull($data['assignee']);
        $this->assertSame([], $data['replies']);
        $this->assertSame('{}', wp_json_encode($data['metadata']));
        $this->assertMobileTicketShape($data);

        $ticket = Ticket::find_by_reference($data['reference']);
        $this->assertSame('jane@example.com', $ticket->guest_email);
        $this->assertSame('Jane', $ticket->guest_name);
        $this->assertSame('open', $ticket->status);
        $this->assertEmpty($ticket->assigned_to);
        $this->assertEmpty($ticket->metadata);
        $this->assertSame('web', $ticket->channel);
        $this->assertSame(1, $this->contact_count('jane@example.com'));

        $again = $this->post('/tickets', $this->ticket_body('jane@example.com') + $proof);
        $this->assertSame(422, $again->get_status());
        $this->assertArrayHasKey('verification_code', $again->get_data()['errors']);
        $this->assertSame(1, $this->ticket_count('jane@example.com'));
    }

    public function test_priority_defaults_and_department_is_checked(): void
    {
        $created = $this->create('dept@example.com');
        $this->assertSame('medium', $created['priority']['value']);
        $this->assertNull($created['department']);

        global $wpdb;
        $wpdb->insert(\Escalated\Escalated::table('departments'), ['name' => 'Billing', 'slug' => 'billing-'.wp_rand(), 'is_active' => 1]);
        $department_id = (int) $wpdb->insert_id;
        $with = $this->create('dept@example.com', ['department_id' => $department_id]);
        $this->assertSame(['id' => $department_id, 'name' => 'Billing'], $with['department']);

        $unknown = $this->post('/tickets', $this->ticket_body('dept@example.com') + ['department_id' => 999999] + $this->proof('dept@example.com', 'ticket'));
        $this->assertSame(422, $unknown->get_status());
        $this->assertArrayHasKey('department_id', $unknown->get_data()['errors']);
    }

    /**
     * @dataProvider invalid_tickets
     */
    public function test_create_validates_fields_before_spending_the_code(array $override, string $field): void
    {
        $proof = $this->proof('valid@example.com', 'ticket');
        $body = array_merge($this->ticket_body('valid@example.com'), $override);
        $body = array_filter($body, static fn ($v) => $v !== null);

        $response = $this->post('/tickets', $body + $proof);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey($field, $response->get_data()['errors']);
        $this->assertSame(201, $this->post('/tickets', $this->ticket_body('valid@example.com') + $proof)->get_status(), 'A validation error spent the code.');
    }

    public static function invalid_tickets(): array
    {
        return [
            'no name' => [['name' => null], 'name'],
            'no subject' => [['subject' => null], 'subject'],
            'no description' => [['description' => null], 'description'],
            'long subject' => [['subject' => str_repeat('a', 256)], 'subject'],
            'bad priority' => [['priority' => 'whenever'], 'priority'],
        ];
    }

    public function test_create_is_403_while_guest_tickets_are_off(): void
    {
        $proof = $this->proof('off@example.com', 'ticket');
        Setting::set('guest_tickets_enabled', '0');

        $response = $this->post('/tickets', ['name' => ''] + $proof);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('Guest tickets are not enabled.', $response->get_data()['message']);
        $this->assertPrivate($response);

        $this->assertSame(403, $this->post('/verification', ['email' => 'off@example.com', 'purpose' => 'ticket'])->get_status());
        $this->assertSame(403, $this->post('/lookup', ['email' => 'off@example.com', 'reference' => 'ESC-1'])->get_status());
        $this->assertSame(0, $this->ticket_count('off@example.com'));
    }

    public function test_create_stores_attachments_and_limits_their_number(): void
    {
        $response = $this->post('/tickets', $this->ticket_body('files@example.com') + $this->proof('files@example.com', 'ticket'), [$this->upload('notes.txt', 'hello')]);
        $this->assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $ticket = Ticket::find_by_reference($response->get_data()['data']['reference']);
        $this->assertCount(1, \Escalated\Models\Attachment::for_attachable('ticket', (int) $ticket->id));

        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = $this->upload('f'.$i.'.txt', 'x');
        }
        $too_many = $this->post('/tickets', $this->ticket_body('files@example.com') + $this->proof('files@example.com', 'ticket'), $files);
        $this->assertSame(422, $too_many->get_status());
        $this->assertArrayHasKey('attachments', $too_many->get_data()['errors']);

        $blocked = $this->post('/tickets', $this->ticket_body('files@example.com') + $this->proof('files@example.com', 'ticket'), [$this->upload('evil.php', '<?php')]);
        $this->assertSame(422, $blocked->get_status());
        $this->assertSame(1, $this->ticket_count('files@example.com'));
    }

    // =========================================================================
    // Grants across surfaces
    // =========================================================================

    public function test_a_mobile_grant_opens_show_the_widget_status_and_the_guest_page(): void
    {
        $created = $this->create('cross@example.com');
        $token = $created['guest_access_token'];

        $show = $this->get('/tickets/'.$token);
        $this->assertSame(200, $show->get_status());
        $this->assertSame($token, $show->get_data()['data']['guest_access_token']);
        $this->assertSame($created['reference'], $show->get_data()['data']['reference']);
        $this->assertNotNull($show->get_data()['data']['guest_access_expires_at']);
        $this->assertMobileTicketShape($show->get_data()['data']);

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/tickets/'.$created['reference']);
        $request->set_header('Authorization', 'Bearer '.$token);
        $this->assertSame(200, $this->server->dispatch($request)->get_status());

        $_GET = ['guest_token' => $token];
        $html = (new \Escalated\Frontend\Shortcodes)->render_view_ticket([]);
        $_GET = [];
        $this->assertStringContainsString('Mobile help', $html);
    }

    public function test_widget_and_lookup_grants_open_the_mobile_routes(): void
    {
        $widget = $this->post_to('/escalated/v1/widget/tickets', [
            'name' => 'Wanda', 'email' => 'wanda@example.com', 'subject' => 'From widget', 'description' => 'Hi',
        ] + $this->proof('wanda@example.com', 'ticket'));
        $this->assertSame(201, $widget->get_status());
        $widget_token = $widget->get_data()['guest_access_token'];
        $this->assertSame(200, $this->get('/tickets/'.$widget_token)->get_status());

        $lookup = $this->post('/lookup', ['email' => 'WANDA@example.com', 'reference' => $widget->get_data()['reference']] + $this->proof('wanda@example.com', 'lookup'));
        $this->assertSame(200, $lookup->get_status());
        $this->assertPrivate($lookup);
        $match = $lookup->get_data()['data'][0];
        $this->assertSame($widget->get_data()['reference'], $match['reference']);
        $this->assertSame('From widget', $match['subject']);
        $this->assertNotEmpty($match['expires_at']);

        $this->assertSame(200, $this->get('/tickets/'.$match['guest_access_token'])->get_status());
        // Issuing a grant rotates the ticket's grant.
        $this->assertSame(404, $this->get('/tickets/'.$widget_token)->get_status());

        $empty = $this->post('/lookup', ['email' => 'eve@example.com', 'reference' => $widget->get_data()['reference']] + $this->proof('eve@example.com', 'lookup'));
        $this->assertSame(200, $empty->get_status());
        $this->assertSame([], $empty->get_data()['data']);

        $bad = $this->proof('wanda@example.com', 'lookup');
        $bad['verification_code'] = $bad['verification_code'] === '12345678' ? '87654321' : '12345678';
        $bad_lookup = $this->post('/lookup', ['email' => 'wanda@example.com', 'reference' => $widget->get_data()['reference']] + $bad);
        $this->assertSame(422, $bad_lookup->get_status());
        $this->assertArrayHasKey('verification_code', $bad_lookup->get_data()['errors']);
    }

    public function test_bad_expired_and_chat_grants_are_404(): void
    {
        $created = $this->create('probe@example.com');
        $token = $created['guest_access_token'];

        $chat = $this->post_to('/escalated/v1/widget/chat/start', ['name' => 'Visitor', 'email' => 'probe@example.com', 'message' => 'Hello'] + $this->proof('probe@example.com', 'chat'));
        $this->assertSame(201, $chat->get_status());

        $forged = [
            'chat' => (string) $chat->get_data()['id'],
            'truncated' => substr($token, 0, -4),
            'garbage' => 'ega1.'.str_repeat('A', 80),
            'malformed' => 'ega1.!!',
            'over-long' => $token.str_repeat('A', 5000),
            'legacy' => (string) Ticket::find_by_reference($created['reference'])->guest_token,
        ];
        foreach ($forged as $label => $grant) {
            $show = $this->get('/tickets/'.rawurlencode($grant));
            $this->assertSame(404, $show->get_status(), $label);
            $this->assertPrivate($show);
            $this->assertSame(404, $this->post('/tickets/'.rawurlencode($grant).'/replies', ['email' => 'probe@example.com', 'body' => 'Hi'])->get_status(), $label);
        }

        Ticket::update(Ticket::find_by_reference($created['reference'])->id, ['guest_access_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->assertSame(404, $this->get('/tickets/'.$token)->get_status());
        $this->assertSame(404, $this->post('/tickets/'.$token.'/replies', ['email' => 'probe@example.com', 'body' => 'Hi'])->get_status());
    }

    // =========================================================================
    // Replies
    // =========================================================================

    public function test_guest_replies_need_the_ticket_email_and_an_open_ticket(): void
    {
        $created = $this->create('reply@example.com');
        $token = $created['guest_access_token'];

        $before = did_action('escalated_reply_created');
        $ok = $this->post('/tickets/'.$token.'/replies', ['email' => '  REPLY@Example.com ', 'body' => 'Any news?']);
        $this->assertSame(201, $ok->get_status(), (string) wp_json_encode($ok->get_data()));
        $this->assertSame(['message' => 'Reply sent.'], $ok->get_data());
        $this->assertPrivate($ok);
        $this->assertSame(1, did_action('escalated_reply_created') - $before);

        $mismatch = $this->post('/tickets/'.$token.'/replies', ['email' => 'mallory@example.com', 'body' => 'Let me in']);
        $this->assertSame(403, $mismatch->get_status());
        $this->assertSame('The provided email does not match this ticket.', $mismatch->get_data()['message']);

        $invalid = $this->post('/tickets/'.$token.'/replies', ['email' => 'reply@example.com']);
        $this->assertSame(422, $invalid->get_status());
        $this->assertArrayHasKey('body', $invalid->get_data()['errors']);

        $replies = Reply::for_ticket((int) $created['id']);
        $this->assertCount(1, $replies);
        $this->assertNull($replies[0]->author_id);
        $this->assertSame('0', (string) $replies[0]->is_internal_note);

        Ticket::update((int) $created['id'], ['status' => 'closed']);
        $closed = $this->post('/tickets/'.$token.'/replies', ['email' => 'reply@example.com', 'body' => 'Again']);
        $this->assertSame(422, $closed->get_status());
        $this->assertSame('This ticket is closed.', $closed->get_data()['message']);
        $this->assertCount(1, Reply::for_ticket((int) $created['id']));
    }

    public function test_show_lists_public_replies_newest_first_and_never_internal_notes(): void
    {
        $created = $this->create('notes@example.com');
        $token = $created['guest_access_token'];
        $agent = self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Agent Smith', 'user_email' => 'smith@corp.example']);
        Ticket::update((int) $created['id'], ['assigned_to' => $agent, 'metadata' => wp_json_encode(['secret' => 'x'])]);

        $this->assertSame(201, $this->post('/tickets/'.$token.'/replies', ['email' => 'notes@example.com', 'body' => 'First from guest'])->get_status());
        $service = new \Escalated\Services\TicketService;
        $staff_reply = $service->reply((int) $created['id'], $agent, 'Second from staff');
        $service->add_note((int) $created['id'], $agent, 'Internal: do not show');
        global $wpdb;
        $wpdb->update(Reply::table(), ['created_at' => gmdate('Y-m-d H:i:s', time() + 60)], ['id' => $staff_reply->id]);

        $response = $this->get('/tickets/'.$token);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'];
        $json = (string) wp_json_encode($data);

        $this->assertStringNotContainsString('Internal: do not show', $json);
        $this->assertStringNotContainsString('smith@corp.example', $json);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertCount(2, $data['replies']);
        $this->assertSame('Second from staff', $data['replies'][0]['body']);
        $this->assertSame(['id' => 0, 'name' => 'Agent Smith', 'email' => ''], $data['replies'][0]['author']);
        $this->assertSame('First from guest', $data['replies'][1]['body']);
        $this->assertSame(['id' => 0, 'name' => 'Jane', 'email' => 'notes@example.com'], $data['replies'][1]['author']);
        $this->assertFalse($data['replies'][1]['is_internal_note']);
        $this->assertSame(['id' => 0, 'name' => 'Agent Smith', 'email' => ''], $data['assignee']);
        $this->assertSame('{}', wp_json_encode($data['metadata']));
    }

    public function test_reply_attachments_get_grant_bound_download_links(): void
    {
        $created = $this->create('attach@example.com');
        $token = $created['guest_access_token'];

        $sent = $this->post('/tickets/'.$token.'/replies', ['email' => 'attach@example.com', 'body' => 'See file'], [$this->upload('log.txt', 'log contents')]);
        $this->assertSame(201, $sent->get_status(), (string) wp_json_encode($sent->get_data()));

        $attachment = $this->get('/tickets/'.$token)->get_data()['data']['replies'][0]['attachments'][0];
        $this->assertSame('log.txt', $attachment['filename']);
        $this->assertSame(12, $attachment['size']);
        $this->assertSame(['id', 'filename', 'mime_type', 'size', 'url'], array_keys($attachment));

        $download = $this->download($attachment['url']);
        $this->assertSame(200, $download->get_status());
        $this->assertSame('log contents', $download->get_data());

        // A forged signature does not open it.
        $this->assertNotSame(200, $this->download(add_query_arg('guest', str_repeat('0', 64), $attachment['url']))->get_status());

        // A guest link never serves an internal note's file.
        $agent = self::factory()->user->create(['role' => 'administrator']);
        $note = (new \Escalated\Services\TicketService)->add_note((int) $created['id'], $agent, 'Internal');
        $note_file = (new \Escalated\Services\AttachmentService)->store_contents('reply', (int) $note->id, 'secret.txt', 'internal only');
        $ticket = Ticket::find((int) $created['id']);
        $this->assertNotSame(200, $this->download(\Escalated\Services\AttachmentService::guest_download_url($note_file, $ticket))->get_status());

        // Rotating the grant (a lookup) ends the old links.
        $this->post('/lookup', ['email' => 'attach@example.com', 'reference' => $created['reference']] + $this->proof('attach@example.com', 'lookup'));
        $this->assertNotSame(200, $this->download($attachment['url'])->get_status());
    }

    // =========================================================================
    // Rate limits and headers
    // =========================================================================

    public function test_the_guest_submission_budget_answers_429_and_is_shared_with_the_widget(): void
    {
        $proof = $this->proof('budget@example.com', 'ticket');
        $this->guest_limits = ['tickets_per_minute' => 1, 'replies_per_minute' => 100];

        // The widget create spends this client's only submission.
        $widget = $this->post_to('/escalated/v1/widget/tickets', $this->ticket_body('budget@example.com') + $proof);
        $this->assertSame(201, $widget->get_status());

        foreach (['/verification' => ['email' => 'budget@example.com', 'purpose' => 'ticket'], '/tickets' => $this->ticket_body('budget@example.com')] as $path => $body) {
            $limited = $this->post($path, $body);
            $this->assertSame(429, $limited->get_status(), $path);
            $this->assertGreaterThan(0, (int) $limited->get_headers()['Retry-After']);
            $this->assertPrivate($limited);
        }
    }

    public function test_the_guest_reply_budget_covers_lookup_and_replies(): void
    {
        $created = $this->create('replies@example.com');
        $this->guest_limits = ['tickets_per_minute' => 100, 'replies_per_minute' => 1];

        $this->assertSame(201, $this->post('/tickets/'.$created['guest_access_token'].'/replies', ['email' => 'replies@example.com', 'body' => 'One'])->get_status());
        $second = $this->post('/tickets/'.$created['guest_access_token'].'/replies', ['email' => 'replies@example.com', 'body' => 'Two']);
        $this->assertSame(429, $second->get_status());
        $this->assertGreaterThan(0, (int) $second->get_headers()['Retry-After']);
        $this->assertSame(429, $this->post('/lookup', ['email' => 'replies@example.com', 'reference' => $created['reference']])->get_status());

        // Show only counts against the API limit.
        $this->assertSame(200, $this->get('/tickets/'.$created['guest_access_token'])->get_status());
    }

    public function test_the_api_limit_applies_per_ip_with_rate_limit_headers(): void
    {
        $created = $this->create('api@example.com');
        Setting::set('api_rate_limit', '2');
        $this->clear_counters($_SERVER['REMOTE_ADDR']);

        $first = $this->get('/tickets/'.$created['guest_access_token']);
        $this->assertSame(200, $first->get_status());
        $this->assertSame('2', (string) $first->get_headers()['X-RateLimit-Limit']);
        $this->assertSame('1', (string) $first->get_headers()['X-RateLimit-Remaining']);
        $this->assertSame(404, $this->get('/tickets/ega1.nope')->get_status());

        $limited = $this->get('/tickets/'.$created['guest_access_token']);
        $this->assertSame(429, $limited->get_status());
        $this->assertSame('Too many requests.', $limited->get_data()['message']);
        $this->assertGreaterThan(0, $limited->get_data()['retry_after']);
        $this->assertGreaterThan(0, (int) $limited->get_headers()['Retry-After']);
        $this->assertSame('0', (string) $limited->get_headers()['X-RateLimit-Remaining']);
        $this->assertPrivate($limited);

        // Another client has its own budget.
        $_SERVER['REMOTE_ADDR'] = '192.0.2.255';
        $this->clear_counters('192.0.2.255');
        $this->assertSame(200, $this->get('/tickets/'.$created['guest_access_token'])->get_status());
        $this->clear_counters('192.0.2.255');
    }

    public function test_every_answer_carries_the_privacy_headers(): void
    {
        $this->assertPrivate($this->post('/verification', ['email' => 'h@example.com', 'purpose' => 'ticket']));
        $this->assertPrivate($this->post('/verification', ['email' => 'bad', 'purpose' => 'ticket']));
        $this->assertPrivate($this->post('/tickets', []));
        $this->assertPrivate($this->post('/lookup', []));
        $this->assertPrivate($this->get('/tickets/ega1.nope'));
    }

    public function test_the_routes_live_under_the_plugin_rest_namespace(): void
    {
        $routes = array_keys($this->server->get_routes('escalated/v1'));

        foreach (['/escalated/v1/mobile/guest/verification', '/escalated/v1/mobile/guest/lookup', '/escalated/v1/mobile/guest/tickets'] as $route) {
            $this->assertContains($route, $routes);
        }
        $this->assertNotEmpty(preg_grep('#^/escalated/v1/mobile/guest/tickets/\(\?P<token>#', $routes));

        // The widget switch is a separate surface: the mobile routes do not depend on it.
        Setting::set('widget_enabled', '0');
        $this->assertSame(202, $this->post('/verification', ['email' => 'nowidget@example.com', 'purpose' => 'ticket'])->get_status());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function assertMobileTicketShape(array $data): void
    {
        $this->assertSame([
            'id', 'reference', 'guest_access_token', 'guest_access_expires_at', 'subject', 'description', 'status',
            'priority', 'channel', 'metadata', 'requester', 'assignee', 'department', 'tags', 'replies',
            'activities', 'sla', 'is_following', 'followers_count', 'resolved_at', 'closed_at', 'created_at', 'updated_at',
        ], array_keys($data));
        $this->assertIsInt($data['id']);
        $this->assertSame([], $data['tags']);
        $this->assertSame([], $data['activities']);
        $this->assertFalse($data['is_following']);
        $this->assertSame(0, $data['followers_count']);
        $this->assertSame(['first_response_due_at', 'first_response_at', 'first_response_breached', 'resolution_due_at', 'resolution_breached'], array_keys($data['sla']));
        $this->assertIsBool($data['sla']['first_response_breached']);
        $this->assertIsBool($data['sla']['resolution_breached']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['updated_at']);
    }

    private function assertPrivate(WP_REST_Response $response): void
    {
        $headers = $response->get_headers();
        $this->assertSame('private, no-store', $headers['Cache-Control'] ?? null);
        $this->assertSame('no-referrer', $headers['Referrer-Policy'] ?? null);
    }

    private function ticket_body(string $email): array
    {
        return ['name' => 'Jane', 'email' => $email, 'subject' => 'Mobile help', 'description' => 'It broke'];
    }

    /**
     * Create a ticket through the mobile route and return its `data`.
     */
    private function create(string $email, array $extra = []): array
    {
        $response = $this->post('/tickets', $this->ticket_body($email) + $extra + $this->proof($email, 'ticket'));
        $this->assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return $response->get_data()['data'];
    }

    private function post(string $path, array $body, array $files = []): WP_REST_Response
    {
        return $this->post_to(self::PREFIX.$path, $body, $files);
    }

    private function post_to(string $route, array $body, array $files = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', $route);
        if ($files === []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        } else {
            $request->set_header('Content-Type', 'multipart/form-data');
            $request->set_body_params($body);
            $request->set_file_params(['attachments' => [
                'name' => array_column($files, 'name'),
                'type' => array_column($files, 'type'),
                'tmp_name' => array_column($files, 'tmp_name'),
                'error' => array_column($files, 'error'),
                'size' => array_column($files, 'size'),
            ]]);
        }

        return rest_ensure_response($this->server->dispatch($request));
    }

    private function get(string $path): WP_REST_Response
    {
        return rest_ensure_response($this->server->dispatch(new WP_REST_Request('GET', self::PREFIX.$path)));
    }

    private function download(string $url): WP_REST_Response
    {
        $parts = wp_parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $route = preg_replace('#^.*?/wp-json#', '', $parts['path'] ?? '');
        if (isset($query['rest_route'])) {
            $route = $query['rest_route'];
            unset($query['rest_route']);
        }
        $request = new WP_REST_Request('GET', $route);
        $request->set_query_params($query);

        return rest_ensure_response($this->server->dispatch($request));
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function upload(string $name, string $content): array
    {
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, $content);
        $this->temp_files[] = $tmp;

        return ['name' => $name, 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    /**
     * @return array{verification_id: string, verification_code: string}
     */
    private function proof(string $email, string $purpose): array
    {
        $limits = $this->guest_limits;
        $this->guest_limits = null;
        $response = $this->post('/verification', ['email' => $email, 'purpose' => $purpose]);
        $this->guest_limits = $limits;
        $this->assertSame(202, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return ['verification_id' => $response->get_data()['verification_id'], 'verification_code' => $this->last_code()];
    }

    private function last_code(): string
    {
        $last = end($this->mail);
        preg_match('/\b(\d{8})\b/', $last['message'] ?? '', $m);

        return $m[1] ?? '';
    }

    private function ticket_count(string $email): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Ticket::table().' WHERE guest_email = %s', $email));
    }

    private function contact_count(string $email): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Contact::table().' WHERE email = %s', $email));
    }

    private function clear_counters(string $ip): void
    {
        delete_transient('escalated_widget_rate_'.md5($ip));
        delete_transient('escalated_api_ip_rate_'.md5($ip));
        foreach (['ticket', 'reply'] as $scope) {
            delete_transient(GuestRateLimiter::key($scope, $ip));
        }
    }
}
