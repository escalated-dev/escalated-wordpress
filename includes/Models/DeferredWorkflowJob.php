<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * Queue row for a paused workflow run — populated by the `delay`
 * workflow action when execution hits a wait clause, consumed by
 * Escalated\Cron\Deferred_Workflow_Jobs_Check to resume.
 *
 * Rows are soft-terminal: the cron handler flips `status` to `done`
 * (or `failed`) after running so they don't get re-picked up, and
 * retains the row for audit.
 *
 * Mirrors escalated-nestjs/src/entities/deferred-workflow-job.entity.ts.
 */
class DeferredWorkflowJob
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('deferred_workflow_jobs');
    }

    /**
     * Find a row by ID.
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
     * Create a new deferred job row. `remaining_actions` is JSON-encoded
     * by the caller so consumers can decode + re-dispatch.
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
        $data['status'] = $data['status'] ?? 'pending';

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update a row.
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
     * Fetch every `pending` row whose `run_at` has elapsed.
     *
     * `run_at` is stored in UTC (written via `gmdate` in
     * {@see WorkflowExecutorService::schedule_delay}), so we compare
     * against GMT here regardless of WordPress's configured timezone.
     *
     * @return array<object>
     */
    public static function pending(): array
    {
        global $wpdb;
        $table = static::table();
        $now_gmt = current_time('mysql', true);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s AND run_at <= %s ORDER BY run_at ASC",
                'pending',
                $now_gmt
            )
        ) ?: [];
    }
}
