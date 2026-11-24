<?php

namespace Escalated\Cron;

class Sla_Check
{
    public function register(): void
    {
        add_action('escalated_check_sla', [$this, 'run']);
    }

    public function run(): void
    {
        // The heartbeat the scheduler warning reads (Support\SchedulerHealth).
        \Escalated\Support\SchedulerHealth::beat();

        $service = new \Escalated\Services\SlaService;
        $service->check_breaches();
        $warning_minutes = \Escalated\Models\Setting::get_int('sla_warning_minutes', 30);
        $service->check_warnings($warning_minutes);
    }
}
