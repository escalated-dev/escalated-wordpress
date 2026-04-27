<?php

/**
 * Tests for WorkflowRunnerService — orchestrates evaluation + execution
 * of Workflows for a given trigger event.
 *
 * Mirrors the NestJS reference unit tests for workflow-runner.service.ts.
 * Runs inside the WP test harness so wpdb DB writes against the real
 * workflow + workflow_logs tables, matching the test strategy of
 * Test_Workflow_Executor_Service.
 */

use Escalated\Escalated;
use Escalated\Models\Workflow;
use Escalated\Services\TicketService;
use Escalated\Services\WorkflowRunnerService;

class Test_Workflow_Runner_Service extends WP_UnitTestCase
{
    private WorkflowRunnerService $runner;

    private TicketService $ticket_service;

    private int $user_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        // The plugin auto-registers WorkflowListener at boot — testing
        // that integration is what Test_Workflow_Listener does. These
        // tests exercise the runner in isolation, so detach the hooks
        // here or every make_ticket() would double-fire the runner.
        remove_all_actions('escalated_ticket_created');
        remove_all_actions('escalated_ticket_updated');
        remove_all_actions('escalated_ticket_status_changed');
        remove_all_actions('escalated_ticket_assigned');
        remove_all_actions('escalated_ticket_reopened');
        remove_all_actions('escalated_reply_created');
        remove_all_actions('escalated_tag_added');
        remove_all_actions('escalated_tag_removed');
        remove_all_actions('escalated_department_changed');

