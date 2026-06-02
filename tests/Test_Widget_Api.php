<?php

/**
 * Tests for the embeddable Widget API.
 *
 * Covers widget configuration, ticket creation via widget, ticket lookup,
 * widget settings, and rate limiting/enabled guard.
 */

use Escalated\Api\Widget_Controller;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;

class Test_Widget_Api extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        // Enable the widget for tests.
        Setting::set('widget_enabled', '1');

        // Initialize the REST server.
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

    // =========================================================================
    // Widget Enabled Guard Tests
    // =========================================================================

    public function test_widget_disabled_returns_403(): void
    {
        Setting::set('widget_enabled', '0');

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/config');
        $response = $this->server->dispatch($request);

        $this->assertEquals(403, $response->get_status());
    }

    public function test_widget_enabled_returns_200(): void
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/widget/config');
        $response = $this->server->dispatch($request);

        $this->assertEquals(200, $response->get_status());
    }

    // =========================================================================
    // Widget Config Tests
    // =========================================================================

    public function test_get_config_returns_settings(): void
    {
        Setting::set('widget_color', '#EF4444');
        Setting::set('widget_position', 'bottom-left');
        Setting::set('widget_greeting', 'Hello!');

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/config');
        $response = $this->server->dispatch($request);

        $data = $response->get_data();
        $this->assertEquals('#EF4444', $data['color']);
        $this->assertEquals('bottom-left', $data['position']);
        $this->assertEquals('Hello!', $data['greeting']);
    }

    public function test_get_config_returns_defaults(): void
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/widget/config');
        $response = $this->server->dispatch($request);

        $data = $response->get_data();
        $this->assertNotEmpty($data['color']);
        $this->assertNotEmpty($data['position']);
        $this->assertNotEmpty($data['greeting']);
    }

    // =========================================================================
    // Widget Ticket Creation Tests
    // =========================================================================

    public function test_create_ticket_via_widget(): void
    {
        // Enable guest tickets.
        Setting::set('guest_tickets_enabled', '1');

        $request = new WP_REST_Request('POST', '/escalated/v1/widget/tickets');
        $request->set_param('name', 'Jane Widget');
        $request->set_param('email', 'jane@widget.com');
        $request->set_param('subject', 'Widget issue');
        $request->set_param('description', 'I found a bug via the widget.');

        $response = $this->server->dispatch($request);

        $this->assertEquals(201, $response->get_status());

        $data = $response->get_data();
        $this->assertNotEmpty($data['reference']);
        $this->assertNotEmpty($data['guest_token']);
    }

    public function test_created_widget_ticket_has_widget_channel(): void
    {
        Setting::set('guest_tickets_enabled', '1');

        $request = new WP_REST_Request('POST', '/escalated/v1/widget/tickets');
        $request->set_param('name', 'Widget User');
        $request->set_param('email', 'widget@test.com');
        $request->set_param('subject', 'Widget test');
        $request->set_param('description', 'Testing widget channel.');

        $response = $this->server->dispatch($request);
        $data = $response->get_data();

        $ticket = Ticket::find_by_reference($data['reference']);
        $this->assertEquals('widget', $ticket->channel);
    }

    // =========================================================================
    // Widget Ticket Lookup Tests
    // =========================================================================

    public function test_lookup_ticket_by_reference_requires_guest_token(): void
    {
        $service = new \Escalated\Services\TicketService;
        $ticket = $service->create_guest([
            'subject' => 'Lookup test',
            'description' => 'Test.',
            'guest_name' => 'Lookup User',
            'guest_email' => 'lookup@example.com',
        ]);

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/tickets/'.$ticket->reference);
        $response = $this->server->dispatch($request);

        $this->assertEquals(403, $response->get_status());
    }

    public function test_lookup_ticket_by_reference_with_guest_token(): void
    {
        $service = new \Escalated\Services\TicketService;
        $ticket = $service->create_guest([
            'subject' => 'Lookup test',
            'description' => 'Test.',
            'guest_name' => 'Lookup User',
            'guest_email' => 'lookup@example.com',
        ]);

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/tickets/'.$ticket->reference);
        $request->set_param('guest_token', $ticket->guest_token);
        $response = $this->server->dispatch($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertEquals($ticket->reference, $data['reference']);
        $this->assertEquals('Lookup test', $data['subject']);
        $this->assertEquals('open', $data['status']);
    }

    public function test_lookup_nonexistent_ticket_returns_404(): void
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/widget/tickets/ESC-99999');
        $response = $this->server->dispatch($request);

        $this->assertEquals(404, $response->get_status());
    }

    // =========================================================================
    // Widget Settings Tests
    // =========================================================================

    public function test_get_widget_settings(): void
    {
        Setting::set('widget_enabled', '1');
        Setting::set('widget_color', '#10B981');

        $settings = Widget_Controller::get_settings();

        $this->assertTrue($settings['widget_enabled']);
        $this->assertEquals('#10B981', $settings['widget_color']);
    }

    public function test_update_widget_settings(): void
    {
        Widget_Controller::update_settings([
            'widget_enabled' => '1',
            'widget_color' => '#F59E0B',
            'widget_position' => 'top-right',
            'widget_greeting' => 'Welcome!',
        ]);

        $this->assertEquals('#F59E0B', Setting::get('widget_color'));
        $this->assertEquals('top-right', Setting::get('widget_position'));
        $this->assertEquals('Welcome!', Setting::get('widget_greeting'));
    }

    // =========================================================================
    // Rate Limiting Tests
    // =========================================================================

    public function test_rate_limiting_allows_within_limit(): void
    {
        $controller = new Widget_Controller;
        $result = $controller->widget_enabled_check();

        $this->assertTrue($result);
    }

    // =========================================================================
    // Articles Endpoint Tests
    // =========================================================================

    public function test_get_articles_returns_empty_array(): void
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/widget/articles');
        $response = $this->server->dispatch($request);

        $this->assertEquals(200, $response->get_status());
        $this->assertIsArray($response->get_data());
    }
}
