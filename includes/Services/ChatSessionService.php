<?php

namespace Escalated\Services;

use Escalated\Models\ChatSession;
use Escalated\Models\Ticket;

class ChatSessionService
{
    private ChatRoutingService $routing;

    private TicketService $ticket_service;

    private AssignmentService $assignment_service;

    public function __construct()
    {
        $this->routing = new ChatRoutingService;
        $this->ticket_service = new TicketService;
        $this->assignment_service = new AssignmentService;
    }

    /**
     * Start a new chat session. Creates an underlying ticket with channel "chat".
     */
    public function start(string $visitor_name, ?string $visitor_email = null, ?string $initial_message = null, ?int $department_id = null, ?array $metadata = null): object
    {
        $route = null;
        if (TicketAccess::enabled() || \Escalated\Models\Setting::get_bool('targeted_slas')) {
            $route = $this->routing->resolve($department_id);
            $department_id = $route['department_id'] ?? $department_id;
        }
        $ticket = $this->ticket_service->create_guest([
            'subject' => sprintf('Chat with %s', $visitor_name),
            'description' => $initial_message ?? '',
            'guest_name' => $visitor_name,
            'guest_email' => $visitor_email ?? '',
            'channel' => 'chat',
            'department_id' => $department_id,
        ]);

        $route ??= $this->routing->resolve($department_id);
        if (! empty($route['agent_id']) && ! TicketAccess::can_receive((int) $ticket->id, (int) $route['agent_id'])) {
            $route['agent_id'] = null;
        }

        $session_data = [
            'ticket_id' => $ticket->id,
            'visitor_name' => sanitize_text_field($visitor_name),
            'visitor_email' => $visitor_email ? sanitize_email($visitor_email) : null,
            'department_id' => $route['department_id'] ?? $department_id,
            'agent_id' => $route['agent_id'] ?? null,
            'status' => ! empty($route['agent_id']) ? 'active' : 'waiting',
            'accepted_at' => ! empty($route['agent_id']) ? current_time('mysql') : null,
            'metadata' => ! empty($metadata) ? wp_json_encode($metadata) : null,
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

        if (! TicketAccess::can_receive((int) $session->ticket_id, $agent_id)) {
            throw new \InvalidArgumentException('Agent cannot access this ticket.');
        }

        ChatSession::update($session_id, [
            'agent_id' => $agent_id,
            'status' => 'active',
            'accepted_at' => current_time('mysql'),
        ]);

        // Assign the ticket to the agent
        $this->assignment_service->assign($session->ticket_id, $agent_id);

        $session = ChatSession::find($session_id);

        do_action('escalated_chat_accepted', $session, $agent_id);

        return $session;
    }

    /**
     * Hand a chat to another agent and/or department, as escalated-laravel's
     * ChatSessionService::transferChat. With an agent, the session and its
     * ticket are reassigned to that agent. With a department, the ticket
     * moves there; without an agent too, the chat goes back to the queue
     * (unassigned, waiting) and routing picks an agent for its new
     * department. Fires escalated_chat_transferred (chat.transferred) with
     * the previous agent, the new agent and the new department.
     *
     * @throws \InvalidArgumentException when neither target is given, the chat
     *                                   has ended, the department does not
     *                                   exist, or the agent cannot take it
     */
    public function transfer(int $session_id, ?int $to_agent_id = null, ?int $to_department_id = null): object
    {
        $session = ChatSession::find($session_id);

        if (! $session) {
            throw new \InvalidArgumentException('Chat session not found.');
        }

        if ($session->status === 'ended') {
            throw new \InvalidArgumentException('Chat session has already ended.');
        }

        if (! $to_agent_id && ! $to_department_id) {
            throw new \InvalidArgumentException('Provide an agent_id or department_id.');
        }

        if ($to_department_id && ! \Escalated\Models\Department::find($to_department_id)) {
            throw new \InvalidArgumentException('The selected department_id is invalid.');
        }

        $ticket_id = (int) $session->ticket_id;
        $from_agent_id = $session->agent_id ? (int) $session->agent_id : null;

        return TicketAccess::without_restriction(function () use ($session_id, $ticket_id, $from_agent_id, $to_agent_id, $to_department_id) {
            // Checked against the ticket as it will be (in its new department).
            $prospective = (array) Ticket::find($ticket_id);
            if ($to_department_id) {
                $prospective['department_id'] = $to_department_id;
            }
            if ($to_agent_id && (! TicketAccess::is_staff($to_agent_id) || ! TicketAccess::allows($prospective, $to_agent_id))) {
                throw new \InvalidArgumentException('Agent cannot access this ticket.');
            }

            $ticket_updates = ['assigned_to' => $to_agent_id ?: null];
            if ($to_department_id) {
                $ticket_updates['department_id'] = $to_department_id;
            }
            Ticket::update($ticket_id, $ticket_updates);

            $session_updates = [];
            if ($to_department_id) {
                $session_updates['department_id'] = $to_department_id;
            }
            if ($to_agent_id) {
                $session_updates['agent_id'] = $to_agent_id;
            } else {
                $session_updates['agent_id'] = null;
                $session_updates['status'] = 'waiting';
                $session_updates['accepted_at'] = null;
            }
            ChatSession::update($session_id, $session_updates);

            $session = ChatSession::find($session_id);

            do_action('escalated_chat_transferred', $session, $from_agent_id, $to_agent_id, $to_department_id);

            if (! $to_agent_id) {
                $route = $this->routing->resolve($to_department_id);
                if (! empty($route['agent_id']) && TicketAccess::can_receive($ticket_id, (int) $route['agent_id'])) {
                    $session = $this->accept($session_id, (int) $route['agent_id']);
                }
            }

            return $session;
        });
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

        $reply = $this->ticket_service->reply($session->ticket_id, $author_id ?? 0, wp_kses_post($body));

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
