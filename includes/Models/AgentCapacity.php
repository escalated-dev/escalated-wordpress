<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * Per-agent, per-channel concurrent-ticket capacity.
 *
 * Tracks how many open tickets an agent is carrying (current_count) against
 * their configured ceiling (max_concurrent) so routing can avoid overloading.
 * Mirrors the Laravel AgentCapacity model.
 */
class AgentCapacity
{
    /**
     * Default concurrent-ticket ceiling for a freshly created row.
     */
    const DEFAULT_MAX_CONCURRENT = 10;

    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('agent_capacity');
    }

    // ---------------------------------------------------------------------
    // Pure helpers (no database)
    // ---------------------------------------------------------------------

    /**
     * Whether an agent at the given load has headroom for another ticket.
     *
     * @param  int  $current_count
     * @param  int  $max_concurrent
     * @return bool
     */
    public static function has_capacity($current_count, $max_concurrent)
    {
        return (int) $current_count < (int) $max_concurrent;
    }

    /**
     * Current load as a percentage of the ceiling. A zero (or negative)
     * ceiling is treated as fully loaded.
     *
     * @param  int  $current_count
     * @param  int  $max_concurrent
     * @return float
     */
    public static function load_percentage($current_count, $max_concurrent)
    {
        if ((int) $max_concurrent <= 0) {
            return 100.0;
        }

        return round(((int) $current_count / (int) $max_concurrent) * 100, 1);
    }

    // ---------------------------------------------------------------------
    // Database access
    // ---------------------------------------------------------------------

    /**
     * Find the capacity row for a user on a channel.
     *
     * @param  int|string  $user_id
     * @param  string  $channel
     * @return object|null
     */
    public static function for_user($user_id, $channel = 'default')
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND channel = %s",
                $user_id,
                $channel
            )
        );
    }

    /**
     * Find the capacity row for a user/channel, creating it with defaults
     * (ceiling 10, count 0) if it does not yet exist.
     *
     * @param  int|string  $user_id
     * @param  string  $channel
     * @return object|null
     */
    public static function find_or_create($user_id, $channel = 'default')
    {
        $existing = static::for_user($user_id, $channel);
        if ($existing) {
            return $existing;
        }

        static::create([
            'user_id' => $user_id,
            'channel' => $channel,
            'max_concurrent' => self::DEFAULT_MAX_CONCURRENT,
            'current_count' => 0,
        ]);

        return static::for_user($user_id, $channel);
    }

    /**
     * Create a new capacity row.
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
     * Update a capacity row.
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
     * Increment the running load for a user/channel.
     *
     * @param  int|string  $user_id
     * @param  string  $channel
     * @return void
     */
    public static function increment($user_id, $channel = 'default')
    {
        $row = static::find_or_create($user_id, $channel);
        if ($row) {
            static::update($row->id, ['current_count' => (int) $row->current_count + 1]);
        }
    }

    /**
     * Decrement the running load for a user/channel (never below zero).
     *
     * @param  int|string  $user_id
     * @param  string  $channel
     * @return void
     */
    public static function decrement($user_id, $channel = 'default')
    {
        $row = static::find_or_create($user_id, $channel);
        if ($row && (int) $row->current_count > 0) {
            static::update($row->id, ['current_count' => (int) $row->current_count - 1]);
        }
    }

    /**
     * Get all capacity rows, ordered by agent then channel.
     *
     * @return array
     */
    public static function all()
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY user_id ASC, channel ASC"
        ) ?: [];
    }
}
