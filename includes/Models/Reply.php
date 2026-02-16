<?php

namespace Escalated\Models;

use Escalated\Escalated;

class Reply {

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table() {
        return Escalated::table('replies');
    }

    /**
     * Find a reply by ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function find($id) {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL", $id)
        );
    }

    /**
     * Create a new reply.
     *
     * @param array $data
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data) {
        global $wpdb;
        $table = static::table();
        $now   = current_time('mysql');

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update a reply.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public static function update($id, array $data) {
        global $wpdb;
        $table = static::table();

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Hard delete a reply.
     *
     * @param int $id
     * @return bool
     */
    public static function delete($id) {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['id' => $id]) !== false;
    }

    /**
     * Soft delete a reply (set deleted_at).
     *
     * @param int $id
     * @return bool
     */
    public static function soft_delete($id) {
        global $wpdb;
        $table = static::table();

        return $wpdb->update(
            $table,
            ['deleted_at' => current_time('mysql')],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Get all replies for a ticket.
     *
     * @param int  $ticket_id
     * @param bool $include_internal Whether to include internal notes.
     * @return array
     */
    public static function for_ticket($ticket_id, $include_internal = true) {
        global $wpdb;
        $table = static::table();

        $sql    = "SELECT * FROM {$table} WHERE ticket_id = %d AND deleted_at IS NULL";
        $values = [$ticket_id];

        if ( ! $include_internal) {
            $sql .= ' AND is_internal = 0';
        }

        $sql .= ' ORDER BY created_at ASC';

        return $wpdb->get_results($wpdb->prepare($sql, $values)) ?: [];
    }

    /**
     * Get all replies with optional filters.
     *
     * @param array $filters
     * @return array
     */
    public static function all(array $filters = []) {
        global $wpdb;
        $table  = static::table();
        $where  = ['deleted_at IS NULL'];
        $values = [];

        if ( ! empty($filters['ticket_id'])) {
            $where[]  = 'ticket_id = %d';
            $values[] = (int) $filters['ticket_id'];
        }

        $where_clause = implode(' AND ', $where);
        $sql          = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at ASC";

        if ( ! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }
}
