<?php

namespace Escalated\Services;

use Escalated\Models\AgentCapacity;

/**
 * Tracks per-agent, per-channel concurrent-ticket load so routing can avoid
 * overloading agents. Mirrors the Laravel CapacityService: a capacity row is
 * created on demand (default ceiling 10, count 0) and the running count is
 * incremented on assignment / decremented on release.
 */
class CapacityService
{
    /**
     * Whether the agent can accept another ticket on the given channel.
     *
     * @param  int|string  $user_id
     * @param  string  $channel
     * @return bool
     */
    public function can_accept_ticket($user_id, $channel = 'default')
    {
        $row = AgentCapacity::find_or_create($user_id, $channel);

        if (! $row) {
            return false;
        }

        return AgentCapacity::has_capacity($row->current_count, $row->max_concurrent);
    }

    /**
     * Increment the agent's running load.
     *
     * @param  int|string  $user_id
     * @param  string  $channel
     * @return void
     */
    public function increment_load($user_id, $channel = 'default')
    {
        AgentCapacity::increment($user_id, $channel);
    }

    /**
     * Decrement the agent's running load (never below zero).
     *
     * @param  int|string  $user_id
     * @param  string  $channel
     * @return void
     */
    public function decrement_load($user_id, $channel = 'default')
    {
        AgentCapacity::decrement($user_id, $channel);
    }

    /**
     * All capacity rows for the admin view.
     *
     * @return array
     */
    public function all_capacities()
    {
        return AgentCapacity::all();
    }
}
