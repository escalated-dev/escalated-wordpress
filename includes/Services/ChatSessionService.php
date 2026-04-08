<?php

namespace Escalated\Services;

use Escalated\Models\ChatSession;
use Escalated\Models\Ticket;

class ChatSessionService
{
    private ChatRoutingService $routing;

    private TicketService $ticket_service;

    public function __construct()
    {
        $this->routing = new ChatRoutingService;
        $this->ticket_service = new TicketService;
    }

    /**
     * Start a new chat session. Creates an underlying ticket with channel "chat".
     */
    public function start(string $visitor_name, ?string $visitor_email = null, ?string $initial_message = null, ?int $department_id = null): object
    {
        $ticket = $this->ticket_service->create_guest([
            'subject' => sprintf('Chat with %s', $visitor_name),
            'description' => $initial_message ?? '',
            'guest_name' => $visitor_name,
            'guest_email' => $visitor_email ?? '',
            'channel' => 'chat',
            'department_id' => $department_id,
        ]);

        $route = $this->routing->resolve($department_id);

        $session_data = [
            'ticket_id' => $ticket->id,
            'visitor_name' => sanitize_text_field($visitor_name),
            'visitor_email' => $visitor_email ? sanitize_email($visitor_email) : null,
            'department_id' => $route['department_id'] ?? $department_id,
            'agent_id' => $route['agent_id'] ?? null,
            'status' => ! empty($route['agent_id']) ? 'active' : 'waiting',
            'accepted_at' => ! empty($route['agent_id']) ? current_time('mysql') : null,
        ];

        $session_id = ChatSession::create($session_data);

        if (! $session_id) {
            throw new \RuntimeException('Failed to create chat session.');
        }

        $session = ChatSession::find($session_id);

        do_action('escalated_chat_started', $session);

        return $session;
    }

    /**
     * Agent accepts a waiting chat session.
     */
    public function accept(int $session_id, int $agent_id): object
    {
        $session = ChatSession::find($session_id);

        if (! $session) {
            throw new \InvalidArgumentException('Chat session not found.');
        }

        if ($session->status !== 'waiting') {
            throw new \InvalidArgumentException('Chat session is not in a waiting state.');
        }

        ChatSession::update($session_id, [
            'agent_id' => $agent_id,
            'status' => 'active',
            'accepted_at' => current_time('mysql'),
        ]);

        // Assign the ticket to the agent
        $this->ticket_service->assign($session->ticket_id, $agent_id);

        $session = ChatSession::find($session_id);

        do_action('escalated_chat_accepted', $session, $agent_id);

        return $session;
    }

    /**
     * Send a message within a chat session. Stored as a reply on the ticket.
     */
    public function send_message(int $session_id, string $body, ?int $author_id = null, string $author_type = 'visitor'): object
    {
        $session = ChatSession::find($session_id);

        if (! $session) {
            throw new \InvalidArgumentException('Chat session not found.');
        }

        if ($session->status === 'ended') {
            throw new \InvalidArgumentException('Chat session has ended.');
        }

        $reply = $this->ticket_service->add_reply($session->ticket_id, $author_id, [
            'body' => wp_kses_post($body),
            'is_internal_note' => false,
        ]);

        ChatSession::update($session_id, [
            'last_activity_at' => current_time('mysql'),
        ]);

        do_action('escalated_chat_message', $session, $reply);

        return $reply;
    }

    /**
     * End a chat session. The underlying ticket is resolved.
     */
    public function end(int $session_id, ?int $causer_id = null): object
    {
        $session = ChatSession::find($session_id);

        if (! $session) {
            throw new \InvalidArgumentException('Chat session not found.');
        }

        if ($session->status === 'ended') {
            throw new \InvalidArgumentException('Chat session has already ended.');
        }

        ChatSession::update($session_id, [
            'status' => 'ended',
            'ended_at' => current_time('mysql'),
        ]);

        $this->ticket_service->resolve($session->ticket_id, $causer_id);

        $session = ChatSession::find($session_id);

        do_action('escalated_chat_ended', $session, $causer_id);

        return $session;
    }

    /**
     * Get all waiting chat sessions.
     */
    public function get_waiting(): array
    {
        return ChatSession::get_waiting();
    }

    /**
     * Get active sessions for an agent.
     */
    public function get_active_for_agent(int $agent_id): array
    {
        return ChatSession::get_active_for_agent($agent_id);
    }

    /**
     * Get queue depth.
     */
    public function get_queue_depth(?int $department_id = null): int
    {
        return ChatSession::count_waiting($department_id);
    }
}
