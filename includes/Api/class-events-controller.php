<?php

/**
 * Events Controller - REST polling endpoint for real-time event support.
 *
 * Provides GET /escalated/v1/events?since={timestamp} to poll for
 * recent ticket lifecycle events.
 */

namespace Escalated\Api;

use Escalated\Services\BroadcastService;
use WP_REST_Request;
use WP_REST_Server;

class Events_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'events';

    /**
     * Register event polling routes.
     */
    public function register_routes(): void
    {
        // Poll for events.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_events'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'since' => [
                            'type' => 'integer',
                            'default' => 0,
                            'sanitize_callback' => 'absint',
                            'description' => __('Unix timestamp to retrieve events from.', 'escalated'),
                        ],
                    ],
                ],
            ]
        );

        // Get event types.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/types',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_event_types'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                ],
            ]
        );
    }

    /**
     * Get events since a given timestamp.
     */
    public function get_events(WP_REST_Request $request)
    {
        if (! BroadcastService::is_enabled()) {
            return $this->error(
                'escalated_broadcasting_disabled',
                __('Broadcasting is not enabled.', 'escalated'),
                403
            );
        }

        $since = (int) $request->get_param('since');
        $events = BroadcastService::get_events_since($since);

        return $this->success([
            'events' => $events,
            'count' => count($events),
            'server_time' => time(),
        ]);
    }

    /**
     * Get the list of supported event types.
     */
    public function get_event_types()
    {
        return $this->success([
            'types' => BroadcastService::event_types(),
        ]);
    }
}
