<?php

/**
 * Tests for WorkflowExecutorService — action dispatch.
 *
 * Mirrors the NestJS reference unit tests for workflow-executor.service.ts.
 * Runs inside the WP test harness so TicketService + AssignmentService
 * can write to real DB tables, matching the way Test_Ticket_Service exercises
 * the service boundary.
 */

use Escalated\Models\DeferredWorkflowJob;
use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;
use Escalated\Services\TicketService;
use Escalated\Services\WorkflowExecutorService;

class Test_Workflow_Executor_Service extends WP_UnitTestCase
{
    private WorkflowExecutorService $executor;

    private TicketService $ticket_service;

    private int $user_id;

    private int $agent_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->executor = new WorkflowExecutorService;
        $this->ticket_service = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
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

    public function test_execute_change_priority_updates_ticket(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'change_priority', 'value' => 'high']])
        );

        $fresh = Ticket::find($ticket->id);
        $this->assertEquals('high', $fresh->priority);
    }

    public function test_execute_change_status_updates_ticket(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'change_status', 'value' => 'resolved']])
        );

        $fresh = Ticket::find($ticket->id);
        $this->assertEquals('resolved', $fresh->status);
    }

    public function test_execute_assign_agent_sets_assignee(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'assign_agent', 'value' => (string) $this->agent_id]])
        );

        $fresh = Ticket::find($ticket->id);
        $this->assertEquals($this->agent_id, (int) $fresh->assigned_to);
    }

    public function test_execute_assign_agent_blank_value_is_noop(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'assign_agent', 'value' => '0']])
        );

        $fresh = Ticket::find($ticket->id);
        $this->assertEmpty($fresh->assigned_to);
    }

    public function test_execute_add_note_creates_internal_reply(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'add_note', 'value' => 'Triaged by workflow']])
        );

        global $wpdb;
        $table = Reply::table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id DESC LIMIT 1", $ticket->id)
        );
        $this->assertNotNull($row);
        $this->assertEquals('Triaged by workflow', $row->body);
        $this->assertEquals(1, (int) $row->is_internal_note);
        $this->assertEquals('note', $row->type);
    }

    public function test_execute_add_note_blank_value_skipped(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'add_note', 'value' => '  ']])
        );

        global $wpdb;
        $table = Reply::table();
        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d", $ticket->id)
        );
        $this->assertEquals(0, $count);
    }

    public function test_execute_insert_canned_reply_interpolates_variables(): void
    {
        $ticket = $this->make_ticket(['subject' => 'Login issue']);

        $this->executor->execute(
            $ticket,
            wp_json_encode([[
                'type' => 'insert_canned_reply',
                'value' => 'Re: {{subject}} (ref {{reference}})',
            ]])
        );

        global $wpdb;
        $table = Reply::table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id DESC LIMIT 1", $ticket->id)
        );
        $this->assertNotNull($row);
        $this->assertStringContainsString('Re: Login issue (ref', $row->body);
        $this->assertEquals(0, (int) $row->is_internal_note);
        $this->assertEquals('reply', $row->type);
    }

    public function test_execute_insert_canned_reply_unknown_variable_left_literal(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([[
                'type' => 'insert_canned_reply',
                'value' => 'Hi {{not_a_field}}',
            ]])
        );

        global $wpdb;
        $table = Reply::table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id DESC LIMIT 1", $ticket->id)
        );
        $this->assertEquals('Hi {{not_a_field}}', $row->body);
    }

    public function test_execute_malformed_json_returns_empty_actions(): void
    {
        $ticket = $this->make_ticket();

        $result = $this->executor->execute($ticket, 'not json');

        $this->assertSame([], $result);
    }

    public function test_execute_empty_string_returns_empty_actions(): void
    {
        $ticket = $this->make_ticket();

        $result = $this->executor->execute($ticket, '');

        $this->assertSame([], $result);
    }

    public function test_execute_null_returns_empty_actions(): void
    {
        $ticket = $this->make_ticket();

        $result = $this->executor->execute($ticket, null);

        $this->assertSame([], $result);
    }

    public function test_execute_unknown_action_type_skipped(): void
    {
        $ticket = $this->make_ticket();

        // Should not throw; other actions in the same list should still run.
        $result = $this->executor->execute(
            $ticket,
            wp_json_encode([
                ['type' => 'future_action', 'value' => 'x'],
                ['type' => 'change_priority', 'value' => 'urgent'],
            ])
        );

        $this->assertCount(2, $result);
        $fresh = Ticket::find($ticket->id);
        $this->assertEquals('urgent', $fresh->priority);
    }

    public function test_execute_add_tag_by_slug_attaches_tag(): void
    {
        $ticket = $this->make_ticket();
        global $wpdb;
        $tags_table = Tag::table();
        $wpdb->insert($tags_table, [
            'name' => 'urgent',
            'slug' => 'urgent',
            'color' => '#ff0000',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $tag_id = (int) $wpdb->insert_id;

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'add_tag', 'value' => 'urgent']])
        );

        $pivot = Escalated\Escalated::table('ticket_tag');
        $attached = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$pivot} WHERE ticket_id = %d AND tag_id = %d",
                $ticket->id,
                $tag_id
            )
        );
        $this->assertEquals(1, $attached);
    }

    public function test_execute_add_follower_inserts_row(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'add_follower', 'value' => (string) $this->agent_id]])
        );

        global $wpdb;
        $table = Escalated\Escalated::table('ticket_followers');
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d AND user_id = %d",
                $ticket->id,
                $this->agent_id
            )
        );
        $this->assertEquals(1, $count);
    }

    public function test_execute_add_follower_is_idempotent(): void
    {
        $ticket = $this->make_ticket();
        $json = wp_json_encode([['type' => 'add_follower', 'value' => (string) $this->agent_id]]);

        $this->executor->execute($ticket, $json);
        $this->executor->execute($ticket, $json);

        global $wpdb;
        $table = Escalated\Escalated::table('ticket_followers');
        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d", $ticket->id)
        );
        $this->assertEquals(1, $count);
    }

    public function test_execute_add_follower_blank_value_is_noop(): void
    {
        $ticket = $this->make_ticket();

        $this->executor->execute(
            $ticket,
            wp_json_encode([['type' => 'add_follower', 'value' => '0']])
        );

        global $wpdb;
        $table = Escalated\Escalated::table('ticket_followers');
        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d", $ticket->id)
        );
        $this->assertEquals(0, $count);
    }

    public function test_execute_returns_parsed_action_list(): void
    {
        $ticket = $this->make_ticket();

        $result = $this->executor->execute(
            $ticket,
            wp_json_encode([
                ['type' => 'change_priority', 'value' => 'high'],
                ['type' => 'add_note', 'value' => 'go'],
            ])
        );

        $this->assertCount(2, $result);
        $this->assertEquals('change_priority', $result[0]['type']);
        $this->assertEquals('add_note', $result[1]['type']);
    }

    // --- delay action ---

    public function test_execute_delay_pauses_and_persists_remaining(): void
    {
        global $wpdb;
        $ticket = $this->make_ticket(['priority' => 'low']);
        $before = time();

        $this->executor->execute(
            $ticket,
            wp_json_encode([
                ['type' => 'change_priority', 'value' => 'high'],
                ['type' => 'delay', 'value' => '60'],
                ['type' => 'add_note', 'value' => 'after wait'],
            ])
        );

        // Pre-delay action ran.
        $fresh = Ticket::find($ticket->id);
        $this->assertEquals('high', $fresh->priority);

        // Post-delay action did NOT run: no note reply inserted yet.
        $reply_table = Reply::table();
        $notes = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$reply_table} WHERE ticket_id = %d AND type = %s",
                $ticket->id,
                'note'
            )
        );
        $this->assertEquals(0, $notes);

        // One DeferredWorkflowJob row was persisted with the remaining tail.
        $jobs_table = DeferredWorkflowJob::table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$jobs_table} WHERE ticket_id = %d", $ticket->id)
        );
        $this->assertNotNull($row);
        $this->assertEquals('pending', $row->status);
        $remaining = json_decode($row->remaining_actions, true);
        $this->assertCount(1, $remaining);
        $this->assertEquals('add_note', $remaining[0]['type']);
        $this->assertGreaterThanOrEqual($before + 59, strtotime($row->run_at.' UTC'));
    }

    public function test_execute_delay_invalid_value_skips_remaining(): void
    {
        global $wpdb;
        $ticket = $this->make_ticket(['priority' => 'low']);

        $this->executor->execute(
            $ticket,
            wp_json_encode([
                ['type' => 'delay', 'value' => 'nonsense'],
                ['type' => 'change_priority', 'value' => 'urgent'],
            ])
        );

        // Priority unchanged — post-delay action did not run.
        $fresh = Ticket::find($ticket->id);
        $this->assertEquals('low', $fresh->priority);

        // No deferred job row was created.
        $jobs_table = DeferredWorkflowJob::table();
        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$jobs_table} WHERE ticket_id = %d", $ticket->id)
        );
        $this->assertEquals(0, $count);
    }

    public function test_run_due_deferred_jobs_resumes_and_marks_done(): void
    {
        global $wpdb;
        $ticket = $this->make_ticket(['priority' => 'low']);

        // Seed a job whose run_at has already elapsed.
        $id = DeferredWorkflowJob::create([
            'ticket_id' => $ticket->id,
            'remaining_actions' => wp_json_encode([
                ['type' => 'change_priority', 'value' => 'urgent'],
            ]),
            'run_at' => gmdate('Y-m-d H:i:s', time() - 60),
            'status' => 'pending',
        ]);
        $this->assertNotFalse($id);

        $result = $this->executor->run_due_deferred_jobs();

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['failed']);

        // Ticket priority was updated.
        $fresh = Ticket::find($ticket->id);
        $this->assertEquals('urgent', $fresh->priority);

        // Row flipped to done.
        $row = DeferredWorkflowJob::find($id);
        $this->assertEquals('done', $row->status);
    }

    public function test_run_due_deferred_jobs_marks_failed_when_ticket_missing(): void
    {
        $id = DeferredWorkflowJob::create([
            'ticket_id' => 9_999_999,
            'remaining_actions' => wp_json_encode([]),
            'run_at' => gmdate('Y-m-d H:i:s', time() - 60),
            'status' => 'pending',
        ]);

        $result = $this->executor->run_due_deferred_jobs();

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(1, $result['failed']);

        $row = DeferredWorkflowJob::find($id);
        $this->assertEquals('failed', $row->status);
        $this->assertStringContainsString('9999999', $row->last_error);
    }

    public function test_run_due_deferred_jobs_skips_rows_not_yet_due(): void
    {
        $id = DeferredWorkflowJob::create([
            'ticket_id' => $this->make_ticket()->id,
            'remaining_actions' => wp_json_encode([]),
            'run_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'status' => 'pending',
        ]);

        $result = $this->executor->run_due_deferred_jobs();

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['failed']);

        $row = DeferredWorkflowJob::find($id);
        $this->assertEquals('pending', $row->status);
    }
}