        $this->runner = new WorkflowRunnerService;
        $this->ticket_service = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
    }

    private function make_ticket(array $overrides = []): object
    {
        $defaults = [
            'subject' => 'Test',
            'description' => 'Body',
            'priority' => 'low',
            'channel' => 'web',
        ];

        return $this->ticket_service->create($this->user_id, array_merge($defaults, $overrides));
    }

    private function create_workflow(array $data): int
    {
        global $wpdb;
        $defaults = [
            'name' => 'Test Workflow',
            'trigger_event' => 'ticket.created',
            'conditions' => null,
            'actions' => wp_json_encode([['type' => 'add_note', 'value' => 'auto']]),
            'is_active' => 1,
            'position' => 0,
            'stop_on_match' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        $wpdb->insert(Workflow::table(), array_merge($defaults, $data));

        return (int) $wpdb->insert_id;
    }

    private function count_logs(int $workflow_id = 0): int
    {
        global $wpdb;
        $table = Escalated::table('workflow_logs');
        if ($workflow_id > 0) {
            return (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE workflow_id = %d", $workflow_id)
            );
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public function test_run_for_event_with_no_matching_workflows_is_noop(): void
    {
        $ticket = $this->make_ticket();

        $executed = $this->runner->run_for_event('ticket.created', $ticket);

        $this->assertEquals(0, $executed);
        $this->assertEquals(0, $this->count_logs());
    }

    public function test_run_for_event_matches_and_executes(): void
    {
        $wf_id = $this->create_workflow([
            'name' => 'Auto-note on create',
            'trigger_event' => 'ticket.created',
        ]);
        $ticket = $this->make_ticket();

        $executed = $this->runner->run_for_event('ticket.created', $ticket);

        $this->assertEquals(1, $executed);
        $this->assertEquals(1, $this->count_logs($wf_id));

        global $wpdb;
        $log_table = Escalated::table('workflow_logs');
        $log = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$log_table} WHERE workflow_id = %d", $wf_id)
        );
        $this->assertNotNull($log);
        $this->assertEquals(1, (int) $log->conditions_matched);
        $this->assertNotEmpty($log->completed_at);
        $this->assertEmpty($log->error_message);
        $this->assertStringContainsString('add_note', $log->actions_executed);
    }

    public function test_run_for_event_unmatched_logs_but_does_not_execute(): void
    {
        $wf_id = $this->create_workflow([
            'name' => 'Only on closed',
            'trigger_event' => 'ticket.created',
            'conditions' => wp_json_encode([
                'all' => [['field' => 'status', 'operator' => 'equals', 'value' => 'closed']],
            ]),
        ]);
        $ticket = $this->make_ticket(); // status = open

        $executed = $this->runner->run_for_event('ticket.created', $ticket);

        $this->assertEquals(0, $executed);
        $this->assertEquals(1, $this->count_logs($wf_id));

        global $wpdb;
        $log_table = Escalated::table('workflow_logs');
        $log = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$log_table} WHERE workflow_id = %d", $wf_id)
        );
        $this->assertEquals(0, (int) $log->conditions_matched);
    }

    public function test_run_for_event_respects_trigger_filter(): void
    {
        $wf_id = $this->create_workflow([
            'name' => 'On-reply only',
            'trigger_event' => 'reply.created',
        ]);
        $ticket = $this->make_ticket();

        $executed = $this->runner->run_for_event('ticket.created', $ticket);

        $this->assertEquals(0, $executed);
        $this->assertEquals(0, $this->count_logs($wf_id));
    }

    public function test_run_for_event_skips_inactive_workflows(): void
    {
        $wf_id = $this->create_workflow([
            'name' => 'Disabled',
            'trigger_event' => 'ticket.created',
            'is_active' => 0,
        ]);
        $ticket = $this->make_ticket();

        $executed = $this->runner->run_for_event('ticket.created', $ticket);

        $this->assertEquals(0, $executed);
        $this->assertEquals(0, $this->count_logs($wf_id));
    }

    public function test_stop_on_match_halts_after_first_match(): void
    {
        $first_id = $this->create_workflow([
            'name' => 'First',
            'trigger_event' => 'ticket.created',
            'position' => 0,
            'stop_on_match' => 1,
            'actions' => wp_json_encode([['type' => 'change_priority', 'value' => 'high']]),
        ]);
        $second_id = $this->create_workflow([
            'name' => 'Second',
            'trigger_event' => 'ticket.created',
            'position' => 1,
            'stop_on_match' => 0,
            'actions' => wp_json_encode([['type' => 'change_priority', 'value' => 'urgent']]),
        ]);
        $ticket = $this->make_ticket();

        $this->runner->run_for_event('ticket.created', $ticket);

        // First log exists; second doesn't (we stopped).
        $this->assertEquals(1, $this->count_logs($first_id));
        $this->assertEquals(0, $this->count_logs($second_id));
    }

    public function test_stop_on_match_only_applies_on_match(): void
    {
        $first_id = $this->create_workflow([
            'name' => 'First (non-matching)',
            'trigger_event' => 'ticket.created',
            'position' => 0,
            'stop_on_match' => 1,
            'conditions' => wp_json_encode([
                'all' => [['field' => 'status', 'operator' => 'equals', 'value' => 'closed']],
            ]),
        ]);
        $second_id = $this->create_workflow([
            'name' => 'Second',
            'trigger_event' => 'ticket.created',
            'position' => 1,
            'stop_on_match' => 0,
        ]);
        $ticket = $this->make_ticket();

        $this->runner->run_for_event('ticket.created', $ticket);

        // Both logs exist since first didn't match.
        $this->assertEquals(1, $this->count_logs($first_id));
        $this->assertEquals(1, $this->count_logs($second_id));
    }

    public function test_executes_in_position_order(): void
    {
        $this->create_workflow([
            'name' => 'Second by position',
            'trigger_event' => 'ticket.created',
            'position' => 10,
            'actions' => wp_json_encode([['type' => 'change_priority', 'value' => 'urgent']]),
        ]);
        $this->create_workflow([
            'name' => 'First by position',
            'trigger_event' => 'ticket.created',
            'position' => 1,
            'actions' => wp_json_encode([['type' => 'change_priority', 'value' => 'high']]),
        ]);
        $ticket = $this->make_ticket();

        $this->runner->run_for_event('ticket.created', $ticket);

        // Later workflow wins since the high→urgent happens last.
        $fresh = \Escalated\Models\Ticket::find($ticket->id);
        $this->assertEquals('urgent', $fresh->priority);
    }
}
