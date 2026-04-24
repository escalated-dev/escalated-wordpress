<?php

namespace Escalated\Services;

use Escalated\Models\Ticket;

/**
 * Bridges WordPress `escalated_*` actions into WorkflowRunnerService.
 *
 * Final piece of the workflow stack for WordPress. Each hook handler
 * maps the WP action to a canonical workflow trigger name (matching
 * the 12-event set in WorkflowEngine::TRIGGER_EVENTS) and delegates
 * to the runner.
 *
 * Register once during plugin boot by calling ->register().
 *
 * Mirrors the NestJS workflow.listener.ts and the Laravel
 * ProcessWorkflows listener.
 */
class WorkflowListener
{
    protected WorkflowRunnerService $runner;

    public function __construct(?WorkflowRunnerService $runner = null)
    {
        $this->runner = $runner ?? new WorkflowRunnerService;
    }

    public function register(): void
    {
        // Priority 50 — after the normal product-side handlers (10-20)
        // but before webhooks / mail (100+). Workflows should observe
        // the already-persisted state and fire their own side-effects
        // into the same hook chain.
        add_action('escalated_ticket_created', [$this, 'on_ticket_created'], 50, 1);
        add_action('escalated_ticket_updated', [$this, 'on_ticket_updated'], 50, 1);
        add_action('escalated_ticket_status_changed', [$this, 'on_ticket_status_changed'], 50, 4);
        add_action('escalated_ticket_assigned', [$this, 'on_ticket_assigned'], 50, 4);
        add_action('escalated_ticket_reopened', [$this, 'on_ticket_reopened'], 50, 2);
        add_action('escalated_reply_created', [$this, 'on_reply_created'], 50, 2);
        add_action('escalated_tag_added', [$this, 'on_tag_changed'], 50, 2);
        add_action('escalated_tag_removed', [$this, 'on_tag_changed'], 50, 2);
        add_action('escalated_department_changed', [$this, 'on_department_changed'], 50, 4);
    }

    public function on_ticket_created($ticket): void
    {
        $this->run_if_ticket('ticket.created', $ticket);
    }

    public function on_ticket_updated($ticket): void
    {
        $this->run_if_ticket('ticket.updated', $ticket);
    }

    public function on_ticket_status_changed($ticket, $old_status = null, $new_status = null, $causer_id = null): void
    {
        $this->run_if_ticket('ticket.status_changed', $ticket);
    }

    public function on_ticket_assigned($ticket, $agent_id = null, $old_agent_id = null, $causer_id = null): void
    {
        $this->run_if_ticket('ticket.assigned', $ticket);
    }

    public function on_ticket_reopened($ticket, $causer_id = null): void
    {
        $this->run_if_ticket('ticket.reopened', $ticket);
    }

    public function on_reply_created($reply, $ticket = null): void
    {
        if ($ticket === null && is_object($reply) && ! empty($reply->ticket_id)) {
            $ticket = Ticket::find((int) $reply->ticket_id);
        }
        $this->run_if_ticket('reply.created', $ticket);
    }

    public function on_tag_changed($ticket_id, $tag_id = null): void
    {
        if (! is_numeric($ticket_id)) {
            return;
        }
        $ticket = Ticket::find((int) $ticket_id);
        $this->run_if_ticket('ticket.tagged', $ticket);
    }

    public function on_department_changed($ticket, $old_department_id = null, $new_department_id = null, $causer_id = null): void
    {
        $this->run_if_ticket('ticket.department_changed', $ticket);
    }

    protected function run_if_ticket(string $trigger, $ticket): void
    {
        if (! is_object($ticket) || empty($ticket->id)) {
            return;
        }
        try {
            $this->runner->run_for_event($trigger, $ticket);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Escalated\\WorkflowListener] %s handler failed: %s',
                    $trigger,
                    $e->getMessage()
                ));
            }
        }
    }
}
