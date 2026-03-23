<?php

namespace Escalated;

class Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'escalated_check_sla' );
        wp_clear_scheduled_hook( 'escalated_evaluate_escalations' );
        wp_clear_scheduled_hook( 'escalated_auto_close' );
        wp_clear_scheduled_hook( 'escalated_purge_activities' );
        wp_clear_scheduled_hook( 'escalated_run_automations' );
    }
}
