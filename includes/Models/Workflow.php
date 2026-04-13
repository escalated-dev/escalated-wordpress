<?php

namespace Escalated\Models;

use Escalated\Escalated;

class Workflow
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('workflows');
    }

    /**
     * Find a workflow by ID.
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
     * Get all workflows ordered by position.
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
     * Format a workflow for API/Inertia response with `trigger` alias.
     *
     * @param  object  $workflow
     * @return array
     */
    public static function format($workflow)
    {
        $conditions = $workflow->conditions;
        if (is_string($conditions)) {
            $decoded = json_decode($conditions, true);
            $conditions = is_array($decoded) ? $decoded : [];
        }

        $actions = $workflow->actions;
        if (is_string($actions)) {
            $decoded = json_decode($actions, true);
            $actions = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (int) $workflow->id,
            'name' => $workflow->name,
            'trigger_event' => $workflow->trigger_event,
            'trigger' => $workflow->trigger_event,
            'conditions' => $conditions,
            'actions' => $actions,
            'is_active' => (bool) $workflow->is_active,
            'position' => (int) $workflow->position,
            'created_at' => $workflow->created_at,
            'updated_at' => $workflow->updated_at,
        ];
    }
}
