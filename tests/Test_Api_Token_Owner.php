<?php

/**
 * Who an API token lets in: tokens from the password login are scoped to the
 * user's Escalated role, and every staff route re-checks that the token's
 * owner is still staff (and an admin for the admin routes), whatever the
 * visibility setting.
 */

use Escalated\Models\ApiToken;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;

class Test_Api_Token_Owner extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    private int $admin_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        Setting::set('visibility', 'all');

        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);

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

    private function user(string $role, string $login): int
    {
        return self::factory()->user->create([
            'role' => $role,
            'user_login' => $login,
            'user_pass' => 'secret123',
            'user_email' => $login.'@example.com',
        ]);
    }

    private function login(string $login): array
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/auth/login');
        $request->set_param('username', $login);
        $request->set_param('password', 'secret123');
        $response = $this->server->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    private function token_for(int $user_id, array $abilities, ?string $expires_at = null): string
    {
        $created = ApiToken::create_token($user_id, 'test', $abilities);
        if ($expires_at !== null) {
            ApiToken::update($created['record']->id, ['expires_at' => $expires_at]);
        }

        return $created['token'];
    }

    private function call(string $method, string $route, string $token): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/escalated/v1/'.$route);
        $request->set_header('Authorization', 'Bearer '.$token);

        return $this->server->dispatch($request);
    }

    private function ticket(): object
    {
        return (new \Escalated\Services\TicketService)->create($this->admin_id, [
            'subject' => 'Owner check',
            'description' => 'Ticket for token owner checks.',
            'priority' => 'medium',
        ]);
    }

    private function abilities(string $plain): array
    {
        return json_decode((string) ApiToken::find_by_token($plain)->abilities, true);
    }

    public function test_login_gives_a_non_staff_user_a_customer_token(): void
    {
        $this->user('subscriber', 'sub1');

        $token = $this->login('sub1')['token'];

        $this->assertSame(['customer'], $this->abilities($token));
    }

    public function test_login_gives_staff_their_staff_abilities(): void
    {
        $this->user('escalated_agent', 'agent1');
        $this->user('escalated_admin', 'admin1');

        $this->assertSame(['agent'], $this->abilities($this->login('agent1')['token']));
        $this->assertSame(['*'], $this->abilities($this->login('admin1')['token']));
    }

    public function test_login_token_of_a_non_staff_user_cannot_read_or_delete_tickets(): void
    {
        $ticket = $this->ticket();
        $this->user('subscriber', 'sub2');
        $token = $this->login('sub2')['token'];

        $this->assertSame(403, $this->call('GET', 'tickets', $token)->get_status());
        $this->assertSame(403, $this->call('GET', 'tickets/'.$ticket->reference, $token)->get_status());
        $this->assertSame(403, $this->call('DELETE', 'tickets/'.$ticket->reference, $token)->get_status());
        $this->assertNotNull(Ticket::find((int) $ticket->id));
    }

    public function test_customer_token_still_reaches_its_own_account_routes(): void
    {
        $this->user('subscriber', 'sub3');
        $token = $this->login('sub3')['token'];

        $me = $this->call('GET', 'auth/me', $token);
        $this->assertSame(200, $me->get_status());
        $this->assertSame('sub3@example.com', $me->get_data()['user']['email']);

        $refreshed = $this->call('POST', 'auth/refresh', $token);
        $this->assertSame(200, $refreshed->get_status());
        $new_token = $refreshed->get_data()['token'];
        $this->assertSame(['customer'], $this->abilities($new_token));
        $this->assertNull(ApiToken::find_by_token($token));

        $this->assertSame(200, $this->call('POST', 'auth/logout', $new_token)->get_status());
        $this->assertNull(ApiToken::find_by_token($new_token));
    }

    public function test_full_token_of_a_non_staff_owner_is_refused_on_staff_routes(): void
    {
        $ticket = $this->ticket();
        $subscriber = $this->user('subscriber', 'sub4');
        $token = $this->token_for($subscriber, ['*']);

        $this->assertSame(403, $this->call('GET', 'tickets', $token)->get_status());
        $this->assertSame(403, $this->call('DELETE', 'tickets/'.$ticket->reference, $token)->get_status());
        $this->assertNotNull(Ticket::find((int) $ticket->id));
    }

    public function test_token_stops_working_on_staff_routes_once_the_owner_is_no_longer_staff(): void
    {
        $agent = $this->user('escalated_agent', 'agent2');
        $token = $this->login('agent2')['token'];
        $this->assertSame(200, $this->call('GET', 'tickets', $token)->get_status());

        (new WP_User($agent))->set_role('subscriber');

        $this->assertSame(403, $this->call('GET', 'tickets', $token)->get_status());
    }

    public function test_an_agent_login_token_cannot_call_admin_routes(): void
    {
        $ticket = $this->ticket();
        $this->user('escalated_agent', 'agent3');
        $token = $this->login('agent3')['token'];

        $this->assertSame(200, $this->call('GET', 'tickets', $token)->get_status());
        $this->assertSame(403, $this->call('DELETE', 'tickets/'.$ticket->reference, $token)->get_status());
        $this->assertSame(403, $this->call('GET', 'automations', $token)->get_status());
        $this->assertNotNull(Ticket::find((int) $ticket->id));

        $this->user('escalated_admin', 'admin3');
        $admin_token = $this->login('admin3')['token'];
        $this->assertSame(200, $this->call('DELETE', 'tickets/'.$ticket->reference, $admin_token)->get_status());
    }

    public function test_refresh_keeps_the_token_abilities_and_expiry(): void
    {
        $expires = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $token = $this->token_for($this->admin_id, ['agent'], $expires);

        $response = $this->call('POST', 'auth/refresh', $token);

        $this->assertSame(200, $response->get_status());
        $record = ApiToken::find_by_token($response->get_data()['token']);
        $this->assertSame(['agent'], json_decode((string) $record->abilities, true));
        $this->assertSame($expires, $record->expires_at);
    }

    public function test_each_request_counts_once_against_the_rate_limit(): void
    {
        Setting::set('api_rate_limit', 2);
        $token = $this->token_for($this->admin_id, ['*']);

        $this->assertSame(200, $this->call('GET', 'tags', $token)->get_status());
        $this->assertSame(200, $this->call('GET', 'tags', $token)->get_status());
        $this->assertNotSame(200, $this->call('GET', 'tags', $token)->get_status());
    }

    public function test_expired_token_cannot_be_refreshed(): void
    {
        $token = $this->token_for($this->admin_id, ['*'], gmdate('Y-m-d H:i:s', time() - 60));

        $this->assertSame(401, $this->call('POST', 'auth/refresh', $token)->get_status());
    }
}
