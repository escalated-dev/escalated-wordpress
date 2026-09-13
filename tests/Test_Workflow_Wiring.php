<?php

/**
 * Every advertised workflow trigger fires, and every advertised action runs.
 *
 * WorkflowEngine::TRIGGER_EVENTS and ACTION_TYPES are what the plugin says a
 * workflow can do. These tests hold it to that: each trigger has a scenario
 * that makes the plugin fire it through the real services, and each action
 * type reaches a handler instead of the executor's "unknown action type"
 * branch.
 */

use Escalated\Escalated;
use Escalated\Models\Department;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;
use Escalated\Models\Workflow;
use Escalated\Services\AssignmentService;
use Escalated\Services\SlaService;
use Escalated\Services\TicketService;
use Escalated\Services\WorkflowEngine;
use Escalated\Services\WorkflowExecutorService;

class Test_Workflow_Wiring extends WP_UnitTestCase
{
    /**
     * The triggers fire() has a scenario for.
     */
    private const TRIGGER_SCENARIOS = [
        'ticket.created',
        'ticket.updated',
        'ticket.status_changed',
        'ticket.assigned',
        'ticket.priority_changed',
        'ticket.tagged',
        'ticket.department_changed',
        'reply.created',
        'reply.agent_reply',
        'sla.warning',
        'sla.breached',
        'ticket.reopened',
    ];

    private TicketService $tickets;

    private int $requester_id;

    private int $agent_id;

