<?php

/**
 * REST smoke tests for newsletter admin routes.
 */

use Escalated\Activator;
use Escalated\Services\Newsletter\NewsletterConfig;

class Test_Newsletter_Admin_Api extends WP_UnitTestCase
{
    private int $admin_id;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(NewsletterConfig::OPTION_ENABLED, '1');
        $this->admin_id = $this->factory->user->create(['role' => 'escalated_admin']);
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

    public function test_routes_hidden_when_disabled(): void
    {
        update_option(NewsletterConfig::OPTION_ENABLED, '0');
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
        $routes = $wp_rest_server->get_routes();
        $this->assertArrayNotHasKey('/escalated/v1/admin/newsletters', $routes);
    }

    public function test_index_requires_manage_permission(): void
    {
        wp_set_current_user(0);
        $request = new WP_REST_Request('GET', '/escalated/v1/admin/newsletters');
        $response = $this->server->dispatch($request);
        $this->assertEquals(401, $response->get_status());
    }

    public function test_index_returns_inertia_shape(): void
    {
        wp_set_current_user($this->admin_id);
        $request = new WP_REST_Request('GET', '/escalated/v1/admin/newsletters');
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('Escalated/Admin/Newsletters/Index', $data['component']);
        $this->assertArrayHasKey('newsletters', $data['props']);
        $this->assertArrayHasKey('tab', $data['props']);
    }

    public function test_webhook_postmark_ok(): void
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/webhooks/newsletter/postmark');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['RecordType' => 'Open', 'MessageID' => 'n-1-abc123@example.com']));
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());
        $this->assertSame(['ok' => true], $response->get_data());
    }

    public function test_preview_returns_html(): void
    {
        wp_set_current_user($this->admin_id);
        $request = new WP_REST_Request('POST', '/escalated/v1/admin/newsletters/preview');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'subject' => 'Hi',
            'body_markdown' => 'Hello **world**',
            'theme' => 'default',
        ]));
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());
        $this->assertArrayHasKey('html', $response->get_data());
    }
}
