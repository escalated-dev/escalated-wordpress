<?php

/**
 * Tests for custom ticket actions (host-defined action buttons).
 *
 * Covers the registry, the REST trigger endpoint (success / 404 / 403),
 * the dispatched `escalated_ticket_action_triggered` hook, and the internal
 * note recorded by Custom_Action_Listener.
 */

use Escalated\Models\Reply;
use Escalated\Services\TicketActionRegistry;

class Test_Custom_Ticket_Actions extends WP_UnitTestCase
{
    private int $admin_id;

    private string $token;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);

        $this->token = wp_generate_password(64, false);
        global $wpdb;
        $token_table = \Escalated\Escalated::table('api_tokens');
        $wpdb->insert($token_table, [
            'user_id' => $this->admin_id,
            'name' => 'Test Token',
            'token' => hash('sha256', $this->token),
            'abilities' => wp_json_encode(['*']),
            'created_at' => current_time('mysql'),
        ]);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_all_filters('escalated_ticket_actions');
        parent::tear_down();
    }

    private function create_request(string $method, string $route, array $params = []): WP_REST_Request
    {
        $request = new WP_REST_Request($method, '/escalated/v1/'.$route);
        $request->set_header('Authorization', 'Bearer '.$this->token);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    private function create_ticket(): object
    {
        return (new \Escalated\Services\TicketService)->create($this->admin_id, [
            'subject' => 'Custom Action Ticket',
            'description' => 'For custom action tests.',
            'priority' => 'medium',
        ]);
    }

    private function register_action(array $config): void
    {
        add_filter('escalated_ticket_actions', function (array $actions) use ($config): array {
            $actions[] = $config;

            return $actions;
        });
    }

    public function test_registry_finds_registered_action(): void
    {
        $this->register_action(['key' => 'sync-crm', 'label' => 'Sync CRM']);

        $this->assertNotNull(TicketActionRegistry::find('sync-crm'));
        $this->assertNull(TicketActionRegistry::find('missing'));
    }

    public function test_detail_response_exposes_visible_custom_actions(): void
    {
        $this->register_action([
            'key' => 'sync-crm',
            'label' => 'Sync CRM',
            'variant' => 'primary',
            'metadata' => ['icon' => 'refresh-cw'],
        ]);

        $ticket = $this->create_ticket();
        $response = $this->server->dispatch($this->create_request('GET', 'tickets/'.$ticket->reference));

        $this->assertEquals(200, $response->get_status());
        $actions = $response->get_data()['ticket']->custom_actions;
        $this->assertCount(1, $actions);
        $this->assertSame('sync-crm', $actions[0]['key']);
        $this->assertSame('post', $actions[0]['method']);
        $this->assertStringContainsString('/actions/sync-crm', $actions[0]['url']);
    }

    public function test_trigger_dispatches_hook_and_records_note(): void
    {
        $this->register_action(['key' => 'sync-crm', 'label' => 'Sync CRM']);
        $ticket = $this->create_ticket();

        $fired = [];
        add_action('escalated_ticket_action_triggered', function ($t, $key, $uid, $payload) use (&$fired) {
            $fired = compact('key', 'uid', 'payload');
        }, 10, 4);

        $response = $this->server->dispatch(
            $this->create_request('POST', 'tickets/'.$ticket->reference.'/actions/sync-crm', [
                'payload' => ['force' => true],
            ])
        );

        $this->assertEquals(200, $response->get_status());
        $this->assertSame('sync-crm', $fired['key']);
        $this->assertSame($this->admin_id, $fired['uid']);
        $this->assertSame(['force' => true], $fired['payload']);

        // Custom_Action_Listener recorded an internal note.
        $notes = array_filter(
            Reply::for_ticket($ticket->id),
            fn ($r) => (int) ($r->is_internal_note ?? 0) === 1
        );
        $this->assertNotEmpty($notes);
    }

    public function test_unknown_action_returns_404(): void
    {
        $ticket = $this->create_ticket();
        $response = $this->server->dispatch(
            $this->create_request('POST', 'tickets/'.$ticket->reference.'/actions/nope')
        );

        $this->assertEquals(404, $response->get_status());
    }

    public function test_disabled_action_returns_403(): void
    {
        $this->register_action(['key' => 'sync-crm', 'label' => 'Sync CRM', 'enabled' => false]);
        $ticket = $this->create_ticket();

        $response = $this->server->dispatch(
            $this->create_request('POST', 'tickets/'.$ticket->reference.'/actions/sync-crm')
        );

        $this->assertEquals(403, $response->get_status());
    }
}
