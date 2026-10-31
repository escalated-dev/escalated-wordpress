<?php

/**
 * API token abilities on the token-authenticated REST API (escalated/v1)
 * work the way escalated-laravel's do: agent routes need the `agent` (or
 * `*`) ability, admin routes need `admin` (or `*`), and an admin-only token
 * cannot reach agent routes.
 *
 * Tokens created by earlier releases with the wp-admin form's scoped
 * abilities keep working at the level they describe: `tickets:write` acts
 * as `agent`, `tickets:read` and `reports:read` as a read-only `agent`, and
 * `replies:write` as a read-only `agent` that may also reply and add notes.
 *
 * Everything goes through the REST router.
 */

use Escalated\Models\ApiToken;
use Escalated\Services\TicketService;

class Test_Api_Token_Abilities extends WP_UnitTestCase
{
    private int $agent_id;

    private int $requester_id;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->agent_id = $this->factory->user->create(['role' => 'administrator']);
        $this->requester_id = $this->factory->user->create(['role' => 'subscriber']);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user(0);
        parent::tear_down();
    }

    private function token(array $abilities): string
    {
        global $wpdb;
        $plain = wp_generate_password(64, false);
        $wpdb->insert(\Escalated\Escalated::table('api_tokens'), [
            'user_id' => $this->agent_id,
            'name' => 'Ability token',
            'token' => hash('sha256', $plain),
            'abilities' => wp_json_encode($abilities),
            'created_at' => current_time('mysql'),
        ]);

        return $plain;
    }

    private function request(string $method, string $route, string $token, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/escalated/v1/'.$route);
        $request->set_header('Authorization', 'Bearer '.$token);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $this->server->dispatch($request);
    }

    private function ticket(): object
    {
        return (new TicketService)->create($this->requester_id, [
            'subject' => 'Ability ticket',
            'description' => 'Customer question.',
            'priority' => 'medium',
        ]);
    }

    /** One request per agent-route family. */
    private function agent_calls(string $token): array
    {
        $ref = $this->ticket()->reference;

        return [
            'GET tickets' => $this->request('GET', 'tickets', $token)->get_status(),
            'GET ticket' => $this->request('GET', 'tickets/'.$ref, $token)->get_status(),
            'POST tickets' => $this->request('POST', 'tickets', $token, ['subject' => 'New', 'description' => 'Body', 'requester_id' => $this->requester_id])->get_status(),
            'POST reply' => $this->request('POST', 'tickets/'.$ref.'/reply', $token, ['body' => 'Hello'])->get_status(),
            'POST note' => $this->request('POST', 'tickets/'.$ref.'/note', $token, ['body' => 'Note'])->get_status(),
            'PATCH status' => $this->request('PATCH', 'tickets/'.$ref.'/status', $token, ['status' => 'in_progress'])->get_status(),
            'PATCH priority' => $this->request('PATCH', 'tickets/'.$ref.'/priority', $token, ['priority' => 'high'])->get_status(),
            'GET agents' => $this->request('GET', 'agents', $token)->get_status(),
            'GET tags' => $this->request('GET', 'tags', $token)->get_status(),
            'GET dashboard' => $this->request('GET', 'dashboard', $token)->get_status(),
            'POST auth/validate' => $this->request('POST', 'auth/validate', $token)->get_status(),
        ];
    }

    private function delete_ticket(string $token): int
    {
        return $this->request('DELETE', 'tickets/'.$this->ticket()->reference, $token)->get_status();
    }

    private function list_automations(string $token): int
    {
        return $this->request('GET', 'automations', $token)->get_status();
    }

    private function assert_all(int $status, array $calls): void
    {
        $this->assertSame(array_fill_keys(array_keys($calls), $status), $calls);
    }

    // =========================================================================
    // Laravel's abilities: agent, admin, *
    // =========================================================================

    public function test_an_agent_token_reaches_every_agent_route(): void
    {
        $calls = $this->agent_calls($this->token(['agent']));

        $this->assertSame(201, $calls['POST tickets']);
        $this->assertSame(201, $calls['POST reply']);
        $this->assertSame(201, $calls['POST note']);
        unset($calls['POST tickets'], $calls['POST reply'], $calls['POST note']);
        $this->assert_all(200, $calls);
    }

    public function test_an_agent_token_cannot_reach_admin_routes(): void
    {
        $token = $this->token(['agent']);

        $this->assertSame(403, $this->delete_ticket($token));
        $this->assertSame(403, $this->list_automations($token));
        $this->assertSame(403, $this->request('GET', 'admin/api-tokens', $token)->get_status());
    }

    public function test_an_admin_token_reaches_admin_routes(): void
    {
        $token = $this->token(['admin']);

        $this->assertSame(200, $this->delete_ticket($token));
        $this->assertSame(200, $this->list_automations($token));
        $this->assertSame(200, $this->request('GET', 'admin/api-tokens', $token)->get_status());
    }

    public function test_an_admin_only_token_cannot_reach_agent_routes(): void
    {
        $this->assert_all(403, $this->agent_calls($this->token(['admin'])));
    }

    public function test_an_agent_and_admin_token_reaches_both(): void
    {
        $token = $this->token(['agent', 'admin']);

        $this->assertSame(200, $this->request('GET', 'tickets', $token)->get_status());
        $this->assertSame(200, $this->delete_ticket($token));
    }

    public function test_a_wildcard_token_still_reaches_everything(): void
    {
        $token = $this->token(['*']);

        $calls = $this->agent_calls($token);
        $this->assertSame(201, $calls['POST tickets']);
        $this->assertSame(200, $calls['GET tickets']);
        $this->assertSame(200, $this->delete_ticket($token));
        $this->assertSame(200, $this->list_automations($token));
    }

    public function test_a_token_with_an_unknown_ability_is_refused_with_403(): void
    {
        $token = $this->token(['something:else']);

        $this->assert_all(403, $this->agent_calls($token));
        $this->assertSame(403, $this->delete_ticket($token));
    }

    public function test_a_bad_token_is_still_401(): void
    {
        $this->assertSame(401, $this->request('GET', 'tickets', 'not-a-token')->get_status());
    }

    // =========================================================================
    // Scoped abilities issued by earlier releases' wp-admin form
    // =========================================================================

    public function test_a_legacy_tickets_write_token_acts_as_an_agent_token(): void
    {
        $token = $this->token(['tickets:write']);

        $calls = $this->agent_calls($token);
        $this->assertSame(201, $calls['POST tickets']);
        $this->assertSame(200, $calls['PATCH status']);
        $this->assertSame(200, $calls['GET tickets']);
        $this->assertSame(403, $this->delete_ticket($token));
    }

    public function test_a_legacy_tickets_read_token_can_read_but_not_write(): void
    {
        $calls = $this->agent_calls($this->token(['tickets:read']));

        $this->assertSame(200, $calls['GET tickets']);
        $this->assertSame(200, $calls['GET ticket']);
        $this->assertSame(200, $calls['GET dashboard']);
        $this->assertSame(403, $calls['POST tickets']);
        $this->assertSame(403, $calls['POST reply']);
        $this->assertSame(403, $calls['PATCH status']);
        $this->assertSame(403, $calls['PATCH priority']);
    }

    public function test_a_legacy_reports_read_token_is_read_only(): void
    {
        $calls = $this->agent_calls($this->token(['reports:read']));

        $this->assertSame(200, $calls['GET dashboard']);
        $this->assertSame(403, $calls['POST tickets']);
    }

    public function test_a_legacy_replies_write_token_can_reply_but_not_change_the_ticket(): void
    {
        $calls = $this->agent_calls($this->token(['replies:write']));

        $this->assertSame(200, $calls['GET ticket']);
        $this->assertSame(201, $calls['POST reply']);
        $this->assertSame(201, $calls['POST note']);
        $this->assertSame(403, $calls['POST tickets']);
        $this->assertSame(403, $calls['PATCH status']);
        $this->assertSame(403, $calls['PATCH priority']);
    }

    // =========================================================================
    // Issuing tokens
    // =========================================================================

    public function test_tokens_are_issued_with_laravels_abilities(): void
    {
        $admin = $this->token(['admin']);

        $response = $this->request('POST', 'admin/api-tokens', $admin, [
            'name' => 'Desk app',
            'user_id' => $this->agent_id,
            'abilities' => ['agent'],
        ]);

        $this->assertSame(201, $response->get_status());
        $plain = $response->get_data()['token']['plain_token'];
        $this->assertSame(['agent'], json_decode(ApiToken::find_by_token($plain)->abilities, true));
        $this->assertSame(200, $this->request('GET', 'tickets', $plain)->get_status());
    }

    public function test_issuing_a_token_with_an_unknown_ability_is_rejected(): void
    {
        $response = $this->request('POST', 'admin/api-tokens', $this->token(['admin']), [
            'name' => 'Bad',
            'user_id' => $this->agent_id,
            'abilities' => ['tickets:read'],
        ]);

        $this->assertSame(422, $response->get_status());
    }

    public function test_the_token_form_offers_laravels_abilities(): void
    {
        wp_set_current_user($this->agent_id);
        ob_start();
        (new \Escalated\Admin\Admin_Api_Tokens)->render();
        $html = ob_get_clean();

        foreach (['*', 'agent', 'admin'] as $ability) {
            $this->assertStringContainsString('name="abilities[]" value="'.$ability.'"', $html);
        }
        $this->assertStringNotContainsString('value="tickets:read"', $html);
    }
}
