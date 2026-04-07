<?php

namespace Escalated\Models;

use Escalated\Escalated;

class TicketActivity
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('ticket_activities');
    }

    /**
     * Create a new ticket activity log entry.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();

        // Encode properties if passed as an array.
        if (isset($data['properties']) && is_array($data['properties'])) {
            $data['properties'] = wp_json_encode($data['properties']);
        }

        $data['created_at'] = current_time('mysql');

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Get activity log entries for a ticket.
     *
     * @param  int  $ticket_id
     * @param  int  $limit  Maximum number of entries to return.
     * @return array
     */
    public static function for_ticket($ticket_id, $limit = 50)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY created_at DESC LIMIT %d",
                $ticket_id,
                $limit
            )
        ) ?: [];
    }
}
