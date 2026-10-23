<?php

/**
 * Live chat transfer, as escalated-laravel's ChatController::transfer and
 * ChatSessionService::transferChat: POST /escalated/v1/chat/{session}/transfer
 * with {agent_id, department_id} (the shared ChatActionBar's {type, id} is
 * read too). It emits chat.transferred to webhooks and workflows.
 *
 * Requests go through the real REST server with an API token; webhooks are
 * recorded with pre_http_request.
 */

use Escalated\Models\ChatRoutingRule;
use Escalated\Models\ChatSession;
use Escalated\Models\Department;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Models\Workflow;
use Escalated\Services\ChatSessionService;
use Escalated\Services\EventVocabulary;
use Escalated\Services\WebhookDispatcher;
use Escalated\Services\WorkflowAdmin;
use Escalated\Services\WorkflowEngine;

class Test_Chat_Transfer extends WP_UnitTestCase
{
    private const WEBHOOK_URL = 'https://hooks.example.com/escalated';

    private WP_REST_Server $server;

    private string $token;

    private int $admin_id;

    private int $from_agent;

    private int $to_agent;

    /** @var array<int, array{url: string, args: array}> */
    private array $http = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        $this->from_agent = $this->factory->user->create(['role' => 'escalated_agent']);
        $this->to_agent = $this->factory->user->create(['role' => 'escalated_agent']);

        $this->token = wp_generate_password(64, false);
        global $wpdb;
        $wpdb->insert(\Escalated\Escalated::table('api_tokens'), [
            'user_id' => $this->admin_id,
            'name' => 'Test Token',
            'token' => hash('sha256', $this->token),
            'abilities' => wp_json_encode(['*']),
            'created_at' => current_time('mysql'),
        ]);

        Setting::set('webhook_url', self::WEBHOOK_URL);
        Setting::set('webhook_secret', 'webhook-secret');
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
        remove_filter('pre_http_request', [$this, 'record_http'], 10);
        remove_filter('escalated_outbound_url_resolve', [$this, 'resolve_host'], 10);
        Setting::set('webhook_url', '');
        Setting::set('webhook_secret', '');
        Setting::set('visibility', 'all');

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

