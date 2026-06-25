<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * A single message within a SideConversation. Mirrors the Laravel
 * SideConversationReply model.
 */
class SideConversationReply
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('side_conversation_replies');
    }

    /**
     * Create a new reply.
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
     * All replies for a conversation, oldest first.
     *
     * @param  int  $side_conversation_id
     * @return array
     */
    public static function for_conversation($side_conversation_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE side_conversation_id = %d ORDER BY created_at ASC, id ASC",
                $side_conversation_id
            )
        ) ?: [];
    }
}
