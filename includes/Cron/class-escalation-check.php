<?php

namespace Escalated\Cron;

class Escalation_Check
{
    public function register(): void
    {
        add_action('escalated_evaluate_escalations', [$this, 'run']);
    }

    public function run(): void
    {
        $service = new \Escalated\Services\EscalationService;
        $service->evaluate_rules();
    }
}
