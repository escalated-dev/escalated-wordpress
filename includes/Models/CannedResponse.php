<?php

namespace Escalated\Models;

use Escalated\Escalated;

class CannedResponse {

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table() {
        return Escalated::table('canned_responses');
    }

    /**
     * Find a canned response by ID.
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
     * Create a new canned response.
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
     * Update a canned response.
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
     * Delete a canned response.
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
     * Get all canned responses with optional filters.
     *
     * @param array $filters
     * @return array
     */
    public static function all(array $filters = []) {
        global $wpdb;
        $table  = static::table();
        $where  = ['1=1'];
        $values = [];

        if ( ! empty($filters['search'])) {
            $like     = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where[]  = '(title LIKE %s OR content LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        $where_clause = implode(' AND ', $where);
        $sql          = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY title ASC";

        if ( ! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get canned responses available to a specific agent.
     *
     * Returns responses that are shared or created by the given user.
     *
     * @param int $user_id
     * @return array
     */
    public static function for_agent($user_id) {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE is_shared = 1 OR created_by = %d ORDER BY title ASC",
                $user_id
            )
        ) ?: [];
    }
}
