<?php

namespace Escalated\Models;

use Escalated\Escalated;

class Department {

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table() {
        return Escalated::table('departments');
    }

    /**
     * Get the pivot table name for department-agent relationships.
     *
     * @return string
     */
    public static function pivot_table() {
        return Escalated::table('department_agent');
    }

    /**
     * Find a department by ID.
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
     * Find a department by slug.
     *
     * @param string $slug
     * @return object|null
     */
    public static function find_by_slug($slug) {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug)
        );
    }

    /**
     * Create a new department.
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
     * Update a department.
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
     * Delete a department.
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
     * Get all departments with optional filters.
     *
     * @param array $filters
     * @return array
     */
    public static function all(array $filters = []) {
        global $wpdb;
        $table  = static::table();
        $where  = ['1=1'];
        $values = [];

        if ( isset($filters['is_active'])) {
            $where[]  = 'is_active = %d';
            $values[] = (int) $filters['is_active'];
        }

        $where_clause = implode(' AND ', $where);
        $sql          = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY name ASC";

        if ( ! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get all active departments.
     *
     * @return array
     */
    public static function active() {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY name ASC"
        ) ?: [];
    }

    /**
     * Get all agent user IDs for a department.
     *
     * @param int $department_id
     * @return array Array of user IDs.
     */
    public static function agents($department_id) {
        global $wpdb;
        $pivot = static::pivot_table();

        $results = $wpdb->get_col(
            $wpdb->prepare("SELECT user_id FROM {$pivot} WHERE department_id = %d", $department_id)
        );

        return $results ?: [];
    }

    /**
     * Add an agent to a department.
     *
     * @param int $department_id
     * @param int $user_id
     * @return bool
     */
    public static function add_agent($department_id, $user_id) {
        global $wpdb;
        $pivot = static::pivot_table();

        $result = $wpdb->insert($pivot, [
            'department_id' => $department_id,
            'user_id'       => $user_id,
        ]);

        return $result !== false;
    }

    /**
     * Remove an agent from a department.
     *
     * @param int $department_id
     * @param int $user_id
     * @return bool
     */
    public static function remove_agent($department_id, $user_id) {
        global $wpdb;
        $pivot = static::pivot_table();

        return $wpdb->delete($pivot, [
            'department_id' => $department_id,
            'user_id'       => $user_id,
        ]) !== false;
    }
}
