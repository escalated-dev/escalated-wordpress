<?php

namespace Escalated\Models;

use Escalated\Escalated;

class Macro
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('macros');
    }

    /**
     * Find a macro by ID.
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
     * Create a new macro.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        // Encode actions if passed as an array.
        if (isset($data['actions']) && is_array($data['actions'])) {
            $data['actions'] = wp_json_encode($data['actions']);
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update a macro.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        // Encode actions if passed as an array.
        if (isset($data['actions']) && is_array($data['actions'])) {
            $data['actions'] = wp_json_encode($data['actions']);
        }

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Delete a macro.
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
     * Get all macros with optional filters.
     *
     * @return array
     */
    public static function all(array $filters = [])
    {
        global $wpdb;
        $table = static::table();
        $where = ['1=1'];
        $values = [];

        if (! empty($filters['search'])) {
            $like = '%'.$wpdb->esc_like($filters['search']).'%';
            $where[] = 'name LIKE %s';
            $values[] = $like;
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY sort_order ASC, name ASC";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get macros available to a specific agent.
     *
     * Returns macros that are shared or created by the given user, ordered by priority.
     *
     * @param  int  $user_id
     * @return array
     */
    public static function for_agent($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE is_shared = 1 OR created_by = %d ORDER BY sort_order ASC, name ASC",
                $user_id
            )
        ) ?: [];
    }
}
