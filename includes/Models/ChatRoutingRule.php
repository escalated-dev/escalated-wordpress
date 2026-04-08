<?php

namespace Escalated\Models;

use Escalated\Escalated;

class ChatRoutingRule
{
    /**
     * Get the table name.
     */
    public static function table(): string
    {
        return Escalated::table('chat_routing_rules');
    }

    /**
     * Find a routing rule by ID.
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
     * Create a new routing rule.
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

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a routing rule.
     */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $table = static::table();
        $data['updated_at'] = current_time('mysql');

        return (bool) $wpdb->update($table, $data, ['id' => $id]);
    }

    /**
     * Delete a routing rule.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $table = static::table();

        return (bool) $wpdb->delete($table, ['id' => $id]);
    }

    /**
     * Get all active rules ordered by priority.
     */
    public static function get_active(): array
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY priority ASC"
        );
    }
}
