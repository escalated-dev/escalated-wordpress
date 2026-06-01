<?php

/**
 * Widget Chat Controller - public REST API endpoints for live chat from the widget.
 */

namespace Escalated\Api;

use Escalated\Models\ChatSession;
use Escalated\Models\Ticket;
use Escalated\Services\ChatSessionService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Widget_Chat_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'widget/chat';

    /**
     * Register widget chat routes.
     */
    public function register_routes(): void
    {
        // Check availability.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/availability',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'availability'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'department_id' => [
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // Start a chat session.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/start',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'start_chat'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'name' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'email' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_email',
                        ],
                        'message' => [
                            'type' => 'string',
                        ],
                        'department_id' => [
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // Send a visitor message.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<session_id>\d+)/messages',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'send_message'],
                    'permission_callback' => '__return_true',
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
                        'guest_token' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // End a chat session from visitor side.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<session_id>\d+)/end',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'end_chat'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'session_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'guest_token' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // Poll for new events in a session (WordPress doesn't have WebSocket).
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/(?P<session_id>\d+)/poll',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'poll_events'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'session_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'guest_token' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'since' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Reuse the ticket widget's enabled setting and per-IP rate limit.
     */
    public function widget_enabled_check()
    {
        return (new Widget_Controller)->widget_enabled_check();
    }

    public function availability(WP_REST_Request $request): WP_REST_Response
    {
        $service = new ChatSessionService;
        $department_id = $request->get_param('department_id') ? (int) $request->get_param('department_id') : null;
        $queue_depth = $service->get_queue_depth($department_id);

        return $this->success([
            'available' => true,
            'queue_depth' => $queue_depth,
        ]);
    }

    public function start_chat(WP_REST_Request $request)
    {
        $service = new ChatSessionService;

        try {
            $session = $service->start(
                $request->get_param('name') ?: 'Visitor',
                $request->get_param('email'),
                $request->get_param('message'),
                $request->get_param('department_id') ? (int) $request->get_param('department_id') : null
            );
            $ticket = Ticket::find((int) $session->ticket_id);

            return $this->success([
                'id' => (int) $session->id,
                'ticket_id' => (int) $session->ticket_id,
                'status' => $session->status,
                'visitor_name' => $session->visitor_name,
                'guest_token' => $ticket->guest_token ?? null,
                'created_at' => $session->created_at,
            ], 201);
        } catch (\Throwable $e) {
            return $this->error('escalated_chat_error', $e->getMessage(), 500);
        }
    }

    public function send_message(WP_REST_Request $request)
    {
        $service = new ChatSessionService;
        $session_id = (int) $request->get_param('session_id');
        $body = $request->get_param('body');

        try {
            $session = $this->verified_session($session_id, (string) $request->get_param('guest_token'));
            if ($session instanceof \WP_Error) {
                return $session;
            }

            $reply = $service->send_message($session_id, $body, null, 'visitor');

            return $this->success($reply, 201);
        } catch (\Throwable $e) {
            return $this->error('escalated_chat_error', $e->getMessage(), 400);
        }
    }

    public function end_chat(WP_REST_Request $request)
    {
        $service = new ChatSessionService;
        $session_id = (int) $request->get_param('session_id');

        try {
            $session = $this->verified_session($session_id, (string) $request->get_param('guest_token'));
            if ($session instanceof \WP_Error) {
                return $session;
            }

            $session = $service->end($session_id);

            return $this->success([
                'id' => (int) $session->id,
                'status' => $session->status,
                'ended_at' => $session->ended_at,
            ]);
        } catch (\Throwable $e) {
            return $this->error('escalated_chat_error', $e->getMessage(), 400);
        }
    }

    /**
     * Poll for new messages in a chat session.
     * WordPress doesn't support WebSocket, so this provides long-polling.
     */
    public function poll_events(WP_REST_Request $request): WP_REST_Response|\WP_Error
    {
        $session_id = (int) $request->get_param('session_id');
        $since = $request->get_param('since') ?: gmdate('Y-m-d H:i:s', strtotime('-30 seconds'));

        $session = $this->verified_session($session_id, (string) $request->get_param('guest_token'));
        if ($session instanceof \WP_Error) {
            return $session;
        }

        global $wpdb;
        $replies_table = \Escalated\Escalated::table('replies');
        $replies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$replies_table} WHERE ticket_id = %d AND created_at > %s AND deleted_at IS NULL ORDER BY created_at ASC",
                $session->ticket_id,
                $since
            )
        );

        return $this->success([
            'session_status' => $session->status,
            'messages' => $replies,
        ]);
    }

    /**
     * Verify that a public widget caller knows the ticket guest token for a session.
     */
    private function verified_session(int $session_id, string $guest_token): \stdClass|\WP_Error
    {
        $session = ChatSession::find($session_id);
        if (! $session) {
            return $this->error('escalated_not_found', __('Chat session not found.', 'escalated'), 404);
        }

        $ticket = Ticket::find((int) $session->ticket_id);
        if (! $ticket || empty($ticket->guest_token) || ! hash_equals((string) $ticket->guest_token, $guest_token)) {
            return $this->error('escalated_forbidden', __('Chat session verification failed.', 'escalated'), 403);
        }

        return $session;
    }
}
