<?php

/**
 * Admin switches and limits on the public widget routes: guest tickets off,
 * knowledge base off or private, widget off for the legacy chat message
 * route, the guest submission limit on chat, and Retry-After on 429s.
 */

use Escalated\Models\Article;
use Escalated\Models\Setting;

class Test_Widget_Guards extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    /** @var array<string, mixed> */
    private array $saved_server = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '1');

        $this->saved_server = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.'.wp_rand(1, 250);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $_SERVER = $this->saved_server;
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user(0);

        parent::tear_down();
    }

    private function post(string $path, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/'.$path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $this->server->dispatch($request);
    }

    private function get(string $path): WP_REST_Response
    {
        return $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/'.$path));
    }

    private function create_ticket(): WP_REST_Response
    {
        return $this->post('widget/tickets', [
            'name' => 'Visitor', 'email' => 'visitor@example.com', 'subject' => 'Hi', 'description' => 'Help',
        ] + escalated_test_guest_proof('visitor@example.com'));
    }

    private function start_chat(): WP_REST_Response
    {
        return $this->post('widget/chat/start', ['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'Hello'] + escalated_test_guest_proof('visitor@example.com', 'chat'));
    }

    private function article(): string
    {
        $slug = 'reset-'.wp_rand();
        Article::create(['title' => 'Reset', 'slug' => $slug, 'body' => 'Steps', 'status' => 'published', 'published_at' => current_time('mysql')]);

        return $slug;
    }

    // ------------------------------------------------------------------
    // Guest tickets disabled
    // ------------------------------------------------------------------

    public function test_widget_ticket_is_refused_when_guest_tickets_are_disabled(): void
    {
        Setting::set('guest_tickets_enabled', '0');

        $response = $this->create_ticket();

        $this->assertSame(403, $response->get_status());
        $this->assertSame('escalated_guest_tickets_disabled', $response->as_error()->get_error_code());
    }

    public function test_chat_start_is_refused_when_guest_tickets_are_disabled(): void
    {
        Setting::set('guest_tickets_enabled', '0');

        $this->assertSame(403, $this->start_chat()->get_status());
    }

    public function test_widget_ticket_still_works_with_guest_tickets_on(): void
    {
        $this->assertSame(201, $this->create_ticket()->get_status());
    }

    // ------------------------------------------------------------------
    // Knowledge base settings
    // ------------------------------------------------------------------

    public function test_widget_kb_is_404_when_the_knowledge_base_is_off(): void
    {
        $slug = $this->article();
        Setting::set('knowledge_base_enabled', '0');

        $this->assertSame(404, $this->get('widget/articles')->get_status());
        $this->assertSame(404, $this->get('widget/articles/'.$slug)->get_status());
        $this->assertSame(404, $this->post('widget/articles/'.$slug.'/feedback', ['helpful' => true])->get_status());
    }

    public function test_widget_kb_is_403_for_anonymous_callers_when_private(): void
    {
        $slug = $this->article();
        Setting::set('knowledge_base_public', '0');

        $this->assertSame(403, $this->get('widget/articles')->get_status());
        $this->assertSame(403, $this->get('widget/articles/'.$slug)->get_status());
        $this->assertSame(403, $this->post('widget/articles/'.$slug.'/feedback', ['helpful' => true])->get_status());
    }

    public function test_widget_kb_is_readable_when_public(): void
    {
        $slug = $this->article();

        $this->assertSame(200, $this->get('widget/articles')->get_status());
        $this->assertSame(200, $this->get('widget/articles/'.$slug)->get_status());
    }

    // ------------------------------------------------------------------
    // Legacy chat message route and chat limits
    // ------------------------------------------------------------------

    public function test_legacy_chat_message_route_respects_widget_disabled(): void
    {
        $chat = $this->start_chat()->get_data();
        Setting::set('widget_enabled', '0');

        $response = $this->post('widget/chat/'.escalated_test_chat_id($chat).'/messages', [
            'body' => 'Still here?', 'guest_token' => $chat['guest_token'],
        ]);

        $this->assertSame(403, $response->get_status());
    }

    public function test_chat_start_counts_against_the_guest_submission_limit(): void
    {
        Setting::set('guest_rate_limit_tickets_per_minute', '2');

        $this->assertSame(201, $this->start_chat()->get_status());
        $this->assertSame(201, $this->create_ticket()->get_status());

        $response = $this->start_chat();
        $this->assertSame(429, $response->get_status());
        $this->assertGreaterThan(0, (int) $response->get_headers()['Retry-After']);
    }

    public function test_chat_messages_count_against_the_guest_reply_limit(): void
    {
        Setting::set('guest_rate_limit_replies_per_minute', '1');
        $chat = $this->start_chat()->get_data();

        $this->assertSame(201, $this->post('widget/chat/'.$chat['id'].'/messages', ['body' => 'one'])->get_status());

        $response = $this->post('widget/chat/'.escalated_test_chat_id($chat).'/messages', [
            'body' => 'two', 'guest_token' => $chat['guest_token'],
        ]);
        $this->assertSame(429, $response->get_status());
        $this->assertArrayHasKey('Retry-After', $response->get_headers());
    }

    // ------------------------------------------------------------------
    // Retry-After on the widget-wide limit
    // ------------------------------------------------------------------

    public function test_widget_rate_limit_429_carries_retry_after(): void
    {
        set_transient('escalated_widget_rate_'.md5($_SERVER['REMOTE_ADDR']), ['count' => 30, 'reset' => time() + 42], 42);

        $request = new WP_REST_Request('GET', '/escalated/v1/widget/articles');
        $response = rest_ensure_response($this->server->dispatch($request));
        $response = apply_filters('rest_post_dispatch', $response, $this->server, $request);

        $this->assertSame(429, $response->get_status());
        $retry = (int) $response->get_headers()['Retry-After'];
        $this->assertGreaterThan(0, $retry);
        $this->assertLessThanOrEqual(42, $retry);
    }
}
