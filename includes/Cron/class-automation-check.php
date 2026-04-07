<?php

namespace Escalated\Cron;

class Automation_Check
{
    public function register(): void
    {
        add_action('escalated_run_automations', [$this, 'run']);
    }

    public function run(): void
    {
        $runner = new \Escalated\Services\AutomationRunner;
        $runner->run();
    }
}
