<?php

/**
 * Ticket Controller - full CRUD and ticket operations.
 */

namespace Escalated\Api;

use Escalated\Helpers\Enums;
use Escalated\Helpers\Sanitizer;
use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;
use Escalated\Services\AssignmentService;
use Escalated\Services\AttachmentService;
use Escalated\Services\MacroService;
use Escalated\Services\TicketActionRegistry;
use Escalated\Services\TicketMergeService;
use Escalated\Services\TicketService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Ticket_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'tickets';

    /**
     * Regex pattern for ticket reference parameter.
     *
     * @var string
     */
    private const REF_PATTERN = '(?P<ref>[A-Z]+-\d+)';

    /**
     * Register all ticket routes.
     */
    public function register_routes(): void
    {
        // Unmerged tickets to merge into, by reference or subject.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/merge-search',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'merge_search'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'q' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                        'exclude' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    ],
                ],
            ]
        );

        // Merge this ticket into another: {target_reference}.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/merge',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'merge'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'target_reference' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    ],
                ],
            ]
        );

        // List tickets.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_items'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => $this->get_collection_params(),
                ],
            ]
        );

        // Create ticket.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_item'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => $this->get_create_params(),
                ],
            ]
        );

        // Show single ticket.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_item'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // Add reply.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/reply',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'add_reply'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'body' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => [Sanitizer::class, 'sanitize_html'],
                        ],
                        // escalated-laravel's agent API: a note posted to /reply.
                        'is_internal_note' => [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ],
                ],
            ]
        );

        // Add internal note.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/note',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'add_note'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'body' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => [Sanitizer::class, 'sanitize_html'],
                        ],
                    ],
                ],
            ]
        );

        // Change status.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/status',
            [
                [
                    // PATCH as escalated-laravel's API; POST kept for existing clients.
                    'methods' => 'POST, PATCH',
                    'callback' => [$this, 'change_status'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'status' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // Change priority.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/priority',
            [
                [
                    // PATCH as escalated-laravel's API; POST kept for existing clients.
                    'methods' => 'POST, PATCH',
                    'callback' => [$this, 'change_priority'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'priority' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // Assign agent.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/assign',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'assign_agent'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
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

        // Manage tags.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/tags',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'manage_tags'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'add' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'default' => [],
                        ],
                        'remove' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'default' => [],
                        ],
                        // escalated-laravel's shape: the ticket's full tag set.
                        'tag_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ]
        );

        // Toggle follow.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/follow',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'toggle_follow'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // Apply macro.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/macro',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'apply_macro'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'macro_id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // Trigger a custom (host-defined) ticket action.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN.'/actions/(?P<action>[A-Za-z0-9_-]+)',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'trigger_custom_action'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'action' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'payload' => [
                            'type' => 'object',
                            'default' => [],
                        ],
                    ],
                ],
            ]
        );

        // Soft delete.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::REF_PATTERN,
            [
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_item'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * List tickets with filters and pagination.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items($request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:read');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $filters = [];

        if ($request->has_param('status')) {
            $status = sanitize_text_field($request->get_param('status'));
            if (array_key_exists($status, Enums::ticket_statuses())) {
                $filters['status'] = $status;
            }
        }

        if ($request->has_param('priority')) {
            $priority = sanitize_text_field($request->get_param('priority'));
            if (array_key_exists($priority, Enums::ticket_priorities())) {
                $filters['priority'] = $priority;
            }
        }

        if ($request->has_param('assigned_to')) {
            $filters['assigned_to'] = absint($request->get_param('assigned_to'));
        }

        if ($request->has_param('unassigned')) {
            $filters['unassigned'] = rest_sanitize_boolean($request->get_param('unassigned'));
        }

        if ($request->has_param('department_id')) {
            $filters['department_id'] = absint($request->get_param('department_id'));
        }

        if ($request->has_param('search')) {
            $filters['search'] = sanitize_text_field($request->get_param('search'));
        }

        if ($request->has_param('sla_breached')) {
            $filters['sla_breached'] = rest_sanitize_boolean($request->get_param('sla_breached'));
        }

        if ($request->has_param('tag_ids')) {
            $tag_ids = $request->get_param('tag_ids');
            if (is_array($tag_ids)) {
                $filters['tag_ids'] = array_map('absint', $tag_ids);
            }
        }

        if ($request->has_param('requester_id')) {
            $filters['requester_id'] = absint($request->get_param('requester_id'));
        }

        if ($request->has_param('ticket_type')) {
            $ticket_type = sanitize_text_field($request->get_param('ticket_type'));
            if (in_array($ticket_type, ['question', 'problem', 'incident', 'task'], true)) {
                $filters['ticket_type'] = $ticket_type;
            }
        }

        // escalated-laravel's "following": only tickets the caller follows.
        if ($request->has_param('following')) {
            $filters['followed_by'] = $user_id;
        }

        if ($request->has_param('sort_by')) {
            $filters['sort_by'] = sanitize_text_field($request->get_param('sort_by'));
        }

        if ($request->has_param('sort_dir')) {
            $filters['sort_dir'] = sanitize_text_field($request->get_param('sort_dir'));
        }

        if ($request->has_param('per_page')) {
            $filters['per_page'] = min(absint($request->get_param('per_page')), 100);
        }

        if ($request->has_param('page')) {
            $filters['page'] = max(1, absint($request->get_param('page')));
        }

        foreach (['account_type', 'account_id'] as $field) {
            if ($request->has_param($field)) {
                $filters[$field] = $request->get_param($field);
            }
        }
        $result = Ticket::all($filters);

        return $this->success([
            'items' => Ticket::enrich_many($result['items']),
            'total' => $result['total'],
            'per_page' => $result['per_page'],
            'current_page' => $result['current_page'],
            'total_pages' => (int) ceil($result['total'] / max(1, $result['per_page'])),
        ]);
    }

    /**
     * Create a new ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function create_item($request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:create');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $subject = sanitize_text_field($request->get_param('subject'));
        if (empty($subject)) {
            return $this->error('escalated_missing_subject', __('Subject is required.', 'escalated'), 422);
        }

        $description = Sanitizer::sanitize_html($request->get_param('description'));
        if (empty($description)) {
            return $this->error('escalated_missing_description', __('Description is required.', 'escalated'), 422);
        }

        $priority = sanitize_text_field($request->get_param('priority')) ?: 'medium';
        if (! array_key_exists($priority, Enums::ticket_priorities())) {
            return $this->error('escalated_invalid_priority', __('Invalid priority value.', 'escalated'), 422);
        }

        $data = [
            'reference' => Ticket::generate_reference(),
            'requester_id' => $request->has_param('requester_id') ? absint($request->get_param('requester_id')) : $user_id,
            'subject' => $subject,
            'description' => $description,
            'status' => 'open',
            'priority' => $priority,
            'channel' => sanitize_text_field($request->get_param('channel')) ?: 'api',
        ];

        if ($request->has_param('department_id')) {
            $data['department_id'] = absint($request->get_param('department_id'));
        }

        if ($request->has_param('assigned_to')) {
            $data['assigned_to'] = absint($request->get_param('assigned_to'));
        }

        if ($request->has_param('ticket_type')) {
            $type = $request->get_param('ticket_type');
            if (! in_array($type, ['question', 'incident', 'problem', 'task'], true)) {
                return $this->error('escalated_invalid_type', __('Invalid ticket type.', 'escalated'), 422);
            }
            $data['ticket_type'] = $type;
        }
        try {
            $ticket_id = Ticket::create($data);
        } catch (\InvalidArgumentException $e) {
            return $this->error('escalated_ticket_access', $e->getMessage(), 403);
        }

        if ($ticket_id === false) {
            return $this->error('escalated_create_failed', __('Failed to create ticket.', 'escalated'), 500);
        }

        $ticket = Ticket::find($ticket_id);

        // Attach tags if provided.
        if ($request->has_param('tag_ids')) {
            $tag_ids = $request->get_param('tag_ids');
            if (is_array($tag_ids)) {
                foreach (array_map('absint', $tag_ids) as $tag_id) {
                    Tag::attach($ticket_id, $tag_id);
                }
            }
        }

        return $this->success([
            'message' => __('Ticket created successfully.', 'escalated'),
            'ticket' => Ticket::enrich($ticket),
        ], 201);
    }

    /**
     * Get a single ticket with replies, tags, and activities.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_item($request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:read');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ref = sanitize_text_field($request->get_param('ref'));
        $ticket = Ticket::find_by_reference($ref);

        if (! $ticket) {
            return $this->error('escalated_not_found', __('Ticket not found.', 'escalated'), 404);
        }

        $replies = Reply::for_ticket($ticket->id);
        $tags = Tag::for_ticket($ticket->id);
        $raw_activities = TicketActivity::for_ticket($ticket->id);

        // Add human-readable timestamps to activities.
        $activities = array_map(function ($activity) {
            if (! empty($activity->created_at)) {
                $activity->created_at_human = human_time_diff(
                    strtotime($activity->created_at),
                    current_time('timestamp')
                ).' '.__('ago', 'escalated');
            } else {
                $activity->created_at_human = null;
            }

            return $activity;
        }, $raw_activities);

        // Load attachments for the ticket and each reply.
        $attachment_service = new AttachmentService;
        $ticket_attachments = AttachmentService::format_many(
            $attachment_service->get_for('ticket', $ticket->id)
        );

        // Enrich replies with author info and attachments.
        foreach ($replies as &$reply) {
            if (! empty($reply->author_id)) {
                $author = get_userdata((int) $reply->author_id);
                $reply->author = $author ? [
                    'id' => $author->ID,
                    'display_name' => $author->display_name,
                    'email' => $author->user_email,
                ] : null;
            }
            $reply->attachments = AttachmentService::format_many(
                $attachment_service->get_for('reply', $reply->id)
            );
        }
        unset($reply);

        // Requester info.
        $requester = null;
        $requester_data = ! empty($ticket->requester_id) ? get_userdata((int) $ticket->requester_id) : null;
        if ($requester_data) {
            $requester = [
                'id' => $requester_data->ID,
                'display_name' => $requester_data->display_name,
                'email' => $requester_data->user_email,
            ];
        }

        // Assigned agent info.
        $assigned = null;
        $assigned_data = ! empty($ticket->assigned_to) ? get_userdata((int) $ticket->assigned_to) : null;
        if ($assigned_data) {
            $assigned = [
                'id' => $assigned_data->ID,
                'display_name' => $assigned_data->display_name,
                'email' => $assigned_data->user_email,
            ];
        }

        $enriched = Ticket::enrich($ticket);
        $enriched->custom_actions = $this->custom_actions_for($enriched, $user_id);

        return $this->success([
            'ticket' => $enriched,
            'requester' => $requester,
            'assigned' => $assigned,
            'replies' => $replies,
            'tags' => $tags,
            'activities' => $activities,
            'attachments' => $ticket_attachments,
        ]);
    }

    /**
     * Add a reply to a ticket, or an internal note when is_internal_note is
     * set (escalated-laravel's agent API takes notes on this endpoint).
     *
     * A note is stored as a note, fires note.created and is never sent to
     * the customer; only a public reply fires reply.created.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function add_reply(WP_REST_Request $request)
    {
        if (rest_sanitize_boolean($request->get_param('is_internal_note'))) {
            return $this->add_note($request);
        }

        $user_id = $this->check_token_permission($request, 'tickets:reply');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $body = Sanitizer::sanitize_html($request->get_param('body'));
        if (empty($body)) {
            return $this->error('escalated_missing_body', __('Reply body is required.', 'escalated'), 422);
        }

        $reply = (new TicketService)->reply((int) $ticket->id, $user_id, $body);

        // Bump the ticket's updated_at, as this endpoint always has.
        Ticket::update($ticket->id, []);

        return $this->success([
            'message' => __('Reply added successfully.', 'escalated'),
            'reply' => $reply,
            'data' => $this->reply_data($reply, $user_id),
        ], 201);
    }

    /**
     * Add an internal note to a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function add_note(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:note');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $body = Sanitizer::sanitize_html($request->get_param('body'));
        if (empty($body)) {
            return $this->error('escalated_missing_body', __('Note body is required.', 'escalated'), 422);
        }

        $note = (new TicketService)->add_note((int) $ticket->id, $user_id, $body);

        return $this->success([
            'message' => __('Internal note added successfully.', 'escalated'),
            'note' => $note,
            'reply' => $note,
            'data' => $this->reply_data($note, $user_id),
        ], 201);
    }

    /**
     * The reply shape escalated-laravel's API returns under "data".
     */
    private function reply_data(object $reply, int $user_id): array
    {
        $author = get_userdata($user_id);

        return [
            'id' => (int) $reply->id,
            'body' => $reply->body,
            'is_internal_note' => (bool) $reply->is_internal_note,
            'author' => ['id' => $user_id, 'name' => $author ? $author->display_name : ''],
            'created_at' => $reply->created_at,
        ];
    }

    /**
     * Change a ticket's status.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function change_status(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:update');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $new_status = sanitize_text_field($request->get_param('status'));

        if (! array_key_exists($new_status, Enums::ticket_statuses())) {
            return $this->error('escalated_invalid_status', __('Invalid status value.', 'escalated'), 422);
        }

        if (! Enums::can_transition($ticket->status, $new_status)) {
            return $this->error(
                'escalated_invalid_transition',
                sprintf(
                    /* translators: 1: current status, 2: requested status */
                    __('Cannot transition from "%1$s" to "%2$s".', 'escalated'),
                    $ticket->status,
                    $new_status
                ),
                422
            );
        }

        $update_data = ['status' => $new_status];

        // Track resolved/closed timestamps.
        if ($new_status === 'resolved' && empty($ticket->resolved_at)) {
            $update_data['resolved_at'] = current_time('mysql');
        }

        if ($new_status === 'closed' && empty($ticket->closed_at)) {
            $update_data['closed_at'] = current_time('mysql');
        }

        Ticket::update($ticket->id, $update_data);

        // Log activity.
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'causer_id' => $user_id,
            'type' => 'status_changed',
            'properties' => [
                'from' => $ticket->status,
                'to' => $new_status,
            ],
        ]);

        $updated_ticket = Ticket::find($ticket->id);

        return $this->success([
            'message' => __('Status updated successfully.', 'escalated'),
            'status' => $new_status,
            'ticket' => Ticket::enrich($updated_ticket),
        ]);
    }

    /**
     * Change a ticket's priority.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function change_priority(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:update');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $new_priority = sanitize_text_field($request->get_param('priority'));

        if (! array_key_exists($new_priority, Enums::ticket_priorities())) {
            return $this->error('escalated_invalid_priority', __('Invalid priority value.', 'escalated'), 422);
        }

        $old_priority = $ticket->priority;
        Ticket::update($ticket->id, ['priority' => $new_priority]);

        // Log activity.
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'causer_id' => $user_id,
            'type' => 'priority_changed',
            'properties' => [
                'from' => $old_priority,
                'to' => $new_priority,
            ],
        ]);

        $updated_ticket = Ticket::find($ticket->id);

        return $this->success([
            'message' => __('Priority updated successfully.', 'escalated'),
            'priority' => $new_priority,
            'ticket' => Ticket::enrich($updated_ticket),
        ]);
    }

    /**
     * Assign an agent to a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function assign_agent(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:assign');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $agent_id = absint($request->get_param('agent_id'));

        // Validate agent exists.
        $agent = get_userdata($agent_id);
        if (! $agent) {
            return $this->error('escalated_invalid_agent', __('Agent not found.', 'escalated'), 404);
        }

        // Verify user has an agent or admin role.
        $valid_roles = array_intersect($agent->roles, ['escalated_agent', 'escalated_admin', 'administrator']);
        if (empty($valid_roles)) {
            return $this->error('escalated_invalid_agent_role', __('User is not an agent.', 'escalated'), 422);
        }

        try {
            (new AssignmentService)->assign((int) $ticket->id, $agent_id, $user_id);
        } catch (\InvalidArgumentException $e) {
            return $this->error('escalated_invalid_assignment', $e->getMessage(), 422);
        }

        $updated_ticket = Ticket::find($ticket->id);

        return $this->success([
            'message' => __('Ticket assigned successfully.', 'escalated'),
            'ticket' => Ticket::enrich($updated_ticket),
        ]);
    }

    /**
     * Add and/or remove tags from a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function manage_tags(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:update');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $add_ids = $request->get_param('add') ?: [];
        $remove_ids = $request->get_param('remove') ?: [];

        // escalated-laravel's shape: tag_ids is the ticket's full tag set.
        $tag_ids = $request->get_param('tag_ids');
        if (is_array($tag_ids)) {
            $wanted = array_values(array_unique(array_map('absint', $tag_ids)));
            foreach ($wanted as $tag_id) {
                if (! Tag::find($tag_id)) {
                    return $this->error('escalated_invalid_tag', __('Tag not found.', 'escalated'), 422);
                }
            }
            $current = array_map(static fn ($tag) => (int) $tag->id, Tag::for_ticket($ticket->id));
            $add_ids = array_merge((array) $add_ids, array_diff($wanted, $current));
            $remove_ids = array_merge((array) $remove_ids, array_diff($current, $wanted));
        }

        if (! is_array($add_ids)) {
            $add_ids = [];
        }
        if (! is_array($remove_ids)) {
            $remove_ids = [];
        }

        // Add tags.
        foreach (array_map('absint', $add_ids) as $tag_id) {
            if ($tag_id > 0) {
                $tag = Tag::find($tag_id);
                if ($tag) {
                    Tag::attach($ticket->id, $tag_id);

                    TicketActivity::create([
                        'ticket_id' => $ticket->id,
                        'causer_id' => $user_id,
                        'type' => 'tag_added',
                        'properties' => ['tag_id' => $tag_id, 'tag_name' => $tag->name],
                    ]);
                }
            }
        }

        // Remove tags.
        foreach (array_map('absint', $remove_ids) as $tag_id) {
            if ($tag_id > 0) {
                $tag = Tag::find($tag_id);
                if ($tag) {
                    Tag::detach($ticket->id, $tag_id);

                    TicketActivity::create([
                        'ticket_id' => $ticket->id,
                        'causer_id' => $user_id,
                        'type' => 'tag_removed',
                        'properties' => ['tag_id' => $tag_id, 'tag_name' => $tag->name],
                    ]);
                }
            }
        }

        $tags = Tag::for_ticket($ticket->id);

        return $this->success([
            'message' => __('Tags updated successfully.', 'escalated'),
            'tags' => $tags,
        ]);
    }

    /**
     * Toggle follow/unfollow on a ticket for the authenticated user.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function toggle_follow(WP_REST_Request $request)
    {
        $wpdb = \Escalated\Escalated::db();

        $user_id = $this->check_token_permission($request, 'tickets:read');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $table = \Escalated\Escalated::table('ticket_followers');

        // Check current follow status.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d AND user_id = %d",
                $ticket->id,
                $user_id
            )
        );

        if ($exists) {
            // Unfollow.
            $wpdb->delete($table, [
                'ticket_id' => $ticket->id,
                'user_id' => $user_id,
            ]);

            return $this->success([
                'message' => __('Unfollowed ticket.', 'escalated'),
                'following' => false,
            ]);
        }

        // Follow.
        $wpdb->insert($table, [
            'ticket_id' => $ticket->id,
            'user_id' => $user_id,
            'created_at' => current_time('mysql'),
        ]);

        return $this->success([
            'message' => __('Following ticket.', 'escalated'),
            'following' => true,
        ]);
    }

    /**
     * Apply a macro to a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function apply_macro(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:update');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $macro_id = absint($request->get_param('macro_id'));

        if (! $macro_id) {
            return $this->error('escalated_missing_macro', __('Macro ID is required.', 'escalated'), 422);
        }

        $result = MacroService::apply($ticket->id, $macro_id, $user_id);

        if (is_wp_error($result)) {
            return $result;
        }

        $updated_ticket = Ticket::find($ticket->id);

        return $this->success([
            'message' => __('Macro applied successfully.', 'escalated'),
            'ticket' => Ticket::enrich($updated_ticket),
        ]);
    }

    /**
     * Trigger a host-defined custom ticket action.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function trigger_custom_action(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:update');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $action_key = sanitize_text_field($request->get_param('action'));
        $action = TicketActionRegistry::find($action_key);

        if ($action === null || ! TicketActionRegistry::is_visible($action, $ticket, $user_id)) {
            return $this->error('escalated_action_not_found', __('Custom action not found.', 'escalated'), 404);
        }

        if (! TicketActionRegistry::is_enabled($action, $ticket, $user_id)) {
            return $this->error('escalated_action_disabled', __('Custom action is not enabled.', 'escalated'), 403);
        }

        $payload = (array) $request->get_param('payload');
        $metadata = TicketActionRegistry::metadata($action, $ticket, $user_id);

        /**
         * Fires when an agent triggers a custom ticket action. Host plugins
         * hook this to do their own work (CRM sync, etc.).
         *
         * @param  object  $ticket  The ticket.
         * @param  string  $action_key  The triggered action key.
         * @param  int  $user_id  The agent who triggered it.
         * @param  array  $payload  Optional payload from the request.
         * @param  array  $metadata  Action metadata.
         */
        do_action('escalated_ticket_action_triggered', $ticket, $action_key, $user_id, $payload, $metadata);

        return $this->success([
            'message' => __('Custom action dispatched.', 'escalated'),
            'action' => $action_key,
        ]);
    }

    /**
     * Serialize the visible custom actions for a ticket, adding url + method.
     *
     * @return array<int, array<string, mixed>>
     */
    private function custom_actions_for(object $ticket, int $user_id): array
    {
        return array_map(function (array $action) use ($ticket) {
            $action['url'] = rest_url($this->namespace.'/'.$this->rest_base.'/'.$ticket->reference.'/actions/'.$action['key']);
            $action['method'] = 'post';

            return $action;
        }, TicketActionRegistry::visible_for($ticket, $user_id));
    }

    /**
     * Soft delete a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function delete_item($request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:delete');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $deleted = Ticket::delete($ticket->id);

        if (! $deleted) {
            return $this->error('escalated_delete_failed', __('Failed to delete ticket.', 'escalated'), 500);
        }

        // Log activity.
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'causer_id' => $user_id,
            'type' => 'closed',
            'properties' => ['action' => 'soft_deleted'],
        ]);

        return $this->success([
            'message' => __('Ticket deleted successfully.', 'escalated'),
        ]);
    }

    /**
     * Resolve a ticket from the request reference parameter.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return object|\WP_Error The ticket object or WP_Error if not found.
     */
    /**
     * GET /tickets/merge-search?q=: unmerged tickets the caller can see.
     */
    public function merge_search(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:read');
        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $exclude = (string) $request->get_param('exclude');
        $excluded = $exclude !== '' ? Ticket::find_by_reference($exclude) : null;

        return $this->success([
            'tickets' => (new TicketMergeService)->search((string) $request->get_param('q'), $excluded ? (int) $excluded->id : null),
        ]);
    }

    /**
     * POST /tickets/{ref}/merge: merge this ticket into target_reference.
     * Needs tickets:update and the ticket.merge permission.
     */
    public function merge(WP_REST_Request $request)
    {
        $user_id = $this->check_token_permission($request, 'tickets:update');
        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }
        if (! user_can($user_id, 'escalated_ticket_merge')) {
            return $this->error('escalated_forbidden', __('You do not have permission to merge tickets.', 'escalated'), 403);
        }

        $source = $this->resolve_ticket($request);
        if (is_wp_error($source)) {
            return $source;
        }

        $target = Ticket::find_by_reference(strtoupper(trim((string) $request->get_param('target_reference'))));
        if (! $target || ! empty($target->merged_into_id)) {
            return $this->error('escalated_not_found', __('Target ticket not found.', 'escalated'), 404);
        }

        try {
            $target = (new TicketMergeService)->merge($source, $target, $user_id);
        } catch (\InvalidArgumentException $e) {
            return $this->error('escalated_merge_refused', $e->getMessage(), 422);
        }

        return $this->success([
            'message' => sprintf(
                /* translators: 1: source reference, 2: target reference */
                __('%1$s was merged into %2$s.', 'escalated'),
                $source->reference,
                $target->reference
            ),
            'ticket' => Ticket::enrich($target),
        ]);
    }

    private function resolve_ticket(WP_REST_Request $request)
    {
        $ref = sanitize_text_field($request->get_param('ref'));
        $ticket = Ticket::find_by_reference($ref);

        if (! $ticket) {
            return $this->error('escalated_not_found', __('Ticket not found.', 'escalated'), 404);
        }

        return $ticket;
    }

    /**
     * Get the query params for collections.
     */
    public function get_collection_params(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'priority' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'assigned_to' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'unassigned' => [
                'type' => 'boolean',
            ],
            'department_id' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sla_breached' => [
                'type' => 'boolean',
            ],
            'tag_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
            'requester_id' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'sort_by' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sort_dir' => [
                'type' => 'string',
                'enum' => ['ASC', 'DESC', 'asc', 'desc'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'per_page' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => 20,
            ],
            'page' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => 1,
            ],
        ];
    }

    /**
     * Get the params for ticket creation.
     */
    private function get_create_params(): array
    {
        return [
            'subject' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => [Sanitizer::class, 'sanitize_html'],
            ],
            'priority' => [
                'type' => 'string',
                'default' => 'medium',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'department_id' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'assigned_to' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'requester_id' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'channel' => [
                'type' => 'string',
                'default' => 'api',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'tag_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
        ];
    }
}
