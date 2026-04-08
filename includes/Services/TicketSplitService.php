<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;

class TicketSplitService
{
    /**
     * Split a ticket by creating a new ticket from a reply's content.
     *
     * Copies metadata (requester, tags, priority, department) from the source ticket,
     * links the two tickets, and logs activity on both.
     *
     * @param  int  $reply_id  The reply to split into a new ticket.
     * @param  int|null  $causer_id  The user performing the split.
     * @return object The newly created ticket.
     *
     * @throws \InvalidArgumentException If the reply or source ticket is not found.
     */
    public function split_ticket(int $reply_id, ?int $causer_id = null): object
    {
        $reply = Reply::find($reply_id);
        if (! $reply) {
            throw new \InvalidArgumentException('Reply not found.');
        }

        $source_ticket = Ticket::find((int) $reply->ticket_id);
        if (! $source_ticket) {
            throw new \InvalidArgumentException('Source ticket not found.');
        }

        // Create a new ticket from the reply content.
        $reference = Ticket::generate_reference();
        $now = current_time('mysql');

        $new_ticket_data = [
            'reference' => $reference,
            'requester_id' => $source_ticket->requester_id,
            'subject' => sprintf('Split from %s: %s', $source_ticket->reference, $source_ticket->subject),
            'description' => $reply->body,
            'status' => 'open',
            'priority' => $source_ticket->priority,
            'ticket_type' => $source_ticket->ticket_type ?? 'question',
            'channel' => $source_ticket->channel ?? 'web',
            'department_id' => $source_ticket->department_id,
            'metadata' => $source_ticket->metadata,
            'guest_name' => $source_ticket->guest_name,
            'guest_email' => $source_ticket->guest_email,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $new_ticket_id = Ticket::create($new_ticket_data);
        $new_ticket = Ticket::find($new_ticket_id);

        // Copy tags from source ticket.
        $tags = Tag::for_ticket((int) $source_ticket->id);
        foreach ($tags as $tag) {
            Tag::attach($new_ticket_id, (int) $tag->id);
        }

        // Link the tickets via metadata.
        $this->link_tickets((int) $source_ticket->id, $new_ticket_id, $source_ticket->reference, $new_ticket->reference);

        // Log activity on source ticket.
        TicketActivity::create([
            'ticket_id' => (int) $source_ticket->id,
            'causer_id' => $causer_id,
            'type' => 'ticket_split',
            'properties' => wp_json_encode([
                'action' => 'split_to',
                'new_ticket_id' => $new_ticket_id,
                'new_ticket_reference' => $new_ticket->reference,
                'message' => sprintf('Split to #%s', $new_ticket->reference),
            ]),
        ]);

        // Log activity on new ticket.
        TicketActivity::create([
            'ticket_id' => $new_ticket_id,
            'causer_id' => $causer_id,
            'type' => 'ticket_split',
            'properties' => wp_json_encode([
                'action' => 'split_from',
                'source_ticket_id' => (int) $source_ticket->id,
                'source_ticket_reference' => $source_ticket->reference,
                'message' => sprintf('Split from #%s', $source_ticket->reference),
            ]),
        ]);

        do_action('escalated_ticket_split', $new_ticket, $source_ticket, $reply);

        return $new_ticket;
    }

    /**
     * Link two tickets by storing references in their metadata.
     *
     * @param  int  $source_id  Source ticket ID.
     * @param  int  $new_id  New ticket ID.
     * @param  string  $source_ref  Source ticket reference.
     * @param  string  $new_ref  New ticket reference.
     */
    private function link_tickets(int $source_id, int $new_id, string $source_ref, string $new_ref): void
    {
        global $wpdb;
        $table = Escalated::table('ticket_links');

        // Create the links table if it doesn't exist.
        $this->ensure_links_table();

        $now = current_time('mysql');

        $wpdb->insert($table, [
            'ticket_id' => $source_id,
            'linked_ticket_id' => $new_id,
            'link_type' => 'split',
            'created_at' => $now,
        ]);

        $wpdb->insert($table, [
            'ticket_id' => $new_id,
            'linked_ticket_id' => $source_id,
            'link_type' => 'split',
            'created_at' => $now,
        ]);
    }

    /**
     * Get linked tickets for a given ticket.
     *
     * @param  int  $ticket_id  The ticket ID.
     * @return array Array of linked ticket objects.
     */
    public static function get_linked_tickets(int $ticket_id): array
    {
        global $wpdb;
        $links_table = Escalated::table('ticket_links');
        $tickets_table = Escalated::table('tickets');

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, tl.link_type FROM {$links_table} AS tl
                 INNER JOIN {$tickets_table} AS t ON t.id = tl.linked_ticket_id
                 WHERE tl.ticket_id = %d AND t.deleted_at IS NULL
                 ORDER BY tl.created_at DESC",
                $ticket_id
            )
        ) ?: [];
    }

    /**
     * Ensure the ticket_links table exists.
     */
    private function ensure_links_table(): void
    {
        global $wpdb;
        $table = Escalated::table('ticket_links');
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            linked_ticket_id BIGINT UNSIGNED NOT NULL,
            link_type VARCHAR(50) DEFAULT 'split',
            created_at DATETIME,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY linked_ticket_id (linked_ticket_id)
        ) $charset_collate;";

        dbDelta($sql);
    }
}
