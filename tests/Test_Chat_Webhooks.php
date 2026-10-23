<?php

/**
 * Live chat events reach webhook subscribers, as escalated-laravel's
 * DispatchWebhook sends them: chat.started, chat.assigned, chat.message and
 * chat.ended (chat.transferred is covered in Test_Chat_Transfer). Each
 * payload carries the ticket plus Laravel's chat fields; the visitor's email,
 * the chat token and message bodies stay out of the chat block.
 *
 * Requests go through the real REST server (the widget routes and the agent
 * chat routes with an API token); webhooks are recorded with pre_http_request.
 */

use Escalated\Models\ChatSession;
use Escalated\Models\Setting;
use Escalated\Models\Webhook;
use Escalated\Services\EventVocabulary;
use Escalated\Services\WebhookDispatcher;

class Test_Chat_Webhooks extends WP_UnitTestCase
{
    private const WEBHOOK_URL = 'https://hooks.example.com/escalated';

    private const CHAT_EVENTS = ['chat.started', 'chat.assigned', 'chat.message', 'chat.ended'];

    private WP_REST_Server $server;

    private string $token;

    private int $agent;

    /** @var array<int, array{url: string, args: array}> */
    private array $http = [];

    /** @var array<string, mixed> */
    private array $saved_server = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $admin = $this->factory->user->create(['role' => 'administrator']);
        $this->agent = $this->factory->user->create(['role' => 'escalated_agent']);

        $this->token = wp_generate_password(64, false);
        global $wpdb;
        $wpdb->insert(\Escalated\Escalated::table('api_tokens'), [
            'user_id' => $admin,
            'name' => 'Test Token',
            'token' => hash('sha256', $this->token),
            'abilities' => wp_json_encode(['*']),
            'created_at' => current_time('mysql'),
        ]);

        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');
        $this->saved_server = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.'.wp_rand(1, 250);

        Webhook::create([
            'url' => self::WEBHOOK_URL,
            'events' => self::CHAT_EVENTS,
            'secret' => 'webhook-secret',
            'active' => 1,
        ]);
        $this->http = [];
        add_filter('pre_http_request', [$this, 'record_http'], 10, 3);
        add_filter('escalated_outbound_url_resolve', [$this, 'resolve_host'], 10, 2);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_SERVER = $this->saved_server;
        remove_filter('pre_http_request', [$this, 'record_http'], 10);
        remove_filter('escalated_outbound_url_resolve', [$this, 'resolve_host'], 10);