    public function test_transfer_to_an_agent_reassigns_the_chat_and_ticket(): void
    {
        $session = $this->active_chat();
        $workflow_id = $this->workflow('chat.transferred');
        $this->settle();

        $response = $this->transfer((int) $session->id, ['agent_id' => $this->to_agent]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('Chat transferred.', $data['message']);
        $this->assertSame($this->to_agent, (int) $data['session']->agent_id);

        $fresh = ChatSession::find((int) $session->id);
        $this->assertSame($this->to_agent, (int) $fresh->agent_id);
        $this->assertSame('active', $fresh->status);
        $this->assertSame($this->to_agent, (int) Ticket::find((int) $session->ticket_id)->assigned_to);

        $this->assertSame(['chat.transferred'], $this->delivered());
        $payload = $this->payload(0);
        $this->assertSame((int) $session->ticket_id, $payload['ticket']['id']);
        $this->assertSame((int) $session->id, $payload['chat']['session_id']);
        $this->assertSame($this->from_agent, $payload['chat']['from_agent_id']);
        $this->assertSame($this->to_agent, $payload['chat']['to_agent_id']);
        $this->assertNull($payload['chat']['to_department_id']);

        $this->assertSame(1, $this->log_count($workflow_id));
    }

    public function test_department_only_transfer_requeues_and_reroutes(): void
    {
        $department = $this->department();
        $routed = $this->factory->user->create(['role' => 'escalated_agent']);
        $session = $this->active_chat();
        ChatRoutingRule::create([
            'name' => 'Billing',
            'department_id' => $department,
            'agent_id' => $routed,
            'priority' => 10,
            'is_active' => 1,
        ]);
        $this->settle();

        $response = $this->transfer((int) $session->id, ['department_id' => $department]);

        $this->assertSame(200, $response->get_status());
        $fresh = ChatSession::find((int) $session->id);
        $ticket = Ticket::find((int) $session->ticket_id);
        $this->assertSame($department, (int) $ticket->department_id);
        $this->assertSame($department, (int) $fresh->department_id);
        $this->assertSame($routed, (int) $fresh->agent_id, 'routing should pick the new department\'s agent');
        $this->assertSame('active', $fresh->status);
        $this->assertSame($routed, (int) $ticket->assigned_to);

        $this->assertSame('chat.transferred', $this->delivered()[0]);
        $payload = $this->payload(0);
        $this->assertSame($this->from_agent, $payload['chat']['from_agent_id']);
        $this->assertNull($payload['chat']['to_agent_id']);
        $this->assertSame($department, $payload['chat']['to_department_id']);
    }

    public function test_department_only_transfer_with_no_agent_leaves_the_chat_waiting(): void
    {
        $department = $this->department();
        $session = $this->active_chat();

        $response = $this->transfer((int) $session->id, ['department_id' => (string) $department]);

        $this->assertSame(200, $response->get_status());
        $fresh = ChatSession::find((int) $session->id);
        $this->assertNull($fresh->agent_id);
        $this->assertSame('waiting', $fresh->status);
        $ticket = Ticket::find((int) $session->ticket_id);
        $this->assertEmpty($ticket->assigned_to);
        $this->assertSame($department, (int) $ticket->department_id);
    }

    public function test_agent_and_department_together(): void
    {
        $department = $this->department();
        $session = $this->active_chat();

        $response = $this->transfer((int) $session->id, ['agent_id' => $this->to_agent, 'department_id' => $department]);

        $this->assertSame(200, $response->get_status());
        $fresh = ChatSession::find((int) $session->id);
        $this->assertSame($this->to_agent, (int) $fresh->agent_id);
        $this->assertSame('active', $fresh->status);
        $this->assertSame($department, (int) Ticket::find((int) $session->ticket_id)->department_id);
    }

    public function test_the_chat_action_bar_body_is_accepted(): void
    {
        $department = $this->department();
        $session = $this->active_chat();

        $this->assertSame(200, $this->transfer((int) $session->id, ['type' => 'agent', 'id' => $this->to_agent])->get_status());
        $this->assertSame($this->to_agent, (int) ChatSession::find((int) $session->id)->agent_id);

        $this->assertSame(200, $this->transfer((int) $session->id, ['type' => 'department', 'id' => $department])->get_status());
        $this->assertSame($department, (int) Ticket::find((int) $session->ticket_id)->department_id);
    }

    public function test_refusals(): void
    {
        $session = $this->active_chat();
        $this->settle();

        $request = new WP_REST_Request('POST', '/escalated/v1/chat/'.(int) $session->id.'/transfer');
        $request->set_body_params(['agent_id' => $this->to_agent]);
        $this->assertSame(401, $this->server->dispatch($request)->get_status(), 'no token');

        $this->assertSame(422, $this->transfer((int) $session->id, [])->get_status());
        $this->assertSame(422, $this->transfer((int) $session->id, ['agent_id' => '', 'department_id' => ''])->get_status());
        $this->assertSame(422, $this->transfer((int) $session->id, ['department_id' => 999999])->get_status());
        $this->assertSame(422, $this->transfer((int) $session->id, ['department_id' => 'billing'])->get_status());
        $this->assertSame(422, $this->transfer((int) $session->id, ['agent_id' => 999999])->get_status());
        $this->assertSame(404, $this->transfer(999999, ['agent_id' => $this->to_agent])->get_status());

        (new ChatSessionService)->end((int) $session->id);
        $this->settle();
        $this->assertSame(422, $this->transfer((int) $session->id, ['agent_id' => $this->to_agent])->get_status());

        $this->assertSame($this->from_agent, (int) ChatSession::find((int) $session->id)->agent_id);
        (new WebhookDispatcher)->process_due();
        $this->assertNotContains('chat.transferred', $this->delivered());
    }

    public function test_an_agent_who_cannot_access_the_ticket_is_refused(): void
    {
        $department = $this->department();
        Department::add_agent($department, $this->from_agent);
        $session = $this->active_chat($department);
        Setting::set('visibility', 'department');
        $this->settle();

        $response = $this->transfer((int) $session->id, ['agent_id' => $this->to_agent]);

        $this->assertSame(422, $response->get_status());
        $this->assertSame($this->from_agent, (int) ChatSession::find((int) $session->id)->agent_id);
        $this->assertSame([], $this->delivered());
    }

    public function test_chat_transferred_is_offered_to_webhooks_and_workflows(): void
    {
        $this->assertContains('chat.transferred', EventVocabulary::WEBHOOK_EVENTS);
        $this->assertContains('chat.transferred', WorkflowEngine::TRIGGER_EVENTS);
        $this->assertNotSame('chat.transferred', WorkflowAdmin::trigger_label('chat.transferred'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function transfer(int $session_id, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/chat/'.$session_id.'/transfer');
        $request->set_header('Authorization', 'Bearer '.$this->token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode((object) $body));

        return rest_ensure_response($this->server->dispatch($request));
    }

    private function active_chat(?int $department = null): object
    {
        $chats = new ChatSessionService;
        $session = $chats->start('Visitor', 'visitor@example.com', 'Hello', $department);
        if ($session->status === 'waiting') {
            $session = $chats->accept((int) $session->id, $this->from_agent);
        }

        return $session;
    }

    private function department(): int
    {
        $slug = 'xfer-'.strtolower(wp_generate_password(8, false, false));

        return (int) Department::create([
            'name' => $slug,
            'slug' => $slug,
            'description' => '',
            'is_active' => 1,
        ]);
    }

    private function workflow(string $trigger): int
    {
        global $wpdb;
        $wpdb->insert(Workflow::table(), [
            'name' => 'On '.$trigger,
            'trigger_event' => $trigger,
            'conditions' => null,
            'actions' => wp_json_encode([['type' => 'add_note', 'value' => 'transferred']]),
            'is_active' => 1,
            'position' => 0,
            'stop_on_match' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function log_count(int $workflow_id): int
    {
        global $wpdb;
        $table = \Escalated\Escalated::table('workflow_logs');

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE workflow_id = %d", $workflow_id));
    }

    /** Sends what earlier steps queued and forgets it. */
    private function settle(): void
    {
        (new WebhookDispatcher)->process_due();
        $this->http = [];
    }

    /** @return list<string> */
    private function delivered(): array
    {
        (new WebhookDispatcher)->process_due();
        $events = [];
        foreach ($this->http as $call) {
            if ($call['url'] === self::WEBHOOK_URL) {
                $events[] = json_decode($call['args']['body'], true)['event'];
            }
        }

        return $events;
    }

    private function payload(int $index): array
    {
        $calls = array_values(array_filter($this->http, static fn ($c) => $c['url'] === self::WEBHOOK_URL));

        return json_decode($calls[$index]['args']['body'], true)['payload'];
    }
}
