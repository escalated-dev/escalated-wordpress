<?php

namespace Escalated\Services;

use Escalated\Models\Setting;

class BroadcastService
{
    /**
     * Maximum number of events to store in the buffer.
     */
    private const MAX_EVENTS = 100;

    /**
     * Transient key for the event buffer.
     */
    private const TRANSIENT_KEY = 'escalated_broadcast_events';

    /**
     * TTL for the event buffer transient (5 minutes).
     */
    private const BUFFER_TTL = 300;

    /**
     * Register WordPress hooks to capture ticket lifecycle events.
     */
    public function register(): void
    {
        if (! self::is_enabled()) {
            return;
        }

        add_action('escalated_ticket_created', [$this, 'on_ticket_created'], 20, 1);
        add_action('escalated_ticket_updated', [$this, 'on_ticket_updated'], 20, 1);
        add_action('escalated_ticket_status_changed', [$this, 'on_ticket_status_changed'], 20, 4);
        add_action('escalated_reply_created', [$this, 'on_reply_created'], 20, 2);
        add_action('escalated_ticket_assigned', [$this, 'on_ticket_assigned'], 20, 3);
    }

    /**
     * Check if broadcasting is enabled.
     */
    public static function is_enabled(): bool
    {
        return Setting::get_bool('escalated_broadcasting_enabled', false);
    }

    /**
     * Enable or disable broadcasting.
     */
    public static function set_enabled(bool $enabled): void
    {
        Setting::set('escalated_broadcasting_enabled', $enabled ? '1' : '0');
    }

    /**
     * Handle ticket created event.
     */
    public function on_ticket_created(object $ticket): void
    {
        $this->push_event('ticket.created', [
            'ticket_id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
        ]);
    }

    /**
     * Handle ticket updated event.
     */
    public function on_ticket_updated(object $ticket): void
    {
        $this->push_event('ticket.updated', [
            'ticket_id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
        ]);
    }

    /**
     * Handle ticket status changed event.
     */
    public function on_ticket_status_changed(object $ticket, string $old_status, string $new_status, ?int $causer_id = null): void
    {
        $this->push_event('ticket.statusChanged', [
            'ticket_id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'causer_id' => $causer_id,
        ]);
    }

    /**
     * Handle reply created event.
     */
    public function on_reply_created(object $reply, object $ticket): void
    {
        $this->push_event('reply.created', [
            'reply_id' => (int) $reply->id,
            'ticket_id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'author_id' => (int) $reply->author_id,
            'is_internal_note' => (bool) $reply->is_internal_note,
        ]);
    }

    /**
     * Handle ticket assigned event.
     */
    public function on_ticket_assigned(object $ticket, ?int $old_agent_id, int $new_agent_id): void
    {
        $this->push_event('ticket.assigned', [
            'ticket_id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'old_agent_id' => $old_agent_id,
            'new_agent_id' => $new_agent_id,
        ]);
    }

    /**
     * Push an event into the buffer.
     *
     * @param  string  $type  Event type (e.g., 'ticket.created').
     * @param  array  $payload  Event data.
     */
    public function push_event(string $type, array $payload): void
    {
        $events = self::get_events_buffer();

        $event = [
            'id' => wp_generate_uuid4(),
            'type' => $type,
            'payload' => $payload,
            'timestamp' => current_time('mysql'),
            'unix_timestamp' => time(),
        ];

        $events[] = $event;

        // Keep only the last MAX_EVENTS entries.
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }

        set_transient(self::TRANSIENT_KEY, $events, self::BUFFER_TTL);
    }

    /**
     * Get events since a given timestamp.
     *
     * @param  int  $since  Unix timestamp to filter events from.
     * @return array Array of events newer than the given timestamp.
     */
    public static function get_events_since(int $since): array
    {
        $events = self::get_events_buffer();

        return array_values(
            array_filter($events, fn (array $event) => $event['unix_timestamp'] > $since)
        );
    }

    /**
     * Get all events in the buffer.
     */
    public static function get_events_buffer(): array
    {
        $events = get_transient(self::TRANSIENT_KEY);

        return is_array($events) ? $events : [];
    }

    /**
     * Clear all events from the buffer.
     */
    public static function clear_events(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Get the list of supported event types.
     */
    public static function event_types(): array
    {
        return [
            'ticket.created',
            'ticket.updated',
            'ticket.statusChanged',
            'reply.created',
            'ticket.assigned',
        ];
    }
}
