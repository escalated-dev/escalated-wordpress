<?php

namespace Escalated\Models;

use Escalated\Escalated;
use Escalated\Services\EventVocabulary;

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
        $wpdb = \Escalated\Escalated::db();
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
        $wpdb = \Escalated\Escalated::db();
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY position ASC, name ASC"
        ) ?: [];
    }

    /**
     * Insert a workflow. conditions and actions are arrays; they are stored
     * as JSON. A workflow without a position goes to the end of the list.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        $wpdb = \Escalated\Escalated::db();
        $table = static::table();
        $now = current_time('mysql');

        if (! isset($data['position'])) {
            $data['position'] = (int) $wpdb->get_var("SELECT COALESCE(MAX(position), -1) + 1 FROM {$table}");
        }

        $row = static::to_row($data);
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $wpdb->insert($table, $row) !== false ? (int) $wpdb->insert_id : false;
    }

    public static function update($id, array $data): bool
    {
        $wpdb = \Escalated\Escalated::db();
        $row = static::to_row($data);
        $row['updated_at'] = current_time('mysql');

        return $wpdb->update(static::table(), $row, ['id' => (int) $id]) !== false;
    }

    public static function delete($id): bool
    {
        $wpdb = \Escalated\Escalated::db();

        return $wpdb->delete(static::table(), ['id' => (int) $id]) !== false;
    }

    /**
     * Give each listed workflow its index in the list as its position.
     *
     * @param  array<int, int>  $ids
     */
    public static function reorder(array $ids): void
    {
        foreach (array_values($ids) as $position => $id) {
            static::update((int) $id, ['position' => $position]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function to_row(array $data): array
    {
        $row = [];
        foreach (['name', 'trigger_event'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('conditions', $data)) {
            $row['conditions'] = $data['conditions'] === null ? null : wp_json_encode($data['conditions']);
        }
        if (array_key_exists('actions', $data)) {
            $row['actions'] = wp_json_encode(array_values((array) $data['actions']));
        }
        foreach (['is_active', 'stop_on_match'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = ! empty($data[$key]) ? 1 : 0;
            }
        }
        if (array_key_exists('position', $data)) {
            $row['position'] = max(0, (int) $data['position']);
        }

        return $row;
    }

    /**
     * Format a workflow for the admin pages and REST, in the shape of the
     * workflow admin contract (domain-model/workflow-admin-contract.md).
     *
     * A trigger stored under a legacy name is shown under its canonical
     * name, conditions are always {all: [...]} or {any: [...]}, and
     * `trigger` is a read-only alias of `trigger_event`.
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

        $trigger = EventVocabulary::canonical((string) $workflow->trigger_event);

        return [
            'id' => (int) $workflow->id,
            'name' => $workflow->name,
            'trigger_event' => $trigger,
            'trigger' => $trigger,
            'conditions' => static::normalize_conditions($conditions),
            'actions' => array_values(is_array($actions) ? $actions : []),
            'is_active' => (bool) $workflow->is_active,
            'position' => (int) $workflow->position,
            'stop_on_match' => (bool) ($workflow->stop_on_match ?? false),
            'created_at' => $workflow->created_at,
            'updated_at' => $workflow->updated_at,
        ];
    }

    /**
     * Conditions as {all: [...]} or {any: [...]}. A flat list (an older
     * stored shape) is read as `all`; nothing at all is {all: []}.
     *
     * @param  mixed  $conditions
     * @return array{all: array}|array{any: array}
     */
    public static function normalize_conditions($conditions): array
    {
        if (! is_array($conditions) || $conditions === []) {
            return ['all' => []];
        }
        if (isset($conditions['any']) && is_array($conditions['any'])) {
            return ['any' => array_values($conditions['any'])];
        }
        if (isset($conditions['all']) && is_array($conditions['all'])) {
            return ['all' => array_values($conditions['all'])];
        }
        if (array_is_list($conditions)) {
            return ['all' => $conditions];
        }
        if (isset($conditions['field'])) {
            return ['all' => [$conditions]];
        }

        return ['all' => []];
    }
}
