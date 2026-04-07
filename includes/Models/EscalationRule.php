<?php

namespace Escalated\Models;

use Escalated\Escalated;

class EscalationRule
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('escalation_rules');
    }

    /**
     * Find an escalation rule by ID.
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
     * Create a new escalation rule.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        // Encode JSON fields if passed as arrays.
        if (isset($data['conditions']) && is_array($data['conditions'])) {
            $data['conditions'] = wp_json_encode($data['conditions']);
        }
        if (isset($data['actions']) && is_array($data['actions'])) {
            $data['actions'] = wp_json_encode($data['actions']);
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update an escalation rule.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        // Encode JSON fields if passed as arrays.
        if (isset($data['conditions']) && is_array($data['conditions'])) {
            $data['conditions'] = wp_json_encode($data['conditions']);
        }
        if (isset($data['actions']) && is_array($data['actions'])) {
            $data['actions'] = wp_json_encode($data['actions']);
        }

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Delete an escalation rule.
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
     * Get all escalation rules with optional filters.
     *
     * @return array
     */
    public static function all(array $filters = [])
    {
        global $wpdb;
        $table = static::table();
        $where = ['1=1'];
        $values = [];

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = %d';
            $values[] = (int) $filters['is_active'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY sort_order ASC";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get all active escalation rules ordered by priority.
     *
     * @return array
     */
    public static function active()
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC"
        ) ?: [];
    }
}