    /** @var array<int, array{url: string, args: array}> */
    private array $http = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->tickets = new TicketService;
        $this->requester_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);

        $this->http = [];
        add_filter('pre_http_request', [$this, 'record_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'record_http'], 10);

        parent::tear_down();
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

    // =========================================================================
    // Triggers
    // =========================================================================

    public function test_every_advertised_trigger_has_a_scenario(): void
    {
        $this->assertEqualsCanonicalizing(WorkflowEngine::TRIGGER_EVENTS, self::TRIGGER_SCENARIOS);
    }

    /**
     * @dataProvider trigger_provider
     */
    public function test_trigger_runs_its_workflow(string $trigger): void
    {
        $workflow_id = $this->create_workflow($trigger, [['type' => 'add_note', 'value' => 'ran on '.$trigger]]);

        $this->fire($trigger);

        $this->assertGreaterThanOrEqual(1, $this->log_count($workflow_id), "Nothing ran the {$trigger} workflow.");
    }

    public static function trigger_provider(): array
    {
        $cases = [];
        foreach (self::TRIGGER_SCENARIOS as $trigger) {
            $cases[$trigger] = [$trigger];
        }

        return $cases;
    }

    public function test_priority_changed_workflow_writes_a_log_row(): void
    {
        $ticket = $this->make_ticket();
        $workflow_id = $this->create_workflow('ticket.priority_changed', [['type' => 'add_note', 'value' => 'escalating']]);

        $this->tickets->change_priority((int) $ticket->id, 'urgent', $this->agent_id);

        $this->assertSame(1, $this->log_count($workflow_id));
    }

    public function test_setting_the_same_priority_does_not_run_priority_changed_workflows(): void
    {
        $ticket = $this->make_ticket();
        $workflow_id = $this->create_workflow('ticket.priority_changed', [['type' => 'add_note', 'value' => 'escalating']]);

        $this->tickets->change_priority((int) $ticket->id, 'low', $this->agent_id);

        $this->assertSame(0, $this->log_count($workflow_id));
    }

    public function test_requester_reply_does_not_run_agent_reply_workflows(): void
    {
        $ticket = $this->make_ticket();
        $workflow_id = $this->create_workflow('reply.agent_reply', [['type' => 'add_note', 'value' => 'agent replied']]);

        $this->tickets->reply((int) $ticket->id, $this->requester_id, 'Any news?');

        $this->assertSame(0, $this->log_count($workflow_id));
    }

    public function test_sla_warning_workflow_runs_once_per_warning(): void
    {
        $ticket = $this->make_ticket();
        $this->set_first_response_due((int) $ticket->id, 15 * MINUTE_IN_SECONDS);
        $workflow_id = $this->create_workflow('sla.warning', [['type' => 'add_note', 'value' => 'SLA due soon']]);

        // The SLA cron runs every minute and re-fires the warning for as long
        // as the ticket is inside the window.
        $sla = new SlaService;
        $sla->check_warnings(30);
        $sla->check_warnings(30);

        $this->assertSame(1, $this->log_count($workflow_id));
    }

    // =========================================================================
    // Actions
    // =========================================================================

    public function test_every_advertised_action_type_has_a_handler(): void
    {
        $executor = $this->recording_executor();
        $ticket = $this->make_ticket();

        foreach (WorkflowEngine::ACTION_TYPES as $type) {
            $executor->messages = [];
            $executor->execute($ticket, wp_json_encode([['type' => $type, 'value' => '']]));

            $this->assertNotContains(
                'unknown action type: '.$type,
                $executor->messages,
                "{$type} is advertised, but the executor has no handler for it."
            );
        }
    }

    public function test_action_types_include_the_contract_core_catalog(): void
    {
        $core = ['change_status', 'change_priority', 'add_tag', 'remove_tag', 'set_department', 'assign_agent', 'add_note', 'insert_canned_reply'];

        foreach ($core as $type) {
            $this->assertContains($type, WorkflowEngine::ACTION_TYPES);
        }
    }

    public function test_set_type_action_changes_the_ticket_type(): void
    {
        $ticket = $this->make_ticket();

        (new WorkflowExecutorService)->execute($ticket, wp_json_encode([['type' => 'set_type', 'value' => 'incident']]));

        $this->assertSame('incident', Ticket::find((int) $ticket->id)->ticket_type);
    }

    public function test_set_type_action_ignores_an_unknown_type(): void
    {
        $ticket = $this->make_ticket();

        (new WorkflowExecutorService)->execute($ticket, wp_json_encode([['type' => 'set_type', 'value' => 'banana']]));

        $this->assertSame('question', Ticket::find((int) $ticket->id)->ticket_type);
    }

    public function test_send_webhook_workflow_posts_the_ticket_to_its_url(): void
    {
        $url = 'https://hooks.example.com/workflow';
        $this->create_workflow('ticket.created', [['type' => 'send_webhook', 'value' => $url]]);

        $ticket = $this->make_ticket();

        $calls = array_values(array_filter($this->http, fn ($call) => $call['url'] === $url));
        $this->assertCount(1, $calls);

        $args = $calls[0]['args'];
        $body = json_decode($args['body'], true);
        $this->assertSame('POST', $args['method']);
        $this->assertSame((int) $ticket->id, $body['ticket']['id']);
        $this->assertSame($ticket->reference, $body['ticket']['reference']);
        $this->assertSame('open', $body['ticket']['status']);

        // wp_safe_remote_post refuses loopback, private and link-local hosts,
        // and with redirects off the request cannot be bounced to one.
        $this->assertTrue($args['reject_unsafe_urls']);
        $this->assertSame(0, $args['redirection']);
    }

    /**
     * @dataProvider unsafe_webhook_urls
     */
    public function test_send_webhook_action_refuses_unsafe_urls(string $url, string $expected_log): void
    {
        // No interception: WordPress has to refuse the URL before connecting.
        remove_filter('pre_http_request', [$this, 'record_http'], 10);

        $executor = $this->recording_executor();
        $executor->execute($this->make_ticket(), wp_json_encode([['type' => 'send_webhook', 'value' => $url]]));

        $this->assertStringContainsString($expected_log, implode("\n", $executor->messages));
    }

    public static function unsafe_webhook_urls(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', 'A valid URL was not provided.'],
            'loopback' => ['http://127.0.0.1/wp-admin/', 'A valid URL was not provided.'],
            'private network' => ['http://10.0.0.5/internal', 'A valid URL was not provided.'],
            'file scheme' => ['file:///etc/passwd', 'is not an http(s) URL'],
            'no scheme' => ['hooks.example.com/workflow', 'is not an http(s) URL'],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fire(string $trigger): void
    {
        if ($trigger === 'ticket.created') {
            $this->make_ticket();

            return;
        }

        $id = (int) $this->make_ticket()->id;

        switch ($trigger) {
            case 'ticket.updated':
                $this->tickets->update_ticket($id, ['subject' => 'Printer still on fire']);
                break;
            case 'ticket.status_changed':
                $this->tickets->change_status($id, 'in_progress', $this->agent_id);
                break;
            case 'ticket.assigned':
                (new AssignmentService)->assign($id, $this->agent_id);
                break;
            case 'ticket.priority_changed':
                $this->tickets->change_priority($id, 'urgent', $this->agent_id);
                break;
            case 'ticket.tagged':
                $this->tickets->add_tags($id, [$this->make_tag()]);
                break;
            case 'ticket.department_changed':
                $this->tickets->change_department($id, $this->make_department());
                break;
            case 'reply.created':
            case 'reply.agent_reply':
                $this->tickets->reply($id, $this->agent_id, 'Looking into it.');
                break;
            case 'sla.warning':
                $this->set_first_response_due($id, 15 * MINUTE_IN_SECONDS);
                (new SlaService)->check_warnings(30);
                break;
            case 'sla.breached':
                $this->set_first_response_due($id, -15 * MINUTE_IN_SECONDS);
                (new SlaService)->check_breaches();
                break;
            case 'ticket.reopened':
                $this->tickets->change_status($id, 'resolved');
                $this->tickets->change_status($id, 'reopened');
                break;
            default:
                $this->fail("No scenario fires {$trigger}.");
        }
    }

    private function make_ticket(): object
    {
        return $this->tickets->create($this->requester_id, [
            'subject' => 'Printer on fire',
            'description' => 'It is still on fire.',
            'priority' => 'low',
            'channel' => 'web',
        ]);
    }

    private function make_tag(): int
    {
        global $wpdb;
        $slug = 'wiring-'.wp_generate_password(8, false, false);
        $wpdb->insert(Tag::table(), [
            'name' => $slug,
            'slug' => $slug,
            'color' => '#336699',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function make_department(): int
    {
        $slug = 'wiring-'.strtolower(wp_generate_password(8, false, false));

        return (int) Department::create([
            'name' => $slug,
            'slug' => $slug,
            'description' => '',
            'is_active' => 1,
        ]);
    }

    private function set_first_response_due(int $ticket_id, int $seconds_from_now): void
    {
        Ticket::update($ticket_id, [
            'first_response_due_at' => gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) + $seconds_from_now),
            'sla_first_response_breached' => 0,
        ]);
    }

    private function create_workflow(string $trigger, array $actions): int
    {
        global $wpdb;
        $wpdb->insert(Workflow::table(), [
            'name' => 'Wiring test: '.$trigger,
            'trigger_event' => $trigger,
            'conditions' => null,
            'actions' => wp_json_encode($actions),
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
        $table = Escalated::table('workflow_logs');

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE workflow_id = %d", $workflow_id)
        );
    }

    /**
     * An executor that keeps its debug messages instead of writing them to
     * the error log.
     */
    private function recording_executor(): WorkflowExecutorService
    {
        return new class extends WorkflowExecutorService
        {
            /** @var array<int, string> */
            public array $messages = [];

            protected function log_debug(string $message): void
            {
                $this->messages[] = $message;
            }
        };
    }
}
