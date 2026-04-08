<?php

/**
 * Chat Controller - agent-facing REST API endpoints for live chat.
 */

namespace Escalated\Api;

use Escalated\Services\ChatSessionService;
use WP_REST_Request;
use WP_REST_Server;

class Chat_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'chat';

    /**
     * Register chat routes.
     */
    public function register_routes(): void
    {
        // Get waiting queue.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/queue',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_queue'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                ],
            ]
        );

        // Get active sessions for an agent.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/active',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_active'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'agent_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // Accept a chat session.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<session_id>\d+)/accept',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'accept_session'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'session_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'agent_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // Send a message in a chat session (as agent).
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<session_id>\d+)/messages',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'send_message'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'session_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'body' => [
                            'required' => true,
                            'type' => 'string',
                        ],
                        'agent_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // End a chat session.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<session_id>\d+)/end',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'end_session'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'session_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // Get a specific session.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<session_id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_session'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'session_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    public function get_queue(): \WP_REST_Response
    {
        $service = new ChatSessionService;

        return $this->success($service->get_waiting());
    }

    public function get_active(WP_REST_Request $request): \WP_REST_Response
    {
        $service = new ChatSessionService;
        $agent_id = (int) $request->get_param('agent_id');

        return $this->success($service->get_active_for_agent($agent_id));
    }

    public function accept_session(WP_REST_Request $request)
    {
        $service = new ChatSessionService;
        $session_id = (int) $request->get_param('session_id');
        $agent_id = (int) $request->get_param('agent_id');

        try {
            $session = $service->accept($session_id, $agent_id);

            return $this->success($session);
        } catch (\Throwable $e) {
            return $this->error('escalated_chat_error', $e->getMessage(), 400);
        }
    }

    public function send_message(WP_REST_Request $request)
    {
        $service = new ChatSessionService;
        $session_id = (int) $request->get_param('session_id');
        $body = $request->get_param('body');
        $agent_id = (int) $request->get_param('agent_id');

        try {
            $reply = $service->send_message($session_id, $body, $agent_id, 'agent');

            return $this->success($reply, 201);
        } catch (\Throwable $e) {
            return $this->error('escalated_chat_error', $e->getMessage(), 400);
        }
    }

    public function end_session(WP_REST_Request $request)
    {
        $service = new ChatSessionService;
        $session_id = (int) $request->get_param('session_id');

        try {
            $session = $service->end($session_id);

            return $this->success($session);
        } catch (\Throwable $e) {
            return $this->error('escalated_chat_error', $e->getMessage(), 400);
        }
    }

    public function get_session(WP_REST_Request $request)
    {
        $session_id = (int) $request->get_param('session_id');
        $session = \Escalated\Models\ChatSession::find($session_id);

        if (! $session) {
            return $this->error('escalated_not_found', __('Chat session not found.', 'escalated'), 404);
        }

        return $this->success($session);
    }
}
