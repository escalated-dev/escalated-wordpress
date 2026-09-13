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
        // ticket.tagged means a tag was added. Removing one fires
        // escalated_tag_removed, which has no workflow trigger.
        add_action('escalated_tag_added', [$this, 'on_tag_changed'], 50, 2);
        add_action('escalated_department_changed', [$this, 'on_department_changed'], 50, 4);
        add_action('escalated_ticket_priority_changed', [$this, 'on_ticket_priority_changed'], 50, 4);
        add_action('escalated_sla_warning', [$this, 'on_sla_warning'], 50, 3);
        add_action('escalated_sla_breached', [$this, 'on_sla_breached'], 50, 2);
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

    public function on_ticket_priority_changed($ticket, $old_priority = null, $new_priority = null, $causer_id = null): void
    {
        $this->run_if_ticket('ticket.priority_changed', $ticket);
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

        if ($this->is_agent_reply($reply, $ticket)) {
            $this->run_if_ticket('reply.agent_reply', $ticket);
        }
    }

    /**
     * SlaService::check_warnings() runs every minute and fires the warning
     * again on every run while the ticket is inside the warning window. Run
     * the workflows once per ticket, SLA target and due time.
     */
    public function on_sla_warning($ticket, $sla_type = null, $due_at = null): void
    {
        if (! is_object($ticket) || empty($ticket->id)) {
            return;
        }

        $key = 'escalated_wf_sla_warning_'.md5($ticket->id.'|'.$sla_type.'|'.$due_at);
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, WEEK_IN_SECONDS);

        $this->run_if_ticket('sla.warning', $ticket);
    }

    public function on_sla_breached($ticket, $sla_type = null): void
    {
        $this->run_if_ticket('sla.breached', $ticket);
    }

    /**
     * A public reply by someone other than the requester, the same test
     * TicketService::reply() uses to record the first response. Internal
     * notes and replies without an author (guest email, workflow replies)
     * do not count.
     */
    protected function is_agent_reply($reply, $ticket): bool
    {
        if (! is_object($reply) || ! is_object($ticket) || ! empty($reply->is_internal_note)) {
            return false;
        }

        $author_id = (int) ($reply->author_id ?? 0);

        return $author_id > 0 && $author_id !== (int) ($ticket->requester_id ?? 0);
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
