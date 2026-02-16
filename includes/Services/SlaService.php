<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;
use Escalated\Models\Setting;

class SlaService {

    /**
     * Attach the default SLA policy to a ticket.
     *
     * Finds the default active SLA policy and calculates due dates based
     * on the ticket's priority.
     *
     * @param int $ticket_id Ticket ID.
     * @return bool True if a policy was attached, false if no default policy exists.
     */
    public function attach_default_policy( int $ticket_id ): bool {
        global $wpdb;

        $table = Escalated::table( 'sla_policies' );
        $policy = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE is_default = 1 AND is_active = 1 LIMIT 1"
        );

        if ( ! $policy ) {
            return false;
        }

        $this->attach_policy( $ticket_id, $policy );

        return true;
    }

    /**
     * Attach a specific SLA policy to a ticket and calculate due dates.
     *
     * Uses the policy's first_response_hours and resolution_hours (JSON keyed by priority)
     * to compute first_response_due_at and resolution_due_at.
     *
     * @param int    $ticket_id Ticket ID.
     * @param object $policy    SLA policy object with first_response_hours, resolution_hours,
     *                          and business_hours_only fields.
     */
    public function attach_policy( int $ticket_id, object $policy ): void {
        $ticket = Ticket::find( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        $priority = $ticket->priority ?? 'medium';
        $business_hours_only = ! empty( $policy->business_hours_only );

        // Parse the hours JSON (keyed by priority).
        $first_response_hours = json_decode( $policy->first_response_hours, true );
        $resolution_hours     = json_decode( $policy->resolution_hours, true );

        $fr_hours = isset( $first_response_hours[ $priority ] )
            ? (float) $first_response_hours[ $priority ]
            : null;

        $res_hours = isset( $resolution_hours[ $priority ] )
            ? (float) $resolution_hours[ $priority ]
            : null;

        $now = $ticket->created_at ?? current_time( 'mysql' );

        $update = [
            'sla_policy_id' => $policy->id,
        ];

        if ( $fr_hours !== null ) {
            $update['first_response_due_at'] = $this->calculate_due_date( $now, $fr_hours, $business_hours_only );
        }

        if ( $res_hours !== null ) {
            $update['resolution_due_at'] = $this->calculate_due_date( $now, $res_hours, $business_hours_only );
        }

        Ticket::update( $ticket_id, $update );
    }

    /**
     * Check for SLA breaches across all open tickets.
     *
     * Scans open tickets for overdue first_response_due_at or resolution_due_at,
     * marks them as breached, logs the activity, and fires the escalated_sla_breached action.
     *
     * @return int Number of newly breached tickets.
     */
    public function check_breaches(): int {
        global $wpdb;

        $table = Ticket::table();
        $now = current_time( 'mysql' );
        $open_scope = Ticket::scope_open();
        $breached_count = 0;

        // Find tickets with first response breach.
        $fr_breached = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE {$open_scope}
                AND deleted_at IS NULL
                AND sla_first_response_breached = 0
                AND first_response_due_at IS NOT NULL
                AND first_response_due_at < %s
                AND first_response_at IS NULL",
                $now
            )
        );

        if ( $fr_breached ) {
            foreach ( $fr_breached as $ticket ) {
                Ticket::update( (int) $ticket->id, [
                    'sla_first_response_breached' => 1,
                ] );

                $this->log_activity( (int) $ticket->id, null, 'sla_breached', [
                    'breach_type' => 'first_response',
                    'due_at'      => $ticket->first_response_due_at,
                ] );

                $updated_ticket = Ticket::find( (int) $ticket->id );
                do_action( 'escalated_sla_breached', $updated_ticket, 'first_response' );

                $breached_count++;
            }
        }

        // Find tickets with resolution breach.
        $res_breached = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE {$open_scope}
                AND deleted_at IS NULL
                AND sla_resolution_breached = 0
                AND resolution_due_at IS NOT NULL
                AND resolution_due_at < %s",
                $now
            )
        );

        if ( $res_breached ) {
            foreach ( $res_breached as $ticket ) {
                Ticket::update( (int) $ticket->id, [
                    'sla_resolution_breached' => 1,
                ] );

                $this->log_activity( (int) $ticket->id, null, 'sla_breached', [
                    'breach_type' => 'resolution',
                    'due_at'      => $ticket->resolution_due_at,
                ] );

                $updated_ticket = Ticket::find( (int) $ticket->id );
                do_action( 'escalated_sla_breached', $updated_ticket, 'resolution' );

                $breached_count++;
            }
        }

        return $breached_count;
    }

    /**
     * Check for tickets approaching their SLA due dates (warning threshold).
     *
     * Fires the escalated_sla_warning action for tickets within the warning window.
     *
     * @param int $warning_minutes Number of minutes before breach to trigger a warning. Default 30.
     * @return int Number of tickets with SLA warnings.
     */
    public function check_warnings( int $warning_minutes = 30 ): int {
        global $wpdb;

        $table = Ticket::table();
        $now = current_time( 'mysql' );
        $open_scope = Ticket::scope_open();

        // Calculate the warning threshold datetime.
        $warning_threshold = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + ( $warning_minutes * 60 ) );
        $warning_count = 0;

        // First response warnings: due date is between now and the threshold, not yet breached, no response yet.
        $fr_warnings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE {$open_scope}
                AND deleted_at IS NULL
                AND sla_first_response_breached = 0
                AND first_response_due_at IS NOT NULL
                AND first_response_due_at > %s
                AND first_response_due_at <= %s
                AND first_response_at IS NULL",
                $now,
                $warning_threshold
            )
        );

        if ( $fr_warnings ) {
            foreach ( $fr_warnings as $ticket ) {
                do_action( 'escalated_sla_warning', $ticket, 'first_response', $ticket->first_response_due_at );
                $warning_count++;
            }
        }

        // Resolution warnings: due date is between now and the threshold, not yet breached.
        $res_warnings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE {$open_scope}
                AND deleted_at IS NULL
                AND sla_resolution_breached = 0
                AND resolution_due_at IS NOT NULL
                AND resolution_due_at > %s
                AND resolution_due_at <= %s",
                $now,
                $warning_threshold
            )
        );

        if ( $res_warnings ) {
            foreach ( $res_warnings as $ticket ) {
                do_action( 'escalated_sla_warning', $ticket, 'resolution', $ticket->resolution_due_at );
                $warning_count++;
            }
        }

        return $warning_count;
    }

    /**
     * Calculate a due date by adding hours to a starting datetime.
     *
     * Supports both calendar hours and business hours (Mon-Fri, configurable start/end times).
     *
     * @param string $from                Starting datetime string (MySQL format).
     * @param float  $hours               Number of hours to add.
     * @param bool   $business_hours_only Whether to count only business hours.
     * @return string The calculated due date in MySQL datetime format.
     */
    public function calculate_due_date( string $from, float $hours, bool $business_hours_only = false ): string {
        if ( ! $business_hours_only ) {
            $timestamp = strtotime( $from ) + (int) ( $hours * 3600 );
            return gmdate( 'Y-m-d H:i:s', $timestamp );
        }

        // Business hours calculation.
        $bh_start = (int) apply_filters( 'escalated_business_hours_start', 9 );  // 9 AM
        $bh_end   = (int) apply_filters( 'escalated_business_hours_end', 17 );   // 5 PM
        $bh_days  = apply_filters( 'escalated_business_days', [ 1, 2, 3, 4, 5 ] ); // Mon-Fri

        $bh_per_day = $bh_end - $bh_start; // Hours of business per day.
        if ( $bh_per_day <= 0 ) {
            $bh_per_day = 8;
        }

        $remaining_seconds = (int) ( $hours * 3600 );
        $current = strtotime( $from );

        while ( $remaining_seconds > 0 ) {
            $day_of_week = (int) gmdate( 'N', $current ); // 1=Monday, 7=Sunday.
            $current_hour = (int) gmdate( 'G', $current );
            $current_minute = (int) gmdate( 'i', $current );
            $current_second = (int) gmdate( 's', $current );

            // Check if current day is a business day.
            if ( ! in_array( $day_of_week, $bh_days, true ) ) {
                // Skip to next day at business hours start.
                $current = strtotime( gmdate( 'Y-m-d', $current ) . ' +1 day' );
                $current = strtotime( gmdate( 'Y-m-d', $current ) . sprintf( ' %02d:00:00', $bh_start ) );
                continue;
            }

            // If before business hours, move to start of business hours.
            if ( $current_hour < $bh_start ) {
                $current = strtotime( gmdate( 'Y-m-d', $current ) . sprintf( ' %02d:00:00', $bh_start ) );
                continue;
            }

            // If after business hours, move to next day.
            if ( $current_hour >= $bh_end ) {
                $current = strtotime( gmdate( 'Y-m-d', $current ) . ' +1 day' );
                $current = strtotime( gmdate( 'Y-m-d', $current ) . sprintf( ' %02d:00:00', $bh_start ) );
                continue;
            }

            // We are within business hours. Calculate remaining seconds until end of business day.
            $seconds_until_eod = ( $bh_end * 3600 ) - ( $current_hour * 3600 + $current_minute * 60 + $current_second );

            if ( $remaining_seconds <= $seconds_until_eod ) {
                // We can fit the remaining time within today.
                $current += $remaining_seconds;
                $remaining_seconds = 0;
            } else {
                // Use up the rest of today's business hours and move to next day.
                $remaining_seconds -= $seconds_until_eod;
                $current = strtotime( gmdate( 'Y-m-d', $current ) . ' +1 day' );
                $current = strtotime( gmdate( 'Y-m-d', $current ) . sprintf( ' %02d:00:00', $bh_start ) );
            }
        }

        return gmdate( 'Y-m-d H:i:s', $current );
    }

    /**
     * Log a ticket activity entry.
     *
     * @param int      $ticket_id  Ticket ID.
     * @param int|null $causer_id  User who caused the activity.
     * @param string   $type       Activity type.
     * @param array    $properties Additional properties to store as JSON.
     */
    protected function log_activity( int $ticket_id, ?int $causer_id, string $type, array $properties = [] ): void {
        TicketActivity::create( [
            'ticket_id'  => $ticket_id,
            'causer_id'  => $causer_id,
            'type'       => $type,
            'properties' => ! empty( $properties ) ? wp_json_encode( $properties ) : null,
            'created_at' => current_time( 'mysql' ),
        ] );
    }
}
