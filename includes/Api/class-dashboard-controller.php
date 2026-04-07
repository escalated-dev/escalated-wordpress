<?php

/**
 * Dashboard Controller - aggregate statistics for the dashboard.
 */

namespace Escalated\Api;

use Escalated\Models\Ticket;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Dashboard_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'dashboard';

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_stats'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [],
                ],
            ]
        );
    }

    /**
     * Return dashboard statistics.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_stats(WP_REST_Request $request)
    {
        global $wpdb;

        $user_id = $this->check_token_permission($request, 'dashboard:read');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $table = \Escalated\Escalated::table('tickets');
        $open_scope = Ticket::scope_open();

        // Count of all open tickets (open, pending, on_hold, waiting statuses).
        $open = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE {$open_scope} AND deleted_at IS NULL"
        );

        // Count of tickets assigned to the authenticated user.
        $my_assigned = Ticket::count_for_agent($user_id);

        // Count of unassigned open tickets.
        $unassigned = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE assigned_to IS NULL AND {$open_scope} AND deleted_at IS NULL"
        );

        // Count of SLA-breached open tickets (either first response or resolution breached).
        $sla_breached = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE (sla_first_response_breached = 1 OR sla_resolution_breached = 1)
             AND {$open_scope}
             AND deleted_at IS NULL"
        );

        // Count of tickets resolved today.
        $today_start = current_time('Y-m-d').' 00:00:00';
        $today_end = current_time('Y-m-d').' 23:59:59';
        $resolved_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE resolved_at BETWEEN %s AND %s
                 AND deleted_at IS NULL",
                $today_start,
                $today_end
            )
        );

        // Count of tickets that need attention:
        // SLA-breaching tickets + unassigned urgent/critical tickets.
        $sla_breaching = $sla_breached;

        $unassigned_urgent = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE assigned_to IS NULL
             AND priority IN ('urgent', 'critical')
             AND {$open_scope}
             AND deleted_at IS NULL"
        );

        $needs_attention = $sla_breaching + $unassigned_urgent;

        // Status breakdown.
        $status_counts = Ticket::count_by_status();

        return $this->success([
            'open' => $open,
            'my_assigned' => $my_assigned,
            'unassigned' => $unassigned,
            'sla_breached' => $sla_breached,
            'resolved_today' => $resolved_today,
            'needs_attention' => $needs_attention,
            'status_breakdown' => $status_counts,
        ]);
    }
}
