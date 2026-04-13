<?php

namespace Escalated\Models;

use Escalated\Escalated;

class WorkflowLog
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('workflow_logs');
    }

    /**
     * Find logs by workflow ID with eager-loaded relationship data.
     *
     * @param  int  $workflowId
     * @param  int  $limit
     * @return array
     */
    public static function for_workflow($workflowId, $limit = 100)
    {
        global $wpdb;
        $logs_table = static::table();
        $workflows_table = Workflow::table();
        $tickets_table = Escalated::table('tickets');

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, w.name AS workflow_name, t.reference AS ticket_reference
                 FROM {$logs_table} l
                 LEFT JOIN {$workflows_table} w ON l.workflow_id = w.id
                 LEFT JOIN {$tickets_table} t ON l.ticket_id = t.id
                 WHERE l.workflow_id = %d
                 ORDER BY l.created_at DESC
                 LIMIT %d",
                $workflowId,
                $limit
            )
        ) ?: [];

        return array_map([static::class, 'format'], $results);
    }

    /**
     * Format a workflow log for API/Inertia response with computed fields.
     *
     * @param  object  $log
     * @return array
     */
    public static function format($log)
    {
        $raw_actions = $log->actions_executed;
        if (is_string($raw_actions)) {
            $decoded = json_decode($raw_actions, true);
            $raw_actions = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw_actions)) {
            $raw_actions = [];
        }

        $started_at = ! empty($log->started_at) ? strtotime($log->started_at) * 1000 : null;
        $completed_at = ! empty($log->completed_at) ? strtotime($log->completed_at) * 1000 : null;
        $duration_ms = ($started_at !== null && $completed_at !== null) ? $completed_at - $started_at : null;

        return [
            'id' => (int) $log->id,
            'workflow_id' => (int) $log->workflow_id,
            'ticket_id' => (int) $log->ticket_id,
            'trigger_event' => $log->trigger_event,
            'event' => $log->trigger_event,
            'workflow_name' => $log->workflow_name ?? null,
            'ticket_reference' => $log->ticket_reference ?? null,
            'matched' => (bool) ($log->conditions_matched ?? true),
            'actions_executed' => count($raw_actions),
            'action_details' => $raw_actions,
            'duration_ms' => $duration_ms,
            'status' => ! empty($log->error_message) ? 'failed' : 'success',
            'error_message' => $log->error_message ?? null,
            'created_at' => $log->created_at,
        ];
    }
}
