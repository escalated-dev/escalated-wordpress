<?php

/**
 * Tests for WorkflowListener — confirms the registered WP hooks fire
 * the runner with the correct trigger event name.
 *
 * Mirrors the NestJS workflow.listener.ts unit tests and the event
 * mapping confirmed by the Spring WorkflowListenerTest.
 */

use Escalated\Escalated;
use Escalated\Models\Ticket;
use Escalated\Models\Workflow;
use Escalated\Services\AssignmentService;
use Escalated\Services\TicketService;
use Escalated\Services\WorkflowListener;

class Test_Workflow_Listener extends WP_UnitTestCase
{
    private WorkflowListener $listener;

    private TicketService $ticket_service;

    private int $user_id;

    private int $agent_id;

    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();

        $this->listener = new WorkflowListener;
        $this->listener->register();

        $this->ticket_service = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
    }

    public function tear_down(): void
    {
        remove_action('escalated_ticket_created', [$this->listener, 'on_ticket_created'], 50);
        remove_action('escalated_ticket_updated', [$this->listener, 'on_ticket_updated'], 50);
        remove_action('escalated_ticket_status_changed', [$this->listener, 'on_ticket_status_changed'], 50);
        remove_action('escalated_ticket_assigned', [$this->listener, 'on_ticket_assigned'], 50);
        remove_action('escalated_ticket_reopened', [$this->listener, 'on_ticket_reopened'], 50);
        remove_action('escalated_reply_created', [$this->listener, 'on_reply_created'], 50);
        remove_action('escalated_tag_added', [$this->listener, 'on_tag_changed'], 50);
        remove_action('escalated_tag_removed', [$this->listener, 'on_tag_changed'], 50);
        remove_action('escalated_department_changed', [$this->listener, 'on_department_changed'], 50);
        parent::tear_down();
    }

    private function create_workflow_for(string $trigger, array $actions): int
    {
        global $wpdb;
        $wpdb->insert(Workflow::table(), [
            'name' => 'Test wf for '.$trigger,
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

    private function make_ticket(array $overrides = []): object
    {
        return $this->ticket_service->create(
            $this->user_id,
            array_merge(['subject' => 'T', 'description' => 'b', 'channel' => 'web'], $overrides)
        );
    }

    private function count_logs_for(int $wf_id): int
    {
        global $wpdb;
        $table = Escalated::table('workflow_logs');

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE workflow_id = %d", $wf_id)
        );
    }

    public function test_ticket_created_fires_workflow(): void
    {
        $wf_id = $this->create_workflow_for(
            'ticket.created',
            [['type' => 'add_note', 'value' => 'auto-triaged']]
        );

        $this->make_ticket(); // TicketService::create fires escalated_ticket_created

        $this->assertEquals(1, $this->count_logs_for($wf_id));
    }

    public function test_status_changed_fires_status_changed_workflow(): void
    {
        $wf_id = $this->create_workflow_for(
            'ticket.status_changed',
            [['type' => 'add_note', 'value' => 'status autolog']]
        );
        $ticket = $this->make_ticket();

        $this->ticket_service->change_status($ticket->id, 'resolved');

        $this->assertGreaterThanOrEqual(1, $this->count_logs_for($wf_id));
    }

    public function test_assigned_fires_assigned_workflow(): void
    {
        $wf_id = $this->create_workflow_for(
            'ticket.assigned',
            [['type' => 'add_note', 'value' => 'assigned autolog']]
        );
        $ticket = $this->make_ticket();

        $assign = new AssignmentService;
        $assign->assign($ticket->id, $this->agent_id);

        $this->assertGreaterThanOrEqual(1, $this->count_logs_for($wf_id));
    }

    public function test_reply_created_fires_reply_workflow(): void
    {
        $wf_id = $this->create_workflow_for(
            'reply.created',
            [['type' => 'add_note', 'value' => 'reply autolog']]
        );
        $ticket = $this->make_ticket();

        $this->ticket_service->reply($ticket->id, $this->agent_id, 'customer answer');

        $this->assertGreaterThanOrEqual(1, $this->count_logs_for($wf_id));
    }

    public function test_non_matching_trigger_does_not_fire(): void
    {
        // Workflow is for reply.created; we only create a ticket.
        $wf_id = $this->create_workflow_for(
            'reply.created',
            [['type' => 'add_note', 'value' => 'should not fire']]
        );

        $this->make_ticket();

        $this->assertEquals(0, $this->count_logs_for($wf_id));
    }

    public function test_missing_ticket_in_hook_is_tolerated(): void
    {
        $wf_id = $this->create_workflow_for('ticket.created', []);

        // Fire the hook with bogus data — listener should swallow it.
        do_action('escalated_ticket_created', null);
        do_action('escalated_ticket_created', 'not an object');
        do_action('escalated_ticket_created', (object) ['id' => 0]);

        $this->assertEquals(0, $this->count_logs_for($wf_id));
    }
}
