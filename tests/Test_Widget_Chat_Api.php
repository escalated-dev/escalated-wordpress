<?php

/**
 * Tests for the public widget chat REST API.
 */

use Escalated\Models\Setting;

class Test_Widget_Chat_Api extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');

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

    public function test_widget_chat_respects_widget_enabled_guard(): void
    {
        Setting::set('widget_enabled', '0');

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/chat/availability');
        $response = $this->server->dispatch($request);

        $this->assertEquals(403, $response->get_status());
    }

    public function test_start_chat_returns_guest_token(): void
    {
        $response = $this->start_chat();
        $data = $response->get_data();

        $this->assertEquals(201, $response->get_status());
        $this->assertNotEmpty($data['id']);
        $this->assertNotEmpty($data['guest_token']);
    }

    public function test_send_message_requires_matching_guest_token(): void
    {
        $chat = $this->start_chat()->get_data();

        $request = new WP_REST_Request('POST', '/escalated/v1/widget/chat/'.$chat['id'].'/messages');
        $request->set_param('body', 'I should not be accepted.');
        $request->set_param('guest_token', 'wrong-token');

        $response = $this->server->dispatch($request);

        $this->assertEquals(403, $response->get_status());
    }

    public function test_send_message_accepts_matching_guest_token(): void
    {
        $chat = $this->start_chat()->get_data();

        $request = new WP_REST_Request('POST', '/escalated/v1/widget/chat/'.$chat['id'].'/messages');
        $request->set_param('body', 'Hello from the verified visitor.');
        $request->set_param('guest_token', $chat['guest_token']);

        $response = $this->server->dispatch($request);

        $this->assertEquals(201, $response->get_status());
    }

    public function test_poll_requires_matching_guest_token(): void
    {
        $chat = $this->start_chat()->get_data();

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/chat/'.$chat['id'].'/poll');
        $request->set_param('guest_token', 'wrong-token');

        $response = $this->server->dispatch($request);

        $this->assertEquals(403, $response->get_status());
    }

    public function test_end_chat_accepts_matching_guest_token(): void
    {
        $chat = $this->start_chat()->get_data();

        $request = new WP_REST_Request('POST', '/escalated/v1/widget/chat/'.$chat['id'].'/end');
        $request->set_param('guest_token', $chat['guest_token']);

        $response = $this->server->dispatch($request);
        $data = $response->get_data();

        $this->assertEquals(200, $response->get_status());
        $this->assertEquals('ended', $data['status']);
    }

    private function start_chat(): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/widget/chat/start');
        $request->set_param('name', 'Widget Visitor');
        $request->set_param('email', 'visitor@example.com');
        $request->set_param('message', 'I need help.');

        return $this->server->dispatch($request);
    }
}
