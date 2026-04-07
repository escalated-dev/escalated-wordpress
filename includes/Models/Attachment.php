<?php

namespace Escalated\Models;

use Escalated\Escalated;

class Attachment
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('attachments');
    }

    /**
     * Find an attachment by ID.
     *
     * @param  int  $id
     * @return object|null
     */
    public static function find($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * Create a new attachment.
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

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update an attachment.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Delete an attachment.
     *
     * @param  int  $id
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['id' => $id]) !== false;
    }

    /**
     * Get all attachments with optional filters.
     *
     * @return array
     */
    public static function all(array $filters = [])
    {
        global $wpdb;
        $table = static::table();
        $where = ['1=1'];
        $values = [];

        if (! empty($filters['attachable_type'])) {
            $where[] = 'attachable_type = %s';
            $values[] = $filters['attachable_type'];
        }

        if (! empty($filters['attachable_id'])) {
            $where[] = 'attachable_id = %d';
            $values[] = (int) $filters['attachable_id'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at ASC";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get attachments by attachable type and ID.
     *
     * @param  string  $type  The attachable type (e.g. 'ticket', 'reply').
     * @param  int  $id  The attachable ID.
     * @return array
     */
    public static function for_attachable($type, $id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE attachable_type = %s AND attachable_id = %d ORDER BY created_at ASC",
                $type,
                $id
            )
        ) ?: [];
    }
}
