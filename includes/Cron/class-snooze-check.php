<?php

namespace Escalated\Cron;

use Escalated\Services\TicketSnoozeService;

class Snooze_Check
{
    /**
     * Register the cron hook.
     */
    public function register(): void
    {
        add_action('escalated_check_snoozed_tickets', [$this, 'run']);
    }

    /**
     * Check for snoozed tickets that need to be woken up.
     */
    public function run(): void
    {
        $service = new TicketSnoozeService;
        $service->wake_snoozed_tickets();
    }
}
