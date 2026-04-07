<?php

namespace Escalated\Models;

use Escalated\Escalated;

class SlaPolicy
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('sla_policies');
    }

    /**
     * Find an SLA policy by ID.
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
     * Create a new SLA policy.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        // Encode JSON fields if passed as arrays.
        if (isset($data['first_response_hours']) && is_array($data['first_response_hours'])) {
            $data['first_response_hours'] = wp_json_encode($data['first_response_hours']);
        }
        if (isset($data['resolution_hours']) && is_array($data['resolution_hours'])) {
            $data['resolution_hours'] = wp_json_encode($data['resolution_hours']);
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update an SLA policy.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        // Encode JSON fields if passed as arrays.
        if (isset($data['first_response_hours']) && is_array($data['first_response_hours'])) {
            $data['first_response_hours'] = wp_json_encode($data['first_response_hours']);
        }
        if (isset($data['resolution_hours']) && is_array($data['resolution_hours'])) {
            $data['resolution_hours'] = wp_json_encode($data['resolution_hours']);
        }

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Delete an SLA policy.
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
     * Get all SLA policies with optional filters.
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
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY name ASC";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get all active SLA policies.
     *
     * @return array
     */
    public static function active()
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY name ASC"
        ) ?: [];
    }

    /**
     * Get the default SLA policy.
     *
     * @return object|null
     */
    public static function get_default()
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            "SELECT * FROM {$table} WHERE is_default = 1 AND is_active = 1 LIMIT 1"
        );
    }

    /**
     * Get the first response hours for a given priority from a policy.
     *
     * @param  object  $policy  The SLA policy record.
     * @param  string  $priority  The priority key (e.g. low, medium, high, urgent, critical).
     * @return int|null Hours or null if not found.
     */
    public static function get_first_response_hours($policy, $priority)
    {
        $hours = json_decode($policy->first_response_hours, true);

        if (! is_array($hours) || ! isset($hours[$priority])) {
            return null;
        }

        return (int) $hours[$priority];
    }

    /**
     * Get the resolution hours for a given priority from a policy.
     *
     * @param  object  $policy  The SLA policy record.
     * @param  string  $priority  The priority key (e.g. low, medium, high, urgent, critical).
     * @return int|null Hours or null if not found.
     */
    public static function get_resolution_hours($policy, $priority)
    {
        $hours = json_decode($policy->resolution_hours, true);

        if (! is_array($hours) || ! isset($hours[$priority])) {
            return null;
        }

        return (int) $hours[$priority];
    }
}
