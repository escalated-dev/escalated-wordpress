<?php

/**
 * Tests for the BroadcastService (real-time event polling).
 *
 * Covers event pushing, filtering by timestamp, buffer limits,
 * enable/disable toggle, event types, and lifecycle hook integration.
 */

use Escalated\Models\Setting;
use Escalated\Services\BroadcastService;
use Escalated\Services\TicketService;

class Test_Broadcast_Service extends WP_UnitTestCase
{
    private BroadcastService $broadcast;

    private TicketService $ticket_service;

    private int $user_id;

    private int $agent_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->broadcast = new BroadcastService;
        $this->ticket_service = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);

        // Enable broadcasting for tests.
        BroadcastService::set_enabled(true);
        BroadcastService::clear_events();
    }

    public function tear_down(): void
    {
        BroadcastService::clear_events();
        parent::tear_down();
    }

    /**
     * Helper: Create a ticket.
     */
    private function create_ticket(array $overrides = []): object
    {
        $defaults = [
            'subject' => 'Broadcast test ticket',
            'description' => 'Test description.',
            'priority' => 'medium',
            'channel' => 'web',
        ];

        return $this->ticket_service->create($this->user_id, array_merge($defaults, $overrides));
    }

    // =========================================================================
    // Enable/Disable Tests
    // =========================================================================

    public function test_broadcasting_disabled_by_default(): void
    {
        // Fresh setting (no explicit set).
        Setting::delete('escalated_broadcasting_enabled');

        $this->assertFalse(BroadcastService::is_enabled());
    }

    public function test_enable_broadcasting(): void
    {
        BroadcastService::set_enabled(true);

        $this->assertTrue(BroadcastService::is_enabled());
    }

    public function test_disable_broadcasting(): void
    {
        BroadcastService::set_enabled(true);
        BroadcastService::set_enabled(false);

        $this->assertFalse(BroadcastService::is_enabled());
    }

    // =========================================================================
    // Event Push Tests
    // =========================================================================

    public function test_push_event(): void
    {
        $this->broadcast->push_event('ticket.created', [
            'ticket_id' => 1,
            'reference' => 'ESC-00001',
        ]);

        $events = BroadcastService::get_events_buffer();
        $this->assertCount(1, $events);
        $this->assertEquals('ticket.created', $events[0]['type']);
        $this->assertEquals(1, $events[0]['payload']['ticket_id']);
    }

    public function test_push_multiple_events(): void
    {
        $this->broadcast->push_event('ticket.created', ['ticket_id' => 1]);
        $this->broadcast->push_event('ticket.updated', ['ticket_id' => 1]);
        $this->broadcast->push_event('reply.created', ['reply_id' => 1, 'ticket_id' => 1]);

        $events = BroadcastService::get_events_buffer();
        $this->assertCount(3, $events);
    }

    public function test_event_has_required_fields(): void
    {
        $this->broadcast->push_event('ticket.created', ['ticket_id' => 1]);

        $events = BroadcastService::get_events_buffer();
        $event = $events[0];

        $this->assertArrayHasKey('id', $event);
        $this->assertArrayHasKey('type', $event);
        $this->assertArrayHasKey('payload', $event);
        $this->assertArrayHasKey('timestamp', $event);
        $this->assertArrayHasKey('unix_timestamp', $event);
    }

    public function test_event_has_unique_id(): void
    {
        $this->broadcast->push_event('ticket.created', ['ticket_id' => 1]);
        $this->broadcast->push_event('ticket.created', ['ticket_id' => 2]);

        $events = BroadcastService::get_events_buffer();
        $this->assertNotEquals($events[0]['id'], $events[1]['id']);
    }

    // =========================================================================
    // Event Filtering Tests
    // =========================================================================

    public function test_get_events_since_filters_correctly(): void
    {
        $past = time() - 10;

        $this->broadcast->push_event('ticket.created', ['ticket_id' => 1]);

        $events = BroadcastService::get_events_since($past);
        $this->assertCount(1, $events);

        $future = time() + 100;
        $events = BroadcastService::get_events_since($future);
        $this->assertCount(0, $events);
    }

    public function test_get_events_since_zero_returns_all(): void
    {
        $this->broadcast->push_event('ticket.created', ['ticket_id' => 1]);
        $this->broadcast->push_event('ticket.updated', ['ticket_id' => 1]);

        $events = BroadcastService::get_events_since(0);
        $this->assertCount(2, $events);
    }

    // =========================================================================
    // Buffer Limit Tests
    // =========================================================================

    public function test_buffer_limits_to_max_events(): void
    {
        // Push more than MAX_EVENTS (100).
        for ($i = 0; $i < 110; $i++) {
            $this->broadcast->push_event('ticket.created', ['ticket_id' => $i]);
        }

        $events = BroadcastService::get_events_buffer();
        $this->assertCount(100, $events);

        // The oldest events should be dropped.
        $this->assertEquals(10, $events[0]['payload']['ticket_id']);
    }

    // =========================================================================
    // Clear Events Tests
    // =========================================================================

    public function test_clear_events(): void
    {
        $this->broadcast->push_event('ticket.created', ['ticket_id' => 1]);

        BroadcastService::clear_events();

        $events = BroadcastService::get_events_buffer();
        $this->assertCount(0, $events);
    }

    // =========================================================================
    // Event Types Tests
    // =========================================================================

    public function test_event_types(): void
    {
        $types = BroadcastService::event_types();

        $this->assertContains('ticket.created', $types);
        $this->assertContains('ticket.updated', $types);
        $this->assertContains('ticket.statusChanged', $types);
        $this->assertContains('reply.created', $types);
        $this->assertContains('ticket.assigned', $types);
    }

    // =========================================================================
    // Lifecycle Hook Integration Tests
    // =========================================================================

    public function test_ticket_created_pushes_event(): void
    {
        // Register hooks.
        $broadcast = new BroadcastService;
        $broadcast->register();

        $ticket = $this->create_ticket();

        $events = BroadcastService::get_events_buffer();
        $created_events = array_filter($events, fn ($e) => $e['type'] === 'ticket.created');

        $this->assertNotEmpty($created_events);
        $event = array_values($created_events)[0];
        $this->assertEquals((int) $ticket->id, $event['payload']['ticket_id']);
    }

    public function test_status_changed_pushes_event(): void
    {
        $broadcast = new BroadcastService;
        $broadcast->register();

        $ticket = $this->create_ticket();
        BroadcastService::clear_events();

        $this->ticket_service->change_status((int) $ticket->id, 'in_progress', $this->agent_id);

        $events = BroadcastService::get_events_buffer();
        $status_events = array_filter($events, fn ($e) => $e['type'] === 'ticket.statusChanged');

        $this->assertNotEmpty($status_events);
        $event = array_values($status_events)[0];
        $this->assertEquals('open', $event['payload']['old_status']);
        $this->assertEquals('in_progress', $event['payload']['new_status']);
    }

    public function test_reply_created_pushes_event(): void
    {
        $broadcast = new BroadcastService;
        $broadcast->register();

        $ticket = $this->create_ticket();
        BroadcastService::clear_events();

        $reply = $this->ticket_service->reply((int) $ticket->id, $this->agent_id, 'A reply.');

        $events = BroadcastService::get_events_buffer();
        $reply_events = array_filter($events, fn ($e) => $e['type'] === 'reply.created');

        $this->assertNotEmpty($reply_events);
        $event = array_values($reply_events)[0];
        $this->assertEquals((int) $reply->id, $event['payload']['reply_id']);
    }

    public function test_on_ticket_assigned(): void
    {
        $ticket = (object) [
            'id' => 1,
            'reference' => 'ESC-00001',
        ];

        $this->broadcast->on_ticket_assigned($ticket, null, $this->agent_id);

        $events = BroadcastService::get_events_buffer();
        $assigned_events = array_filter($events, fn ($e) => $e['type'] === 'ticket.assigned');

        $this->assertNotEmpty($assigned_events);
        $event = array_values($assigned_events)[0];
        $this->assertNull($event['payload']['old_agent_id']);
        $this->assertEquals($this->agent_id, $event['payload']['new_agent_id']);
    }
}
