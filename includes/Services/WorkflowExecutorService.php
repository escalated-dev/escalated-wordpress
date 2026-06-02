<?php

namespace Escalated\Services;

use Escalated\Models\DeferredWorkflowJob;
use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;

/**
 * Performs the side-effects dictated by a matched Workflow.
 *
 * Distinct from WorkflowEngine (which only evaluates conditions).
 * Parses the JSON action array stored on `workflow.actions` and
 * dispatches each entry to the relevant service.
 *
 * Action catalog: change_priority, change_status, assign_agent,
 * set_department, add_tag, remove_tag, add_note, insert_canned_reply,
 * delay. Mirrors the NestJS reference impl in
 * escalated-nestjs/src/services/workflow-executor.service.ts.
 *
 * `delay` splits a run into two halves: everything before the delay
 * runs inline, everything after is persisted as a DeferredWorkflowJob
 * row and picked up by Escalated\Cron\Deferred_Workflow_Jobs_Check
 * once the wait expires.
 *
 * One failing action never halts the others — matches NestJS. Unknown
 * action types warn-log (via error_log when WP_DEBUG) and skip.
 */
class WorkflowExecutorService
{
    protected TicketService $ticket_service;

    protected AssignmentService $assignment_service;

    public function __construct(
        ?TicketService $ticket_service = null,
        ?AssignmentService $assignment_service = null
    ) {
        $this->ticket_service = $ticket_service ?? new TicketService;
        $this->assignment_service = $assignment_service ?? new AssignmentService;
    }

    /**
     * Execute every action in $actions_json against $ticket. Returns
     * the parsed action list so the caller (typically WorkflowRunner)
     * can serialize it into a workflow_log row.
     *
     * @param  object  $ticket  Ticket row as returned by Ticket::find.
     * @param  string|null  $actions_json  JSON-encoded action array.
     * @return array parsed actions (empty on malformed input).
     */
    public function execute(object $ticket, ?string $actions_json): array
    {
        $actions = $this->parse_actions($actions_json);
        $count = count($actions);
        for ($i = 0; $i < $count; $i++) {
            $action = $actions[$i];
            if (($action['type'] ?? '') === 'delay') {
                $remaining = array_slice($actions, $i + 1);
                $this->schedule_delay($ticket, (string) ($action['value'] ?? ''), $remaining);

                return $actions;
            }
            try {
                $this->dispatch($ticket, $action);
            } catch (\Throwable $e) {
                $this->log_action_failure($ticket, $action, $e);
            }
        }

        return $actions;
    }

    /**
     * Persist remaining actions to the deferred-jobs queue with
     * run_at = now + $seconds. Logs a warning + skips when the value
     * isn't a positive integer. Mirrors NestJS scheduleDelay.
     *
     * @param  array<int,array<string,mixed>>  $remaining
     */
    protected function schedule_delay(object $ticket, string $value, array $remaining): void
    {
        $seconds = (int) $value;
        if (! ctype_digit($value) || $seconds <= 0) {
            $this->log_debug(sprintf(
                'delay: invalid seconds value "%s", skipping remaining actions',
                $value
            ));

            return;
        }
        $run_at = gmdate('Y-m-d H:i:s', time() + $seconds);
        DeferredWorkflowJob::create([
            'ticket_id' => (int) $ticket->id,
            'remaining_actions' => wp_json_encode($remaining),
            'run_at' => $run_at,
            'status' => 'pending',
        ]);
    }

