<?php

namespace Escalated\Models;

use Escalated\Escalated;

class Tag
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('tags');
    }

    /**
     * Get the pivot table name for ticket-tag relationships.
     *
     * @return string
     */
    public static function pivot_table()
    {
        return Escalated::table('ticket_tag');
    }

    /**
     * Find a tag by ID.
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
     * Find a tag by slug.
     *
     * @param  string  $slug
     * @return object|null
     */
    public static function find_by_slug($slug)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug)
        );
    }

    /**
     * Create a new tag.
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
     * Update a tag.
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
     * Delete a tag.
     *
     * @param  int  $id
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        $table = static::table();
        $pivot = static::pivot_table();

        // Remove all pivot entries for this tag.
        $wpdb->delete($pivot, ['tag_id' => $id]);

        return $wpdb->delete($table, ['id' => $id]) !== false;
    }

    /**
     * Get all tags with optional filters.
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
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY name ASC";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get all tags associated with a ticket.
     *
     * @param  int  $ticket_id
     * @return array
     */
    public static function for_ticket($ticket_id)
    {
        global $wpdb;
        $table = static::table();
        $pivot = static::pivot_table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.* FROM {$table} AS t
                 INNER JOIN {$pivot} AS tt ON tt.tag_id = t.id
                 WHERE tt.ticket_id = %d
                 ORDER BY t.name ASC",
                $ticket_id
            )
        ) ?: [];
    }

    /**
     * Attach a tag to a ticket (ignore if already attached).
     *
     * @param  int  $ticket_id
     * @param  int  $tag_id
     * @return bool
     */
    public static function attach($ticket_id, $tag_id)
    {
        global $wpdb;
        $pivot = static::pivot_table();

        // Use INSERT IGNORE to silently skip duplicates.
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$pivot} (ticket_id, tag_id) VALUES (%d, %d)",
            $ticket_id,
            $tag_id
        );

        return $wpdb->query($sql) !== false;
    }

    /**
     * Detach a tag from a ticket.
     *
     * @param  int  $ticket_id
     * @param  int  $tag_id
     * @return bool
     */
    public static function detach($ticket_id, $tag_id)
    {
        global $wpdb;
        $pivot = static::pivot_table();

        return $wpdb->delete($pivot, [
            'ticket_id' => $ticket_id,
            'tag_id' => $tag_id,
        ]) !== false;
    }

    /**
     * Sync tags for a ticket (replace all existing tags).
     *
     * @param  int  $ticket_id
     * @param  array  $tag_ids  Array of tag IDs to set.
     * @return void
     */
    public static function sync($ticket_id, array $tag_ids)
    {
        global $wpdb;
        $pivot = static::pivot_table();

        // Remove all existing tags for this ticket.
        $wpdb->delete($pivot, ['ticket_id' => $ticket_id]);

        // Attach new tags.
        foreach ($tag_ids as $tag_id) {
            $wpdb->insert($pivot, [
                'ticket_id' => (int) $ticket_id,
                'tag_id' => (int) $tag_id,
            ]);
        }
    }
}
