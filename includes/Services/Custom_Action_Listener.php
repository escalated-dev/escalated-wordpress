<?php

namespace Escalated\Services;

/**
 * Records an internal note whenever a custom ticket action is triggered, giving
 * an audit trail of who ran which action. The note is authored by the
 * triggering agent, so the body need not repeat their name.
 *
 * Register once during plugin boot by calling ->register().
 *
 * Mirrors the Laravel RecordCustomActionInternalNote listener and the NestJS
 * RecordCustomActionInternalNoteListener.
 */
class Custom_Action_Listener
{
    public function register(): void
    {
        add_action('escalated_ticket_action_triggered', [$this, 'record_note'], 10, 5);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function record_note($ticket, string $action_key, int $user_id, array $payload = [], array $metadata = []): void
    {
        if (! is_object($ticket) || empty($ticket->id)) {
            return;
        }

        (new TicketService)->add_note(
            (int) $ticket->id,
            $user_id,
            sprintf('Custom action "%s" was triggered.', $action_key),
        );
    }
}
