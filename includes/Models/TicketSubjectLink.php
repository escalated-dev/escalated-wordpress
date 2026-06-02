<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * Join row linking a ticket to one host-app subject (type + string id).
 */
class TicketSubjectLink
{
    public static function table(): string
    {
        return Escalated::table('ticket_subjects');
    }

    /**
     * @return object|null
     */
    public static function find(int $id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * @return object[]
     */
    public static function for_ticket(int $ticket_id): array
    {
        global $wpdb;
        $table = static::table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY position ASC, id ASC",
                $ticket_id
            )
        );

        return $rows ?: [];
    }

    public static function max_position(int $ticket_id): int
    {
        global $wpdb;
        $table = static::table();

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT MAX(position) FROM {$table} WHERE ticket_id = %d", $ticket_id)
        );
    }

    /**
     * Idempotent upsert on (ticket_id, subject_type, subject_id).
     *
     * @return object|null The link row after write.
     */
    public static function upsert(
        int $ticket_id,
        string $subject_type,
        string $subject_id,
        ?string $role = null,
        ?int $position = null
    ) {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE ticket_id = %d AND subject_type = %s AND subject_id = %s",
                $ticket_id,
                $subject_type,
                $subject_id
            )
        );

        if ($position === null) {
            $position = static::max_position($ticket_id) + 1;
        }

        $data = [
            'role' => $role,
            'position' => $position,
            'updated_at' => $now,
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int) $existing->id]);

            return static::find((int) $existing->id);
        }

        $data['ticket_id'] = $ticket_id;
        $data['subject_type'] = $subject_type;
        $data['subject_id'] = $subject_id;
        $data['created_at'] = $now;

        $inserted = $wpdb->insert($table, $data);

        if ($inserted === false) {
            return null;
        }

        return static::find((int) $wpdb->insert_id);
    }

    /**
     * @return int Rows deleted (0 or 1).
     */
    public static function detach(int $ticket_id, string $subject_type, string $subject_id): int
    {
        global $wpdb;
        $table = static::table();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE ticket_id = %d AND subject_type = %s AND subject_id = %s",
                $ticket_id,
                $subject_type,
                $subject_id
            )
        );
    }

    public static function delete_for_ticket(int $ticket_id): void
    {
        global $wpdb;
        $table = static::table();

        $wpdb->delete($table, ['ticket_id' => $ticket_id]);
    }

    /**
     * @return int Rows deleted (0 or 1).
     */
    public static function delete_by_id(int $id, int $ticket_id): int
    {
        global $wpdb;
        $table = static::table();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id = %d AND ticket_id = %d",
                $id,
                $ticket_id
            )
        );
    }
}
