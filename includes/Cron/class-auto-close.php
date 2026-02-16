<?php
namespace Escalated\Cron;

class Auto_Close {
    public function register(): void {
        add_action( 'escalated_auto_close', [ $this, 'run' ] );
    }

    public function run(): void {
        if ( ! \Escalated\Models\Setting::get_bool( 'auto_close_enabled', false ) ) {
            return;
        }
        $days = \Escalated\Models\Setting::get_int( 'auto_close_days', 7 );
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        global $wpdb;
        $table = \Escalated\Escalated::table( 'tickets' );
        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = 'resolved' AND resolved_at <= %s AND deleted_at IS NULL",
            $cutoff
        ) );

        $service = new \Escalated\Services\TicketService();
        foreach ( $tickets as $ticket ) {
            try {
                $service->close( $ticket->id );
            } catch ( \Throwable $e ) {
                // Skip tickets that can't be closed
            }
        }
    }
}
