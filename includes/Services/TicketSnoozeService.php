<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;

class TicketSnoozeService
{
    /**
     * Snooze a ticket until a given datetime.
     *
     * Stores the current status before snoozing, changes the ticket status
     * to 'snoozed', and records snoozed_until and snoozed_by in the ticket meta table.
     *
     * @param  int  $ticket_id  The ticket ID to snooze.
     * @param  string  $until  DateTime string (Y-m-d H:i:s) to snooze until.
     * @param  int  $snoozed_by  User ID performing the snooze.
     * @return object The updated ticket.
     *
     * @throws \InvalidArgumentException If the ticket is not found or already snoozed.
     */
    public function snooze_ticket(int $ticket_id, string $until, int $snoozed_by): object
    {
        $ticket = Ticket::find($ticket_id);
        if (! $ticket) {
            throw new \InvalidArgumentException('Ticket not found.');
        }

        if ($this->is_snoozed($ticket_id)) {
            throw new \InvalidArgumentException('Ticket is already snoozed.');
        }

        // Validate the snooze datetime is in the future.
        if (strtotime($until) <= time()) {
            throw new \InvalidArgumentException('Snooze time must be in the future.');
        }

        // Store snooze metadata.
        $this->set_meta($ticket_id, 'snoozed_until', $until);
        $this->set_meta($ticket_id, 'snoozed_by', (string) $snoozed_by);
        $this->set_meta($ticket_id, 'status_before_snooze', $ticket->status);

        // Update the ticket status to snoozed.
        Ticket::update($ticket_id, ['status' => 'snoozed']);

        TicketActivity::create([
            'ticket_id' => $ticket_id,
            'causer_id' => $snoozed_by,
            'type' => 'snoozed',
            'properties' => wp_json_encode([
                'snoozed_until' => $until,
                'previous_status' => $ticket->status,
            ]),
        ]);

        $updated = Ticket::find($ticket_id);
        do_action('escalated_ticket_snoozed', $updated, $until);

        return $updated;
    }

    /**
     * Unsnooze a ticket, restoring its previous status.
     *
     * @param  int  $ticket_id  The ticket ID to unsnooze.
     * @param  int|null  $causer_id  User who triggered the unsnooze (null for cron).
     * @return object The updated ticket.
     *
     * @throws \InvalidArgumentException If the ticket is not found or not snoozed.
     */
    public function unsnooze_ticket(int $ticket_id, ?int $causer_id = null): object
    {
        $ticket = Ticket::find($ticket_id);
        if (! $ticket) {
            throw new \InvalidArgumentException('Ticket not found.');
        }

        if (! $this->is_snoozed($ticket_id)) {
            throw new \InvalidArgumentException('Ticket is not snoozed.');
        }

        $previous_status = $this->get_meta($ticket_id, 'status_before_snooze') ?: 'open';

        // Restore the previous status.
        Ticket::update($ticket_id, ['status' => $previous_status]);

        // Clean up snooze metadata.
        $this->delete_meta($ticket_id, 'snoozed_until');
        $this->delete_meta($ticket_id, 'snoozed_by');
        $this->delete_meta($ticket_id, 'status_before_snooze');

        TicketActivity::create([
            'ticket_id' => $ticket_id,
            'causer_id' => $causer_id,
            'type' => 'unsnoozed',
            'properties' => wp_json_encode([
                'restored_status' => $previous_status,
            ]),
        ]);

        $updated = Ticket::find($ticket_id);
        do_action('escalated_ticket_unsnoozed', $updated);

        return $updated;
    }

    /**
     * Check if a ticket is currently snoozed.
     *
     * @param  int  $ticket_id  The ticket ID.
     */
    public function is_snoozed(int $ticket_id): bool
    {
        $until = $this->get_meta($ticket_id, 'snoozed_until');

        return ! empty($until);
    }

    /**
     * Get the snooze wake time for a ticket.
     *
     * @param  int  $ticket_id  The ticket ID.
     * @return string|null The datetime string, or null if not snoozed.
     */
    public function get_snooze_until(int $ticket_id): ?string
    {
        return $this->get_meta($ticket_id, 'snoozed_until');
    }

    /**
     * Wake all snoozed tickets whose snooze time has passed.
     *
     * Called by WP-Cron every minute.
     */
    public function wake_snoozed_tickets(): void
    {
        global $wpdb;
        $meta_table = $this->ensure_meta_table();
        $now = current_time('mysql');

        // Find all tickets with snoozed_until in the past.
        $snoozed = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ticket_id FROM {$meta_table} WHERE meta_key = 'snoozed_until' AND meta_value <= %s",
                $now
            )
        );

        foreach ($snoozed as $row) {
            try {
                $this->unsnooze_ticket((int) $row->ticket_id);
            } catch (\Throwable $e) {
                // Skip tickets that can't be unsnoozed.
            }
        }
    }

    /**
     * Get all currently snoozed ticket IDs (for exclusion from default queries).
     *
     * @return array Array of ticket IDs.
     */
    public static function get_snoozed_ticket_ids(): array
    {
        global $wpdb;
        $table = Escalated::table('ticket_meta');

        // Check if table exists first.
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );
        if (! $table_exists) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT ticket_id FROM {$table} WHERE meta_key = 'snoozed_until'"
        );

        return array_map(fn ($row) => (int) $row->ticket_id, $rows ?: []);
    }

    /**
     * Set a ticket meta value.
     */
    private function set_meta(int $ticket_id, string $key, string $value): void
    {
        global $wpdb;
        $table = $this->ensure_meta_table();
        $now = current_time('mysql');

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE ticket_id = %d AND meta_key = %s",
                $ticket_id,
                $key
            )
        );

        if ($exists) {
            $wpdb->update(
                $table,
                ['meta_value' => $value, 'updated_at' => $now],
                ['ticket_id' => $ticket_id, 'meta_key' => $key]
            );
        } else {
            $wpdb->insert($table, [
                'ticket_id' => $ticket_id,
                'meta_key' => $key,
                'meta_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Get a ticket meta value.
     */
    private function get_meta(int $ticket_id, string $key): ?string
    {
        global $wpdb;
        $table = $this->ensure_meta_table();

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE ticket_id = %d AND meta_key = %s",
                $ticket_id,
                $key
            )
        );
    }

    /**
     * Delete a ticket meta entry.
     */
    private function delete_meta(int $ticket_id, string $key): void
    {
        global $wpdb;
        $table = $this->ensure_meta_table();

        $wpdb->delete($table, [
            'ticket_id' => $ticket_id,
            'meta_key' => $key,
        ]);
    }

    /**
     * Ensure the ticket_meta table exists and return its name.
     */
    private function ensure_meta_table(): string
    {
        global $wpdb;
        $table = Escalated::table('ticket_meta');
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY meta_key (meta_key)
        ) $charset_collate;";

        dbDelta($sql);

        return $table;
    }
}