    /**
     * Dispatch every pending deferred job whose `run_at` has elapsed.
     *
     * For each row, re-loads the ticket, re-invokes execute() with the
     * stored remaining_actions JSON, and flips status to `done` /
     * `failed`. Called by Cron\Deferred_Workflow_Jobs_Check.
     *
     * @return array{processed:int,failed:int}
     */
    public function run_due_deferred_jobs(): array
    {
        $processed = 0;
        $failed = 0;
        foreach (DeferredWorkflowJob::pending() as $job) {
            try {
                $ticket = Ticket::find((int) $job->ticket_id);
                if (! $ticket) {
                    DeferredWorkflowJob::update((int) $job->id, [
                        'status' => 'failed',
                        'last_error' => sprintf('Ticket #%d not found', (int) $job->ticket_id),
                    ]);
                    $failed++;

                    continue;
                }
                $this->execute($ticket, $job->remaining_actions);
                DeferredWorkflowJob::update((int) $job->id, ['status' => 'done']);
                $processed++;
            } catch (\Throwable $e) {
                DeferredWorkflowJob::update((int) $job->id, [
                    'status' => 'failed',
                    'last_error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    protected function parse_actions(?string $actions_json): array
    {
        if (empty($actions_json)) {
            return [];
        }
        $decoded = json_decode($actions_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function dispatch(object $ticket, array $action): void
    {
        $type = $action['type'] ?? '';
        $value = (string) ($action['value'] ?? '');
        $ticket_id = (int) $ticket->id;

        switch ($type) {
            case 'change_priority':
                $this->change_priority($ticket_id, $value);
                break;

            case 'change_status':
                $this->change_status($ticket_id, $value);
                break;

            case 'assign_agent':
                $this->assign_agent($ticket_id, $value);
                break;

            case 'set_department':
                $this->set_department($ticket_id, $value);
                break;

            case 'add_tag':
                $this->add_tag($ticket_id, $value);
                break;

            case 'remove_tag':
                $this->remove_tag($ticket_id, $value);
                break;

            case 'add_note':
                $this->add_note($ticket_id, $value);
                break;

            case 'insert_canned_reply':
                $this->insert_canned_reply($ticket, $value);
                break;

            case 'add_follower':
                $this->add_follower($ticket_id, $value);
                break;

            default:
                $this->log_debug(sprintf('unknown action type: %s', $type));
        }
    }

    protected function change_priority(int $ticket_id, string $value): void
    {
        if ($value === '') {
            return;
        }
        $this->ticket_service->change_priority($ticket_id, $value);
    }

    protected function change_status(int $ticket_id, string $value): void
    {
        if ($value === '') {
            return;
        }
        $this->ticket_service->change_status($ticket_id, $value);
    }

    protected function assign_agent(int $ticket_id, string $value): void
    {
        $agent_id = (int) $value;
        if ($agent_id <= 0) {
            return;
        }
        $this->assignment_service->assign($ticket_id, $agent_id);
    }

    protected function set_department(int $ticket_id, string $value): void
    {
        $department_id = (int) $value;
        if ($department_id <= 0) {
            return;
        }
        $this->ticket_service->change_department($ticket_id, $department_id);
    }

    protected function add_tag(int $ticket_id, string $value): void
    {
        $tag_id = $this->resolve_tag_id($value);
        if ($tag_id === null) {
            $this->log_debug(sprintf('add_tag: tag "%s" not found', $value));

            return;
        }
        $this->ticket_service->add_tags($ticket_id, [$tag_id]);
    }

    protected function remove_tag(int $ticket_id, string $value): void
    {
        $tag_id = $this->resolve_tag_id($value);
        if ($tag_id === null) {
            return;
        }
        $this->ticket_service->remove_tags($ticket_id, [$tag_id]);
    }

    /**
     * Resolve a tag value (slug or numeric id) to an integer id.
     * Overridable for tests via subclass.
     */
    protected function resolve_tag_id(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        // Try slug first.
        $tag = Tag::find_by_slug($value);
        if ($tag && isset($tag->id)) {
            return (int) $tag->id;
        }
        // Fall back to numeric id.
        if (ctype_digit($value)) {
            $by_id = Tag::find((int) $value);
            if ($by_id && isset($by_id->id)) {
                return (int) $by_id->id;
            }
        }

        return null;
    }

    /**
     * Add a host user as a follower of the ticket. The value is a numeric
     * user id; non-positive values are skipped. TicketService::follow is
     * idempotent, so following the same user twice is a harmless no-op.
     */
    protected function add_follower(int $ticket_id, string $value): void
    {
        $user_id = (int) $value;
        if ($user_id <= 0) {
            return;
        }
        $this->ticket_service->follow($ticket_id, $user_id);
    }

    protected function add_note(int $ticket_id, string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            return;
        }
        Reply::create([
            'ticket_id' => $ticket_id,
            'author_id' => null,
            'body' => $body,
            'is_internal_note' => 1,
            'is_pinned' => 0,
            'type' => 'note',
            'metadata' => wp_json_encode(['system_note' => true, 'source' => 'workflow']),
        ]);
    }

    /**
     * Insert an agent-visible reply built from a template. {{field}}
     * placeholders are interpolated against the ticket via
     * WorkflowEngine::interpolateVariables. Unknown variables stay
     * as literal {{...}} so the reader can see the gap.
     */
    protected function insert_canned_reply(object $ticket, string $template): void
    {
        $template = trim($template);
        if ($template === '') {
            return;
        }
        $body = WorkflowEngine::interpolateVariables($template, $this->ticket_to_array($ticket));
        Reply::create([
            'ticket_id' => (int) $ticket->id,
            'author_id' => null,
            'body' => $body,
            'is_internal_note' => 0,
            'is_pinned' => 0,
            'type' => 'reply',
            'metadata' => wp_json_encode(['system_reply' => true, 'source' => 'workflow']),
        ]);
    }

    /**
     * Flatten a ticket row (object) into a string map for the template
     * interpolator. Non-scalar fields are dropped.
     *
     * @return array<string,string>
     */
    protected function ticket_to_array(object $ticket): array
    {
        $out = [];
        foreach (get_object_vars($ticket) as $key => $val) {
            if ($val === null || $val === '' || is_array($val) || is_object($val)) {
                continue;
            }
            $out[$key] = (string) $val;
        }

        return $out;
    }

    protected function log_action_failure(object $ticket, array $action, \Throwable $e): void
    {
        $this->log_debug(sprintf(
            'action failed: type=%s ticket=%d error=%s',
            $action['type'] ?? '?',
            (int) ($ticket->id ?? 0),
            $e->getMessage()
        ));
    }

    protected function log_debug(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Escalated\\WorkflowExecutor] '.$message);
        }
    }
}
