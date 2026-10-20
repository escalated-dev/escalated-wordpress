<?php

/**
 * Widget Chat Controller - public REST API endpoints for live chat from the widget.
 */

namespace Escalated\Api;

use Escalated\Models\ChatSession;
use Escalated\Models\SatisfactionRating;
use Escalated\Models\Ticket;
use Escalated\Services\ChatSessionService;
use Escalated\Services\WidgetMetadata;
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
     * Path pattern for the chat id the widget uses: the ticket's random guest
     * token (wp_generate_password(64, false)). Numeric session ids, used by
     * the older guest_token routes, are far shorter, so the two never overlap.
     */
    private const CHAT_ID = '(?P<id>[A-Za-z0-9]{32,64})';

    /**
     * Requests per minute per IP for the id-keyed routes. The widget polls
     * messages every 3 seconds and sends typing pings, which the general
     * 30/minute widget limit cannot carry.
     */
    private const CHAT_RATE_LIMIT = 120;

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
                            'type' => ['string', 'null'],
                        ],
                        'department_id' => Widget_Controller::department_arg(),
                        'metadata' => WidgetMetadata::rest_arg(),
                    ],
                ],
            ]
        );

        // Routes keyed by the chat id returned from /start (shared widget).
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::CHAT_ID.'/messages',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_messages'],
                    'permission_callback' => [$this, 'chat_id_check'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'post_message'],
                    'permission_callback' => [$this, 'chat_id_check'],
                    'args' => [
                        'body' => [
                            'required' => true,
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 5000,
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::CHAT_ID.'/typing',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'typing'],
                    'permission_callback' => [$this, 'chat_id_check'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::CHAT_ID.'/end',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'end_by_id'],
                    'permission_callback' => [$this, 'chat_id_check'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::CHAT_ID.'/rate',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'rate'],
                    'permission_callback' => [$this, 'chat_id_check'],
                    'args' => [
                        'rating' => [
                            'required' => true,
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 5,
                        ],
                        'comment' => [
                            'type' => ['string', 'null'],
                            'maxLength' => 1000,
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
                    'permission_callback' => [$this, 'chat_id_check'],
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

    /**
     * Permission for the id-keyed routes: the widget must be enabled, with a
     * per-IP limit sized for polling. The id itself is checked in the callback.
     *
     * @return bool|\WP_Error
     */
    public function chat_id_check()
    {
        return Widget_Controller::widget_guard('escalated_widget_chat_rate_', self::CHAT_RATE_LIMIT);
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
        // Every chat opens a guest ticket, so it honours the guest ticket
        // switch and the per-IP guest submission limit.
        $disabled = Widget_Controller::guest_tickets_disabled();
        if ($disabled) {
            return $disabled;
        }
        $limited = Widget_Controller::guest_limited('ticket');
        if ($limited) {
            return $limited;
        }

        $service = new ChatSessionService;

        try {
            $session = $service->start(
                $request->get_param('name') ?: 'Visitor',
                $request->get_param('email'),
                $request->get_param('message'),
                Widget_Controller::active_department_id($request->get_param('department_id')),
                WidgetMetadata::from_request($request)
            );
            $ticket = Ticket::find((int) $session->ticket_id);

            return $this->success([
                // The chat id is the ticket's random guest token, never the
                // sequential session id; the id-keyed routes resolve it.
                'id' => $ticket->guest_token ?? null,
                'session_id' => (int) $session->id,
                'ticket_id' => (int) $session->ticket_id,
                'status' => $session->status,
                'visitor_name' => $session->visitor_name,
                'guest_token' => $ticket->guest_token ?? null,
                'messages' => $ticket ? $this->chat_messages($session, $ticket) : [],
                'agent' => $this->agent_payload($session),
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
            $limited = Widget_Controller::guest_limited('reply');
            if ($limited) {
                return $limited;
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

        $wpdb = \Escalated\Escalated::db();
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
     * GET /chat/{id}/messages - the conversation so far.
     */
    public function list_messages(WP_REST_Request $request)
    {
        $resolved = $this->session_for_id((string) $request->get_param('id'));
        if ($resolved instanceof \WP_Error) {
            return $resolved;
        }
        [$session, $ticket] = $resolved;

        return $this->success([
            'messages' => $this->chat_messages($session, $ticket),
            'agent' => $this->agent_payload($session),
            // WordPress has no agent typing signal yet.
            'typing' => null,
            'ended' => $session->status === 'ended',
            'status' => $session->status,
        ]);
    }

    /**
     * POST /chat/{id}/messages {body} - a visitor message.
     */
    public function post_message(WP_REST_Request $request)
    {
        $resolved = $this->session_for_id((string) $request->get_param('id'));
        if ($resolved instanceof \WP_Error) {
            return $resolved;
        }
        [$session] = $resolved;

        if ($session->status === 'ended') {
            return $this->error('escalated_chat_ended', __('Chat has ended.', 'escalated'), 422);
        }

        $limited = Widget_Controller::guest_limited('reply');
        if ($limited) {
            return $limited;
        }

        $reply = (new ChatSessionService)->send_message((int) $session->id, (string) $request->get_param('body'), null, 'visitor');

        return $this->success([
            'message' => __('Message sent.', 'escalated'),
            'reply_id' => (int) $reply->id,
        ], 201);
    }

    /**
     * POST /chat/{id}/typing - the visitor is typing. Kept for a few seconds
     * in a transient and announced with the escalated_chat_visitor_typing action.
     */
    public function typing(WP_REST_Request $request)
    {
        $resolved = $this->session_for_id((string) $request->get_param('id'));
        if ($resolved instanceof \WP_Error) {
            return $resolved;
        }
        [$session] = $resolved;

        if ($session->status === 'ended') {
            return $this->error('escalated_chat_ended', __('Chat has ended.', 'escalated'), 422);
        }

        set_transient('escalated_chat_visitor_typing_'.(int) $session->id, time(), 5);
        do_action('escalated_chat_visitor_typing', $session);

        return $this->success(['message' => __('Typing updated.', 'escalated')]);
    }

    /**
     * POST /chat/{id}/end - the visitor ends the chat.
     */
    public function end_by_id(WP_REST_Request $request)
    {
        $resolved = $this->session_for_id((string) $request->get_param('id'));
        if ($resolved instanceof \WP_Error) {
            return $resolved;
        }
        [$session] = $resolved;

        if ($session->status === 'ended') {
            return $this->error('escalated_chat_ended', __('Chat has already ended.', 'escalated'), 422);
        }

        $session = (new ChatSessionService)->end((int) $session->id);

        return $this->success([
            'message' => __('Chat ended.', 'escalated'),
            'status' => $session->status,
            'ended_at' => $session->ended_at,
        ]);
    }

    /**
     * POST /chat/{id}/rate {rating, comment?} - rate an ended chat. The rating
     * is stored as the chat ticket's satisfaction rating.
     */
    public function rate(WP_REST_Request $request)
    {
        $resolved = $this->session_for_id((string) $request->get_param('id'));
        if ($resolved instanceof \WP_Error) {
            return $resolved;
        }
        [$session, $ticket] = $resolved;

        if ($session->status !== 'ended') {
            return $this->error('escalated_chat_not_ended', __('Chat must be ended before rating.', 'escalated'), 422);
        }

        if (SatisfactionRating::for_ticket((int) $ticket->id)) {
            return $this->error('escalated_chat_already_rated', __('This chat has already been rated.', 'escalated'), 422);
        }

        $created = SatisfactionRating::create([
            'ticket_id' => (int) $ticket->id,
            'rating' => (int) $request->get_param('rating'),
            'comment' => sanitize_textarea_field((string) $request->get_param('comment')),
            'rated_by' => null,
        ]);

        if (! $created) {
            return $this->error('escalated_chat_rating_failed', __('Could not save the rating.', 'escalated'), 500);
        }

        return $this->success(['message' => __('Rating submitted.', 'escalated')]);
    }

    /**
     * Resolve a chat id (the ticket's guest token) to its chat session.
     *
     * @return array{0: object, 1: object}|\WP_Error
     */
    private function session_for_id(string $id)
    {
        $ticket = $id !== '' ? Ticket::find_by_guest_token($id) : null;
        // The column's collation may compare case-insensitively.
        if (! $ticket || ! hash_equals((string) $ticket->guest_token, $id)) {
            return $this->error('escalated_not_found', __('Chat session not found.', 'escalated'), 404);
        }

        $session = ChatSession::find_by_ticket_id((int) $ticket->id);
        if (! $session) {
            return $this->error('escalated_not_found', __('Chat session not found.', 'escalated'), 404);
        }

        return [$session, $ticket];
    }

    /**
     * The visible conversation: the opening message, then public replies.
     *
     * @return list<array<string, mixed>>
     */
    private function chat_messages(object $session, object $ticket): array
    {
        $messages = [];
        $visitor = ['name' => (string) $session->visitor_name];

        if (trim(wp_strip_all_tags((string) $ticket->description)) !== '') {
            $messages[] = [
                'id' => 0,
                'body' => self::plain_text((string) $ticket->description),
                'is_agent' => false,
                'author' => $visitor,
                'created_at' => mysql2date('c', (string) $ticket->created_at, false),
            ];
        }

        $wpdb = \Escalated\Escalated::db();
        $replies_table = \Escalated\Escalated::table('replies');
        $replies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, author_id, body, created_at FROM {$replies_table} WHERE ticket_id = %d AND is_internal_note = 0 AND deleted_at IS NULL ORDER BY id DESC LIMIT 100",
                (int) $ticket->id
            )
        ) ?: [];

        foreach (array_reverse($replies) as $reply) {
            $author_id = (int) $reply->author_id;
            $is_agent = $author_id > 0;
            $user = $is_agent ? get_userdata($author_id) : null;
            $messages[] = [
                'id' => (int) $reply->id,
                'body' => self::plain_text((string) $reply->body),
                'is_agent' => $is_agent,
                'author' => $is_agent ? ['name' => $user ? $user->display_name : __('Agent', 'escalated')] : $visitor,
                'created_at' => mysql2date('c', (string) $reply->created_at, false),
            ];
        }

        return $messages;
    }

    /**
     * Message bodies as plain text: the widget renders them as text.
     */
    private static function plain_text(string $html): string
    {
        return html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array{name: string}|null
     */
    private function agent_payload(object $session): ?array
    {
        if (empty($session->agent_id)) {
            return null;
        }

        $user = get_userdata((int) $session->agent_id);

        return $user ? ['name' => $user->display_name] : null;
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
