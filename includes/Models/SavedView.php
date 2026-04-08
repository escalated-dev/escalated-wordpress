<?php

namespace Escalated\Models;

use Escalated\Escalated;

class SavedView
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('saved_views');
    }

    /**
     * Find a saved view by ID.
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
     * Create a new saved view.
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
     * Update a saved view.
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
     * Delete a saved view.
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
     * Get views visible to a specific user (own views + shared views).
     *
     * @param  int  $user_id  The user ID.
     * @return array
     */
    public static function for_user($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d OR is_shared = 1 ORDER BY position ASC, name ASC",
                $user_id
            )
        ) ?: [];
    }

    /**
     * Get all saved views.
     *
     * @return array
     */
    public static function all()
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY position ASC, name ASC"
        ) ?: [];
    }

    /**
     * Reorder saved views by updating their positions.
     *
     * @param  array  $ordered_ids  Array of view IDs in desired order.
     */
    public static function reorder(array $ordered_ids): void
    {
        global $wpdb;
        $table = static::table();

        foreach ($ordered_ids as $position => $id) {
            $wpdb->update(
                $table,
                ['position' => $position, 'updated_at' => current_time('mysql')],
                ['id' => (int) $id]
            );
        }
    }

    /**
     * Ensure the saved_views table exists.
     */
    public static function ensure_table(): void
    {
        global $wpdb;
        $table = static::table();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            filters LONGTEXT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            is_shared TINYINT(1) DEFAULT 0,
            position INT UNSIGNED DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        dbDelta($sql);
    }
}
