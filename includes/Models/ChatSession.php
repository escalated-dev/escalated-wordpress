<?php

namespace Escalated\Models;

use Escalated\Escalated;

class ChatSession
{
    /**
     * Get the table name.
     */
    public static function table(): string
    {
        return Escalated::table('chat_sessions');
    }

    /**
     * Find a chat session by ID.
     */
    public static function find(int $id): ?object
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * Find a chat session by ticket ID.
     */
    public static function find_by_ticket_id(int $ticket_id): ?object
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE ticket_id = %d", $ticket_id)
        );
    }

    /**
     * Create a new chat session.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $data['last_activity_at'] = $now;

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a chat session.
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $table = static::table();
        $data['updated_at'] = current_time('mysql');

        return (bool) $wpdb->update($table, $data, ['id' => $id]);
    }

    /**
     * Get all waiting sessions ordered by creation time.
     */
    public static function get_waiting(): array
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'waiting' ORDER BY created_at ASC"
        );
    }

    /**
     * Get active sessions for a specific agent.
     */
    public static function get_active_for_agent(int $agent_id): array
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE agent_id = %d AND status = 'active' ORDER BY last_activity_at DESC",
                $agent_id
            )
        );
    }

    /**
     * Get idle sessions (no activity within cutoff).
     */
    public static function get_idle(string $cutoff): array
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status IN ('waiting', 'active') AND last_activity_at <= %s",
                $cutoff
            )
        );
    }

    /**
     * Count waiting sessions, optionally filtered by department.
     */
    public static function count_waiting(?int $department_id = null): int
    {
        global $wpdb;
        $table = static::table();

        if ($department_id) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = 'waiting' AND department_id = %d",
                    $department_id
                )
            );
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'waiting'");
    }
}