        parent::tear_down();
    }

    public function resolve_host($addresses, $host)
    {
        return $host === 'hooks.example.com' ? ['93.184.216.34'] : $addresses;
    }

    public function record_http($preempt, $args, $url)
    {
        $this->http[] = ['url' => $url, 'args' => $args];

        return [
            'headers' => [],
            'body' => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    public function test_a_widget_chat_start_sends_chat_started(): void
    {
        $chat = $this->widget_start();

        $this->assertSame(['chat.started'], $this->delivered());
        $session = ChatSession::find($chat['session_id']);
        $payload = $this->payload(0);
        $this->assertSame((int) $session->ticket_id, $payload['ticket']['id']);
        $this->assertSame([
            'session_id' => (int) $session->id,
            'ticket_id' => (int) $session->ticket_id,
            'status' => 'waiting',
            'agent_id' => null,
            'department_id' => null,
        ], $payload['chat']);

        $body = $this->bodies();
        $this->assertStringNotContainsString('wendy@example.com', $body);
        $this->assertStringNotContainsString($chat['id'], $body, 'the chat token never leaves');
    }

    public function test_an_agent_accepting_a_chat_sends_chat_assigned(): void
    {
        $chat = $this->widget_start();
        $this->settle();

        $response = $this->agent_post('/chat/'.$chat['session_id'].'/accept', ['agent_id' => $this->agent]);
        $this->assertSame(200, $response->get_status());

        $this->assertSame(['chat.assigned'], $this->delivered());
        $payload = $this->payload(0)['chat'];
        $this->assertSame($chat['session_id'], $payload['session_id']);
        $this->assertSame($this->agent, $payload['agent_id']);
        $this->assertSame('active', $payload['status']);
    }

    public function test_messages_send_chat_message_with_the_author_type(): void
    {
        $chat = $this->widget_start();
        $this->agent_post('/chat/'.$chat['session_id'].'/accept', ['agent_id' => $this->agent]);
        $this->settle();

        $agent = $this->agent_post('/chat/'.$chat['session_id'].'/messages', ['agent_id' => $this->agent, 'body' => 'Agent says hi']);
        $this->assertSame(201, $agent->get_status());
        $visitor = $this->widget_post('/chat/'.$chat['id'].'/messages', ['body' => 'Visitor says hi']);
        $this->assertSame(201, $visitor->get_status());

        $this->assertSame(['chat.message', 'chat.message'], $this->delivered());

        $first = $this->payload(0)['chat'];
        $this->assertSame($chat['session_id'], $first['session_id']);
        $this->assertSame('agent', $first['author_type']);
        $this->assertIsInt($first['reply_id']);
        $this->assertSame('Agent says hi', $this->reply_body($first['reply_id']));

        $second = $this->payload(1)['chat'];
        $this->assertSame('visitor', $second['author_type']);
        $this->assertSame((int) $visitor->get_data()['reply_id'], $second['reply_id']);

        $this->assertStringNotContainsString('says hi', $this->bodies(), 'message bodies are read through the API');
    }

    public function test_ending_a_chat_sends_chat_ended_with_who_ended_it(): void
    {
        $by_agent = $this->widget_start();
        $by_visitor = $this->widget_start();
        $idle = $this->widget_start();
        $this->settle();

        $this->assertSame(200, $this->agent_post('/chat/'.$by_agent['session_id'].'/end', [])->get_status());
        $this->assertSame(200, $this->widget_post('/chat/'.$by_visitor['id'].'/end', [])->get_status());
        ChatSession::update($idle['session_id'], ['last_activity_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)]);
        ChatSession::update($by_agent['session_id'], ['last_activity_at' => current_time('mysql')]);
        (new \Escalated\Cron\Chat_Cleanup)->run();

        $this->assertSame(['chat.ended', 'chat.ended', 'chat.ended'], $this->delivered());
        $ended = [];
        foreach ([0, 1, 2] as $i) {
            $chat = $this->payload($i)['chat'];
            $this->assertSame('ended', $chat['status']);
            $ended[$chat['session_id']] = $chat['ended_by'];
        }
        $this->assertSame([
            $by_agent['session_id'] => 'agent',
            $by_visitor['session_id'] => 'customer',
            $idle['session_id'] => 'system',
        ], $ended);
    }

    public function test_a_webhook_not_subscribed_to_chat_events_gets_none(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM '.Webhook::table());
        Webhook::create([
            'url' => self::WEBHOOK_URL,
            'events' => ['ticket.created'],
            'secret' => 'webhook-secret',
            'active' => 1,
        ]);

        $chat = $this->widget_start();
        $this->widget_post('/chat/'.$chat['id'].'/messages', ['body' => 'Hello?']);
        $this->widget_post('/chat/'.$chat['id'].'/end', []);

        $this->assertSame([], array_values(array_filter($this->delivered(), static fn ($e) => str_starts_with($e, 'chat.'))));
    }

    public function test_chat_events_are_offered_to_webhooks(): void
    {
        foreach ([...self::CHAT_EVENTS, 'chat.transferred'] as $event) {
            $this->assertContains($event, EventVocabulary::WEBHOOK_EVENTS);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<string, mixed> */
    private function widget_start(): array
    {
        $response = $this->widget_post('/chat/start', array_merge([
            'name' => 'Wendy Widget',
            'email' => 'wendy@example.com',
            'message' => 'My order is late.',
        ], escalated_test_guest_proof('wendy@example.com', 'chat')));
        $this->assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return $response->get_data();
    }

    private function widget_post(string $path, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/widget'.$path);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode((object) $body));

        return rest_ensure_response($this->server->dispatch($request));
    }

    private function agent_post(string $path, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1'.$path);
        $request->set_header('Authorization', 'Bearer '.$this->token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode((object) $body));

        return rest_ensure_response($this->server->dispatch($request));
    }

    private function reply_body(int $reply_id): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SELECT body FROM '.\Escalated\Escalated::table('replies').' WHERE id = %d', $reply_id));
    }

    /** Sends what earlier steps queued and forgets it. */
    private function settle(): void
    {
        (new WebhookDispatcher)->process_due();
        $this->http = [];
    }

    /** @return list<array{url: string, args: array}> */
    private function calls(): array
    {
        (new WebhookDispatcher)->process_due();

        return array_values(array_filter($this->http, static fn ($c) => $c['url'] === self::WEBHOOK_URL));
    }

    /** @return list<string> */
    private function delivered(): array
    {
        return array_map(static fn ($c) => json_decode($c['args']['body'], true)['event'], $this->calls());
    }

    private function payload(int $index): array
    {
        return json_decode($this->calls()[$index]['args']['body'], true)['payload'];
    }

    private function bodies(): string
    {
        return implode("\n", array_map(static fn ($c) => (string) $c['args']['body'], $this->calls()));
    }
}
