<?php

/**
 * Ticket Snooze Controller - REST API endpoints for snoozing/unsnoozing tickets.
 */

namespace Escalated\Api;

use Escalated\Models\Ticket;
use Escalated\Services\TicketSnoozeService;
use WP_REST_Request;
use WP_REST_Server;

class Ticket_Snooze_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'tickets';

    /**
     * Regex pattern for ticket ID parameter.
     *
     * @var string
     */
    private const ID_PATTERN = '(?P<id>[\d]+)';

    /**
     * Register ticket snooze routes.
     */
    public function register_routes(): void
    {
        // Snooze a ticket.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::ID_PATTERN.'/snooze',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'snooze'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'until' => [
                            'required' => true,
                            'type' => 'string',
                            'description' => __('Datetime to snooze until (Y-m-d H:i:s).', 'escalated'),
                        ],
                    ],
                ],
            ]
        );

        // Unsnooze a ticket.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::ID_PATTERN.'/unsnooze',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'unsnooze'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Snooze a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function snooze(WP_REST_Request $request)
    {
        $ticket_id = (int) $request->get_param('id');
        $until = sanitize_text_field($request->get_param('until'));
        $user_id = $this->check_token_permission($request, 'ticket.edit');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Insufficient permissions.', 'escalated'), 403);
        }

        try {
            $service = new TicketSnoozeService;
            $ticket = $service->snooze_ticket($ticket_id, $until, $user_id);

            return $this->success([
                'message' => __('Ticket snoozed successfully.', 'escalated'),
                'ticket' => Ticket::enrich($ticket),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error('escalated_snooze_failed', $e->getMessage());
        }
    }

    /**
     * Unsnooze a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function unsnooze(WP_REST_Request $request)
    {
        $ticket_id = (int) $request->get_param('id');
        $user_id = $this->check_token_permission($request, 'ticket.edit');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Insufficient permissions.', 'escalated'), 403);
        }

        try {
            $service = new TicketSnoozeService;
            $ticket = $service->unsnooze_ticket($ticket_id, $user_id);

            return $this->success([
                'message' => __('Ticket unsnoozed successfully.', 'escalated'),
                'ticket' => Ticket::enrich($ticket),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error('escalated_unsnooze_failed', $e->getMessage());
        }
    }
}
