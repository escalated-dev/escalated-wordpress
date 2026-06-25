<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * A side conversation — a private thread (internal note or outbound email)
 * attached to a ticket, used by agents to consult colleagues or third
 * parties without exposing the main customer thread. Mirrors the Laravel
 * SideConversation model.
 */
class SideConversation
{
    const CHANNEL_INTERNAL = 'internal';

    const CHANNEL_EMAIL = 'email';

    const STATUS_OPEN = 'open';

    const STATUS_CLOSED = 'closed';

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('side_conversations');
    }

    // ---------------------------------------------------------------------
    // Pure helpers (no database)
    // ---------------------------------------------------------------------

    /**
     * Whether a channel value is one of the accepted values.
     *
     * @param  string  $channel
     * @return bool
     */
    public static function valid_channel($channel)
    {
        return in_array($channel, [self::CHANNEL_INTERNAL, self::CHANNEL_EMAIL], true);
    }

    // ---------------------------------------------------------------------
    // Database access
    // ---------------------------------------------------------------------

    /**
     * Find a side conversation by ID.
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
     * Create a new side conversation.
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
     * Update a side conversation.
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
     * All side conversations for a ticket, newest first.
     *
     * @param  int  $ticket_id
     * @return array
     */
    public static function for_ticket($ticket_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY created_at DESC, id DESC",
                $ticket_id
            )
        ) ?: [];
    }
}
