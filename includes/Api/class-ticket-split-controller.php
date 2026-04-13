<?php

/**
 * Ticket Split Controller - REST API endpoint for splitting tickets.
 */

namespace Escalated\Api;

use Escalated\Models\Ticket;
use Escalated\Services\TicketSplitService;
use WP_REST_Request;
use WP_REST_Server;

class Ticket_Split_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'tickets/split';

    /**
     * Register ticket split routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'split_ticket'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'reply_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                            'description' => __('The reply ID to split into a new ticket.', 'escalated'),
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Split a ticket by reply ID.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function split_ticket(WP_REST_Request $request)
    {
        $reply_id = (int) $request->get_param('reply_id');
        $user_id = $this->check_token_permission($request, 'ticket.edit');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Insufficient permissions.', 'escalated'), 403);
        }

        try {
            $service = new TicketSplitService;
            $new_ticket = $service->split_ticket($reply_id, $user_id);

            return $this->success([
                'message' => __('Ticket split successfully.', 'escalated'),
                'ticket' => Ticket::enrich($new_ticket),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error('escalated_split_failed', $e->getMessage(), 404);
        }
    }
}
