<?php

/**
 * Tests for the ChatSessionService class.
 *
 * Covers chat session lifecycle: starting, accepting, messaging, and ending.
 */

use Escalated\Services\ChatSessionService;

class Test_Chat_Session_Service extends WP_UnitTestCase
{
    private ChatSessionService $service;

    private int $agent_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->service = new ChatSessionService;
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
    }

    /**
     * Helper: Start a chat session via the service.
     */
    private function start_chat(array $overrides = []): object
    {
        $defaults = [
            'name' => 'Test Visitor',
            'email' => 'visitor@test.com',
            'message' => 'Hello, I need help!',
        ];

        $data = array_merge($defaults, $overrides);

        return $this->service->start(
            $data['name'],
            $data['email'],
            $data['message'],
            $data['department_id'] ?? null
        );
    }

    // =========================================================================
    // Start Tests
    // =========================================================================

    public function test_start_creates_session(): void
    {
        $session = $this->start_chat();

        $this->assertNotEmpty($session->id);
        $this->assertEquals('Test Visitor', $session->visitor_name);
        $this->assertEquals('visitor@test.com', $session->visitor_email);
        $this->assertContains($session->status, ['waiting', 'active']);
        $this->assertNotEmpty($session->ticket_id);
    }

    public function test_start_creates_underlying_ticket(): void
    {
        $session = $this->start_chat();

        $ticket = \Escalated\Models\Ticket::find((int) $session->ticket_id);

        $this->assertNotNull($ticket);
        $this->assertEquals('chat', $ticket->channel);
        $this->assertStringContainsString('Chat with Test Visitor', $ticket->subject);
    }

    // =========================================================================
    // Accept Tests
    // =========================================================================

    public function test_accept_transitions_to_active(): void
    {
        $session = $this->start_chat();
        $accepted = $this->service->accept((int) $session->id, $this->agent_id);

        $this->assertEquals('active', $accepted->status);
        $this->assertEquals($this->agent_id, (int) $accepted->agent_id);
        $this->assertNotEmpty($accepted->accepted_at);
    }

    public function test_accept_throws_for_non_waiting(): void
    {
        $session = $this->start_chat();
        $this->service->accept((int) $session->id, $this->agent_id);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->accept((int) $session->id, $this->agent_id);
    }

    // =========================================================================
    // Message Tests
    // =========================================================================

    public function test_send_message_creates_reply(): void
    {
        $session = $this->start_chat();
        $reply = $this->service->send_message((int) $session->id, 'Hello from visitor');

        $this->assertNotNull($reply);
    }

    public function test_send_message_throws_for_ended(): void
    {
        $session = $this->start_chat();
        $this->service->end((int) $session->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->send_message((int) $session->id, 'too late');
    }

    // =========================================================================
    // End Tests
    // =========================================================================

    public function test_end_transitions_to_ended(): void
    {
        $session = $this->start_chat();
        $ended = $this->service->end((int) $session->id);

        $this->assertEquals('ended', $ended->status);
        $this->assertNotEmpty($ended->ended_at);
    }

    public function test_end_throws_for_already_ended(): void
    {
        $session = $this->start_chat();
        $this->service->end((int) $session->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->end((int) $session->id);
    }

    // =========================================================================
    // Queue Tests
    // =========================================================================

    public function test_get_waiting_returns_only_waiting(): void
    {
        $s1 = $this->start_chat(['name' => 'Visitor1']);
        $s2 = $this->start_chat(['name' => 'Visitor2']);
        $this->service->accept((int) $s2->id, $this->agent_id);

        $waiting = $this->service->get_waiting();

        $this->assertCount(1, $waiting);
        $this->assertEquals('Visitor1', $waiting[0]->visitor_name);
    }

    public function test_get_queue_depth(): void
    {
        $this->start_chat();
        $this->start_chat();

        $depth = $this->service->get_queue_depth();

        $this->assertEquals(2, $depth);
    }
}
