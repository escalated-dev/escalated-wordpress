<?php

namespace Escalated\Cron;

class Activity_Purge
{
    public function register(): void
    {
        add_action('escalated_purge_activities', [$this, 'run']);
    }

    public function run(): void
    {
        $days = \Escalated\Models\Setting::get_int('activity_purge_days', 90);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        global $wpdb;
        $table = \Escalated\Escalated::table('ticket_activities');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at <= %s",
            $cutoff
        ));
    }
}
