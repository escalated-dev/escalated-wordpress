<?php

/**
 * Tests for the TicketSnoozeService class.
 *
 * Covers snoozing, unsnoozing, wake logic, status restoration,
 * activity logging, and snoozed ticket exclusion.
 */

use Escalated\Models\Ticket;
use Escalated\Services\TicketService;
use Escalated\Services\TicketSnoozeService;

class Test_Ticket_Snooze_Service extends WP_UnitTestCase
{
    private TicketService $ticket_service;

    private TicketSnoozeService $snooze_service;

    private int $user_id;

    private int $agent_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->ticket_service = new TicketService;
        $this->snooze_service = new TicketSnoozeService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
    }

    /**
     * Helper: Create a ticket via the service.
     */
    private function create_ticket(array $overrides = []): object
    {
        $defaults = [
            'subject' => 'Test ticket',
            'description' => 'Test description.',
            'priority' => 'medium',
            'channel' => 'web',
        ];

        return $this->ticket_service->create($this->user_id, array_merge($defaults, $overrides));
    }

    // =========================================================================
    // Snooze Tests
    // =========================================================================

    public function test_snooze_ticket_changes_status(): void
    {
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $snoozed = $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);

        $this->assertEquals('snoozed', $snoozed->status);
    }

    public function test_snooze_ticket_stores_metadata(): void
    {
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);

        $this->assertTrue($this->snooze_service->is_snoozed((int) $ticket->id));
        $this->assertEquals($future, $this->snooze_service->get_snooze_until((int) $ticket->id));
    }

    public function test_snooze_ticket_logs_activity(): void
    {
        global $wpdb;
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);

        $activity_table = \Escalated\Escalated::table('ticket_activities');
        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$activity_table} WHERE ticket_id = %d AND type = 'snoozed' LIMIT 1",
                $ticket->id
            )
        );

        $this->assertNotNull($activity);
        $props = json_decode($activity->properties, true);
        $this->assertEquals($future, $props['snoozed_until']);
        $this->assertEquals('open', $props['previous_status']);
    }

    public function test_snooze_ticket_fires_action(): void
    {
        $fired = false;
        add_action('escalated_ticket_snoozed', function () use (&$fired) {
            $fired = true;
        });

        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);

        $this->assertTrue($fired, 'escalated_ticket_snoozed action should fire.');
    }

    public function test_snooze_already_snoozed_throws(): void
    {
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);

        $this->expectException(\InvalidArgumentException::class);
        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);
    }

    public function test_snooze_past_time_throws(): void
    {
        $ticket = $this->create_ticket();
        $past = gmdate('Y-m-d H:i:s', strtotime('-1 hour'));

        $this->expectException(\InvalidArgumentException::class);
        $this->snooze_service->snooze_ticket((int) $ticket->id, $past, $this->agent_id);
    }

    public function test_snooze_nonexistent_ticket_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->snooze_service->snooze_ticket(999999, gmdate('Y-m-d H:i:s', strtotime('+1 hour')), $this->agent_id);
    }

    // =========================================================================
    // Unsnooze Tests
    // =========================================================================

    public function test_unsnooze_restores_previous_status(): void
    {
        $ticket = $this->create_ticket();
        $this->ticket_service->change_status((int) $ticket->id, 'in_progress', $this->agent_id);
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);
        $unsnoozed = $this->snooze_service->unsnooze_ticket((int) $ticket->id, $this->agent_id);

        $this->assertEquals('in_progress', $unsnoozed->status);
    }

    public function test_unsnooze_clears_metadata(): void
    {
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);
        $this->snooze_service->unsnooze_ticket((int) $ticket->id, $this->agent_id);

        $this->assertFalse($this->snooze_service->is_snoozed((int) $ticket->id));
        $this->assertNull($this->snooze_service->get_snooze_until((int) $ticket->id));
    }

    public function test_unsnooze_logs_activity(): void
    {
        global $wpdb;
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);
        $this->snooze_service->unsnooze_ticket((int) $ticket->id, $this->agent_id);

        $activity_table = \Escalated\Escalated::table('ticket_activities');
        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$activity_table} WHERE ticket_id = %d AND type = 'unsnoozed' LIMIT 1",
                $ticket->id
            )
        );

        $this->assertNotNull($activity);
        $props = json_decode($activity->properties, true);
        $this->assertEquals('open', $props['restored_status']);
    }

    public function test_unsnooze_fires_action(): void
    {
        $fired = false;
        add_action('escalated_ticket_unsnoozed', function () use (&$fired) {
            $fired = true;
        });

        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);
        $this->snooze_service->unsnooze_ticket((int) $ticket->id, $this->agent_id);

        $this->assertTrue($fired, 'escalated_ticket_unsnoozed action should fire.');
    }

    public function test_unsnooze_not_snoozed_throws(): void
    {
        $ticket = $this->create_ticket();

        $this->expectException(\InvalidArgumentException::class);
        $this->snooze_service->unsnooze_ticket((int) $ticket->id, $this->agent_id);
    }

    // =========================================================================
    // Wake / Cron Tests
    // =========================================================================

    public function test_wake_snoozed_tickets_unsnoozes_expired(): void
    {
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);

        // Manually set the snooze time to the past.
        global $wpdb;
        $meta_table = \Escalated\Escalated::table('ticket_meta');
        $wpdb->update(
            $meta_table,
            ['meta_value' => gmdate('Y-m-d H:i:s', strtotime('-1 hour'))],
            ['ticket_id' => $ticket->id, 'meta_key' => 'snoozed_until']
        );

        $this->snooze_service->wake_snoozed_tickets();

        $updated = Ticket::find((int) $ticket->id);
        $this->assertNotEquals('snoozed', $updated->status);
        $this->assertFalse($this->snooze_service->is_snoozed((int) $ticket->id));
    }

    public function test_wake_does_not_unsnooze_future_tickets(): void
    {
        $ticket = $this->create_ticket();
        $future = gmdate('Y-m-d H:i:s', strtotime('+2 hours'));

        $this->snooze_service->snooze_ticket((int) $ticket->id, $future, $this->agent_id);

        $this->snooze_service->wake_snoozed_tickets();

        $updated = Ticket::find((int) $ticket->id);
        $this->assertEquals('snoozed', $updated->status);
        $this->assertTrue($this->snooze_service->is_snoozed((int) $ticket->id));
    }

    // =========================================================================
    // Snoozed Ticket Exclusion Tests
    // =========================================================================

    public function test_get_snoozed_ticket_ids_returns_snoozed(): void
    {
        $ticket1 = $this->create_ticket(['subject' => 'Snoozed one']);
        $ticket2 = $this->create_ticket(['subject' => 'Not snoozed']);
        $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->snooze_service->snooze_ticket((int) $ticket1->id, $future, $this->agent_id);

        $snoozed_ids = TicketSnoozeService::get_snoozed_ticket_ids();

        $this->assertContains((int) $ticket1->id, $snoozed_ids);
        $this->assertNotContains((int) $ticket2->id, $snoozed_ids);
    }
}
