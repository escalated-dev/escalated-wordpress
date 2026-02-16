<?php

namespace Escalated\Models;

use Escalated\Escalated;

class InboundEmail {

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table() {
        return Escalated::table('inbound_emails');
    }

    /**
     * Find an inbound email by ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function find($id) {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * Create a new inbound email record.
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
     * Update an inbound email record.
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
     * Delete an inbound email record.
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
     * Get all inbound emails with optional filters.
     *
     * @param array $filters
     * @return array
     */
    public static function all(array $filters = []) {
        global $wpdb;
        $table  = static::table();
        $where  = ['1=1'];
        $values = [];

        if ( ! empty($filters['status'])) {
            $where[]  = 'status = %s';
            $values[] = $filters['status'];
        }

        $where_clause = implode(' AND ', $where);
        $sql          = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC";

        if ( ! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Mark an inbound email as processed.
     *
     * @param int      $id
     * @param int|null $ticket_id Associated ticket ID.
     * @param int|null $reply_id  Associated reply ID.
     * @return bool
     */
    public static function mark_processed($id, $ticket_id = null, $reply_id = null) {
        global $wpdb;
        $table = static::table();
        $now   = current_time('mysql');

        $data = [
            'status'       => 'processed',
            'processed_at' => $now,
            'updated_at'   => $now,
        ];

        if ($ticket_id !== null) {
            $data['ticket_id'] = (int) $ticket_id;
        }

        if ($reply_id !== null) {
            $data['reply_id'] = (int) $reply_id;
        }

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Mark an inbound email as failed.
     *
     * @param int    $id
     * @param string $error_message
     * @return bool
     */
    public static function mark_failed($id, $error_message) {
        global $wpdb;
        $table = static::table();
        $now   = current_time('mysql');

        return $wpdb->update(
            $table,
            [
                'status'        => 'failed',
                'error_message' => $error_message,
                'processed_at'  => $now,
                'updated_at'    => $now,
            ],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Check if a message ID already exists as processed (duplicate detection).
     *
     * @param string   $message_id The email message ID.
     * @param int|null $exclude_id Record ID to exclude from the check.
     * @return bool
     */
    public static function is_duplicate($message_id, $exclude_id = null) {
        global $wpdb;
        $table = static::table();

        $sql    = "SELECT COUNT(*) FROM {$table} WHERE message_id = %s AND status = 'processed'";
        $values = [$message_id];

        if ($exclude_id !== null) {
            $sql      .= ' AND id != %d';
            $values[] = (int) $exclude_id;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $values)) > 0;
    }
}
