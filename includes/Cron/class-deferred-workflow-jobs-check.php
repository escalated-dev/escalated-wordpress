<?php

namespace Escalated\Cron;

use Escalated\Services\WorkflowExecutorService;

/**
 * Runs once per minute via WP-Cron to pick up pending
 * DeferredWorkflowJob rows whose wait has elapsed and resume their
 * remaining_actions. Flips each row's status to `done` / `failed`
 * after execution so it's never re-picked up.
 *
 * Host apps with a misconfigured scheduler (DISABLE_WP_CRON without a
 * system cron replacement) will see delay actions pile up as `pending`
 * indefinitely.
 */
class Deferred_Workflow_Jobs_Check
{
    /**
     * Register the cron hook.
     */
    public function register(): void
    {
        add_action('escalated_run_due_deferred_workflow_jobs', [$this, 'run']);
    }

    /**
     * Dispatch all pending deferred workflow jobs whose run_at has elapsed.
     */
    public function run(): void
    {
        $service = new WorkflowExecutorService;
        $service->run_due_deferred_jobs();
    }
}
