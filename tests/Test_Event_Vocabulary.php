<?php

/**
 * ADR 2026-10-21-event-vocabulary: webhooks and workflows use the canonical
 * event names, and stored legacy names keep working.
 *
 * Everything goes through the real services and the hooks Escalated::boot()
 * registers. Outbound HTTP is recorded with pre_http_request.
 */

use Escalated\Escalated;
use Escalated\Models\Setting;
use Escalated\Models\Tag;
use Escalated\Models\Workflow;
use Escalated\Services\AssignmentService;
use Escalated\Services\EventVocabulary;
use Escalated\Services\SlaService;
use Escalated\Services\TicketService;
use Escalated\Services\WebhookDispatcher;
use Escalated\Services\WorkflowEngine;

class Test_Event_Vocabulary extends WP_UnitTestCase
{
    private const WEBHOOK_URL = 'https://hooks.example.com/escalated';

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

        Setting::set('webhook_url', self::WEBHOOK_URL);
        Setting::set('webhook_secret', 'webhook-secret');

        $this->http = [];
        add_filter('pre_http_request', [$this, 'record_http'], 10, 3);
        add_filter('escalated_outbound_url_resolve', [$this, 'resolve_host'], 10, 2);
    }

    public function resolve_host($addresses, $host)
    {
        return $host === 'hooks.example.com' ? ['93.184.216.34'] : $addresses;
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'record_http'], 10);
        remove_filter('escalated_outbound_url_resolve', [$this, 'resolve_host'], 10);
        Setting::set('webhook_url', '');
        Setting::set('webhook_secret', '');

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
    // Vocabulary
    // =========================================================================

    public function test_legacy_names_map_to_canonical_names(): void
    {
        $this->assertSame('reply.created', EventVocabulary::canonical('ticket.replied'));
        $this->assertSame('note.created', EventVocabulary::canonical('internal_note.added'));
        $this->assertSame('note.created', EventVocabulary::canonical('internal_note.created'));
        $this->assertSame('ticket.tag_added', EventVocabulary::canonical('ticket.tagged'));
        $this->assertSame('ticket.tag_added', EventVocabulary::canonical('tag.added'));
        $this->assertSame('ticket.tag_removed', EventVocabulary::canonical('tag.removed'));
        $this->assertSame('ticket.tag_removed', EventVocabulary::canonical('ticket.untagged'));
        $this->assertSame('chat.started', EventVocabulary::canonical('chat.session_started'));
        $this->assertSame('chat.ended', EventVocabulary::canonical('chat.session_ended'));
        $this->assertSame('ticket.created', EventVocabulary::canonical('ticket.created'));
    }

    public function test_offered_triggers_are_canonical(): void
    {
        foreach (WorkflowEngine::TRIGGER_EVENTS as $trigger) {
            $this->assertArrayNotHasKey($trigger, EventVocabulary::LEGACY, "{$trigger} is a legacy name.");
        }
        $this->assertContains('reply.created', WorkflowEngine::TRIGGER_EVENTS);
        $this->assertContains('note.created', WorkflowEngine::TRIGGER_EVENTS);
        $this->assertContains('ticket.tag_added', WorkflowEngine::TRIGGER_EVENTS);
        $this->assertNotContains('ticket.tagged', WorkflowEngine::TRIGGER_EVENTS);
    }

    // =========================================================================
    // Workflows stored under legacy names
    // =========================================================================

    public function test_a_ticket_tagged_workflow_still_runs_when_a_tag_is_added(): void
    {
        $ticket_id = (int) $this->make_ticket()->id;
        $workflow_id = $this->create_workflow('ticket.tagged');

        $this->tickets->add_tags($ticket_id, [$this->make_tag()]);

        $this->assertSame(1, $this->log_count($workflow_id));
    }

    public function test_a_ticket_replied_workflow_runs_on_a_public_reply_only(): void
    {
        $ticket_id = (int) $this->make_ticket()->id;
        $workflow_id = $this->create_workflow('ticket.replied');

        $this->tickets->add_note($ticket_id, $this->agent_id, 'Internal only.');
        $this->assertSame(0, $this->log_count($workflow_id));

        $this->tickets->reply($ticket_id, $this->agent_id, 'Looking into it.');
        $this->assertSame(1, $this->log_count($workflow_id));
    }

    public function test_an_internal_note_added_workflow_runs_on_a_note(): void
    {
        $ticket_id = (int) $this->make_ticket()->id;
        $workflow_id = $this->create_workflow('internal_note.added');

        $this->tickets->add_note($ticket_id, $this->agent_id, 'Internal only.');

        $this->assertSame(1, $this->log_count($workflow_id));
    }

    public function test_a_legacy_untagged_workflow_runs_when_a_tag_is_removed(): void
    {
        $ticket_id = (int) $this->make_ticket()->id;
        $tag_id = $this->make_tag();
        $workflow_id = $this->create_workflow('ticket.untagged');

        $this->tickets->add_tags($ticket_id, [$tag_id]);
        $this->assertSame(0, $this->log_count($workflow_id));

        $this->tickets->remove_tags($ticket_id, [$tag_id]);
        $this->assertSame(1, $this->log_count($workflow_id));
    }

    public function test_workflow_log_records_the_canonical_trigger(): void
    {
        $ticket_id = (int) $this->make_ticket()->id;
        $workflow_id = $this->create_workflow('ticket.tagged');

        $this->tickets->add_tags($ticket_id, [$this->make_tag()]);

        global $wpdb;
        $table = Escalated::table('workflow_logs');
        $this->assertSame('ticket.tag_added', $wpdb->get_var($wpdb->prepare("SELECT trigger_event FROM {$table} WHERE workflow_id = %d", $workflow_id)));
    }

    // =========================================================================
    // Webhook names
    // =========================================================================

    public function test_tag_events_reach_the_webhook_under_canonical_names(): void
    {
        $ticket_id = (int) $this->make_ticket()->id;
        $tag_id = $this->make_tag();
        $this->http = [];

        $this->tickets->add_tags($ticket_id, [$tag_id]);
        $this->tickets->remove_tags($ticket_id, [$tag_id]);

        $added = $this->webhook('ticket.tag_added');
        $this->assertSame($ticket_id, $added['payload']['ticket']['id']);
        $this->assertSame($tag_id, $added['payload']['tag']['id']);
        $this->assertNotNull($this->webhook('ticket.tag_removed'));
    }

    public function test_internal_note_reaches_the_webhook_as_note_created_without_its_body(): void
    {
        $ticket_id = (int) $this->make_ticket()->id;
        $this->http = [];

        $note = $this->tickets->add_note($ticket_id, $this->agent_id, 'Customer is on the naughty list.');

        $sent = $this->webhook('note.created');
        $this->assertSame((int) $note->id, $sent['payload']['note']['id']);
        $this->assertSame($ticket_id, $sent['payload']['ticket']['id']);
        $this->assertStringNotContainsString('naughty', wp_json_encode($sent));
        $this->assertSame([], $this->webhooks_named('reply.created'));
    }

    public function test_status_lifecycle_events_reach_the_webhook(): void
    {
        $id = (int) $this->make_ticket()->id;
        $this->http = [];

        $this->tickets->change_status($id, 'escalated');
        $this->tickets->change_status($id, 'resolved');
        $this->tickets->change_status($id, 'reopened');
        $this->tickets->change_status($id, 'resolved');
        $this->tickets->change_status($id, 'closed');

        foreach (['ticket.escalated', 'ticket.resolved', 'ticket.reopened', 'ticket.closed'] as $event) {
            $this->assertNotNull($this->webhook($event), "{$event} was not sent.");
        }
    }

    public function test_priority_and_unassign_reach_the_webhook(): void
    {
        $id = (int) $this->make_ticket()->id;
        (new AssignmentService)->assign($id, $this->agent_id);
        $this->http = [];

        $this->tickets->change_priority($id, 'urgent', $this->agent_id);
        (new AssignmentService)->unassign($id);

        $priority = $this->webhook('ticket.priority_changed');
        $this->assertSame('low', $priority['payload']['old_priority']);
        $this->assertSame('urgent', $priority['payload']['new_priority']);
        $this->assertNotNull($this->webhook('ticket.unassigned'));
    }

    public function test_sla_warning_reaches_the_webhook_once(): void
    {
        $id = (int) $this->make_ticket()->id;
        \Escalated\Models\Ticket::update($id, [
            'first_response_due_at' => gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) + 15 * MINUTE_IN_SECONDS),
            'sla_first_response_breached' => 0,
        ]);
        $this->http = [];

        $sla = new SlaService;
        $sla->check_warnings(30);
        $sla->check_warnings(30);

        $this->assertCount(1, $this->webhooks_named('sla.warning'));
    }

    public function test_every_sent_event_name_is_canonical(): void
    {
        $id = (int) $this->make_ticket()->id;
        $tag_id = $this->make_tag();
        $this->tickets->add_tags($id, [$tag_id]);
        $this->tickets->add_note($id, $this->agent_id, 'note');
        $this->tickets->reply($id, $this->agent_id, 'reply');

        (new WebhookDispatcher)->process_due();
        $this->assertNotEmpty($this->http);
        foreach ($this->http as $call) {
            if ($call['url'] !== self::WEBHOOK_URL) {
                continue;
            }
            $event = json_decode($call['args']['body'], true)['event'];
            $this->assertContains($event, EventVocabulary::WEBHOOK_EVENTS);
            $this->assertSame($event, $call['args']['headers']['X-Escalated-Event']);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function webhook(string $event): ?array
    {
        $calls = $this->webhooks_named($event);

        return $calls[0] ?? null;
    }

    private function webhooks_named(string $event): array
    {
        (new WebhookDispatcher)->process_due();
        $out = [];
        foreach ($this->http as $call) {
            if ($call['url'] !== self::WEBHOOK_URL) {
                continue;
            }
            $body = json_decode($call['args']['body'], true);
            if (($body['event'] ?? null) === $event) {
                $out[] = $body;
            }
        }

        return $out;
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
        $slug = 'vocab-'.strtolower(wp_generate_password(8, false, false));
        $wpdb->insert(Tag::table(), [
            'name' => $slug,
            'slug' => $slug,
            'color' => '#336699',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function create_workflow(string $trigger): int
    {
        global $wpdb;
        $wpdb->insert(Workflow::table(), [
            'name' => 'Vocabulary: '.$trigger,
            'trigger_event' => $trigger,
            'conditions' => null,
            'actions' => wp_json_encode([['type' => 'set_type', 'value' => 'incident']]),
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
}
