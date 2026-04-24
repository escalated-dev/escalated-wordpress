<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Workflow;

/**
 * Orchestrates evaluation + execution of Workflows for a given trigger
 * event.
 *
 * For each active Workflow matching the trigger (in `position` order),
 * evaluates conditions via WorkflowEngine and, if matched, dispatches
 * to WorkflowExecutorService. Writes a workflow_log row per Workflow
 * considered. Honors `stop_on_match`.
 *
 * Executor failures are caught so one misbehaving workflow never blocks
 * the rest — the error is stamped on the log row.
 *
 * Mirrors the NestJS reference `workflow-runner.service.ts`.
 */
class WorkflowRunnerService
{
    protected WorkflowEngine $engine;

    protected WorkflowExecutorService $executor;

    public function __construct(
        ?WorkflowEngine $engine = null,
        ?WorkflowExecutorService $executor = null
    ) {
        $this->engine = $engine ?? new WorkflowEngine;
        $this->executor = $executor ?? new WorkflowExecutorService;
    }

    /**
     * Run workflows matching the given trigger event against a ticket.
     *
     * @param  object  $ticket  Ticket row (as returned by Ticket::find).
     * @return int Number of workflows that executed actions.
     */
    public function run_for_event(string $trigger_event, object $ticket): int
    {
        $workflows = $this->find_active_by_trigger($trigger_event);
        if (empty($workflows)) {
            return 0;
        }

        $condition_map = $this->ticket_to_condition_map($ticket);
        $executed = 0;

        foreach ($workflows as $wf) {
            $started_at = current_time('mysql');
            $conditions = $this->decode_json_array($wf->conditions);
            $matched = $this->matches($conditions, $condition_map);

            $log_id = $this->create_log([
                'workflow_id' => (int) $wf->id,
                'ticket_id' => (int) $ticket->id,
                'trigger_event' => $trigger_event,
                'conditions_matched' => $matched ? 1 : 0,
                'started_at' => $started_at,
                'created_at' => $started_at,
            ]);

            if (! $matched) {
                continue;
            }

            try {
                $result = $this->executor->execute($ticket, $wf->actions);
                $this->update_log($log_id, [
                    'actions_executed' => wp_json_encode($result),
                    'completed_at' => current_time('mysql'),
                ]);
                $executed++;
            } catch (\Throwable $e) {
                $this->update_log($log_id, [
                    'error_message' => $e->getMessage(),
                    'completed_at' => current_time('mysql'),
                ]);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[Escalated\\WorkflowRunner] workflow #%d (%s) failed on ticket #%d: %s',
                        $wf->id,
                        $wf->name,
                        $ticket->id,
                        $e->getMessage()
                    ));
                }
            }

            if (! empty($wf->stop_on_match)) {
                break;
            }
        }

        return $executed;
    }

    protected function find_active_by_trigger(string $trigger_event): array
    {
        global $wpdb;
        $table = Workflow::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE trigger_event = %s AND is_active = 1
                 ORDER BY position ASC, name ASC",
                $trigger_event
            )
        ) ?: [];
    }

    protected function matches(?array $conditions, array $ticket_map): bool
    {
        // Null / empty conditions match everything (NestJS parity).
        if (empty($conditions)) {
            return true;
        }

        return $this->engine->evaluateConditions($conditions, $ticket_map);
    }

    protected function create_log(array $data): int
    {
        global $wpdb;
        $table = Escalated::table('workflow_logs');
        $wpdb->insert($table, $data);

        return (int) $wpdb->insert_id;
    }

    protected function update_log(int $log_id, array $data): void
    {
        if ($log_id <= 0) {
            return;
        }
        global $wpdb;
        $table = Escalated::table('workflow_logs');
        $wpdb->update($table, $data, ['id' => $log_id]);
    }

    protected function decode_json_array($value): ?array
    {
        if (empty($value)) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Flatten a ticket row into a string map for the condition evaluator.
     *
     * @return array<string,string>
     */
    protected function ticket_to_condition_map(object $ticket): array
    {
        $out = [];
        foreach (get_object_vars($ticket) as $key => $val) {
            if ($val === null || is_array($val) || is_object($val)) {
                $out[$key] = '';

                continue;
            }
            $out[$key] = (string) $val;
        }

        return $out;
    }
}
