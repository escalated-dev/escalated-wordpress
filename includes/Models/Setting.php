<?php

namespace Escalated\Models;

use Escalated\Escalated;

class Setting {

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table() {
        return Escalated::table('settings');
    }

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param mixed  $default Default value if key does not exist.
     * @return mixed
     */
    public static function get($key, $default = null) {
        global $wpdb;
        $table = static::table();

        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$table} WHERE option_key = %s", $key)
        );

        return $value !== null ? $value : $default;
    }

    /**
     * Set a setting value (insert or update).
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public static function set($key, $value) {
        global $wpdb;
        $table = static::table();
        $now = current_time( 'mysql' );

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (option_key, option_value, created_at, updated_at) VALUES (%s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), updated_at = VALUES(updated_at)",
            $key,
            $value,
            $now,
            $now
        );

        return $wpdb->query($sql) !== false;
    }

    /**
     * Get a setting value as a boolean.
     *
     * @param string $key
     * @param bool   $default
     * @return bool
     */
    public static function get_bool($key, $default = false) {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get a setting value as an integer.
     *
     * @param string $key
     * @param int    $default
     * @return int
     */
    public static function get_int($key, $default = 0) {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Delete a setting by key.
     *
     * @param string $key
     * @return bool
     */
    public static function delete($key) {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['option_key' => $key]) !== false;
    }

    /**
     * Get all settings as a key => value associative array.
     *
     * @return array
     */
    public static function all() {
        global $wpdb;
        $table = static::table();

        $rows = $wpdb->get_results("SELECT option_key, option_value FROM {$table}");

        $settings = [];
        if ($rows) {
            foreach ($rows as $row) {
                $settings[$row->option_key] = $row->option_value;
            }
        }

        return $settings;
    }
}
