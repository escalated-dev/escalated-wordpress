<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Reply;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;

/**
 * Merge one ticket into another, after escalated-laravel's
 * TicketMergeService.
 *
 * In one database transaction:
 *   - every reply on the source moves to the target, and its attachments
 *     go with it
 *   - a system internal note goes on both tickets
 *   - the source is closed with merged_into_id pointing at the target
 *
 * After commit it fires the usual status hooks for the source, so
 * notifications, webhooks and workflows see the close, plus
 * `escalated_ticket_merged`.
 */
class TicketMergeService
{
    /**
     * @return object The target ticket, reloaded.
     *
     * @throws \InvalidArgumentException When the merge is not allowed.
     */
    public function merge(object $source, object $target, ?int $merged_by = null): object
    {
        if ((int) $source->id === (int) $target->id) {
            throw new \InvalidArgumentException(__('A ticket cannot be merged into itself.', 'escalated'));
        }
        if (! empty($source->merged_into_id)) {
            throw new \InvalidArgumentException(__('This ticket has already been merged.', 'escalated'));
        }
        if (! empty($target->merged_into_id)) {
            throw new \InvalidArgumentException(__('The target ticket has itself been merged into another ticket.', 'escalated'));
        }

        $wpdb = Escalated::db();
        $replies = Reply::table();
        $now = current_time('mysql');
        $old_status = (string) $source->status;

        $wpdb->query('START TRANSACTION');
        try {
            TicketAccess::without_restriction(static function () use ($wpdb, $replies, $source, $target, $merged_by, $now): void {
                if ($wpdb->query($wpdb->prepare("UPDATE {$replies} SET ticket_id = %d WHERE ticket_id = %d", (int) $target->id, (int) $source->id)) === false) {
                    throw new \RuntimeException('Moving the replies failed.');
                }

                foreach ([
                    [$target, sprintf('Ticket %s was merged into this ticket.', $source->reference), ['merge_source' => $source->reference]],
                    [$source, sprintf('This ticket was merged into %s.', $target->reference), ['merge_target' => $target->reference]],
                ] as [$ticket, $body, $meta]) {
                    $ok = Reply::create([
                        'ticket_id' => (int) $ticket->id,
                        'author_id' => null,
                        'body' => $body,
                        'is_internal_note' => 1,
                        'is_pinned' => 0,
                        'type' => 'note',
                        'metadata' => wp_json_encode(['system_note' => true, 'merged_by' => $merged_by] + $meta),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    if (! $ok) {
                        throw new \RuntimeException('Writing the merge note failed.');
                    }
                }

                if (! Ticket::update((int) $source->id, ['status' => 'closed', 'closed_at' => $now, 'merged_into_id' => (int) $target->id])) {
                    throw new \RuntimeException('Closing the source ticket failed.');
                }

                TicketActivity::create([
                    'ticket_id' => (int) $source->id,
                    'causer_id' => $merged_by,
                    'type' => 'merged',
                    'properties' => wp_json_encode(['merged_into' => $target->reference]),
                ]);
            });
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            throw $e;
        }

        $closed = Ticket::find((int) $source->id);
        $target = Ticket::find((int) $target->id);

        if ($old_status !== 'closed') {
            do_action('escalated_ticket_status_changed', $closed, $old_status, 'closed', $merged_by);
            do_action('escalated_ticket_closed', $closed, $merged_by);
        }

        /**
         * Fires after a ticket is merged into another.
         *
         * @param  object  $closed  The source ticket, now closed.
         * @param  object  $target  The ticket it was merged into.
         * @param  int|null  $merged_by  The user who merged it.
         */
        do_action('escalated_ticket_merged', $closed, $target, $merged_by);

        return $target;
    }

    /**
     * Unmerged tickets whose reference or subject matches, for the merge
     * picker. LIKE wildcards in the query are matched literally.
     *
     * @return array<int, array{id: int, reference: string, subject: string, status: string, created_at: string}>
     */
    public function search(string $query, ?int $exclude_id = null, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $wpdb = Escalated::db();
        $table = Ticket::table();
        $like = '%'.$wpdb->esc_like($query).'%';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, reference, subject, status, created_at FROM {$table}
             WHERE merged_into_id IS NULL AND deleted_at IS NULL AND id <> %d
               AND (reference LIKE %s OR subject LIKE %s)
             ORDER BY id DESC LIMIT %d",
            (int) $exclude_id,
            $like,
            $like,
            $limit
        )) ?: [];

        return array_map(static fn ($row) => [
            'id' => (int) $row->id,
            'reference' => (string) $row->reference,
            'subject' => (string) $row->subject,
            'status' => (string) $row->status,
            'created_at' => (string) $row->created_at,
        ], $rows);
    }
}
