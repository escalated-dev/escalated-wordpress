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
                    'methods' => WP_REST_Server::CREATABLE,
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
                    'methods' => WP_REST_Server::CREATABLE,
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

        $result = Ticket::all($filters);

        return $this->success([
            'items' => $result['items'],
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

        $ticket_id = Ticket::create($data);

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
            'ticket' => $ticket,
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
        $activities = TicketActivity::for_ticket($ticket->id);

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

        return $this->success([
            'ticket' => $ticket,
            'requester' => $requester,
            'assigned' => $assigned,
            'replies' => $replies,
            'tags' => $tags,
            'activities' => $activities,
            'attachments' => $ticket_attachments,
        ]);
    }

    /**
     * Add a public reply to a ticket.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function add_reply(WP_REST_Request $request)
    {
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

        $reply_id = Reply::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user_id,
            'body' => $body,
            'is_internal_note' => 0,
            'type' => 'reply',
        ]);

        if ($reply_id === false) {
            return $this->error('escalated_reply_failed', __('Failed to create reply.', 'escalated'), 500);
        }

        // Log activity.
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'causer_id' => $user_id,
            'type' => 'replied',
            'properties' => ['reply_id' => $reply_id],
        ]);

        // Update ticket timestamp.
        Ticket::update($ticket->id, []);

        // Set first response if not already set.
        if (empty($ticket->first_response_at)) {
            Ticket::update($ticket->id, ['first_response_at' => current_time('mysql')]);
        }

        $reply = Reply::find($reply_id);

        return $this->success([
            'message' => __('Reply added successfully.', 'escalated'),
            'reply' => $reply,
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

        $note_id = Reply::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user_id,
            'body' => $body,
            'is_internal_note' => 1,
            'type' => 'note',
        ]);

        if ($note_id === false) {
            return $this->error('escalated_note_failed', __('Failed to create note.', 'escalated'), 500);
        }

        // Log activity.
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'causer_id' => $user_id,
            'type' => 'note_added',
            'properties' => ['reply_id' => $note_id],
        ]);

        $note = Reply::find($note_id);

        return $this->success([
            'message' => __('Internal note added successfully.', 'escalated'),
            'note' => $note,
        ], 201);
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
            'ticket' => $updated_ticket,
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
            'ticket' => $updated_ticket,
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

        $old_assigned = $ticket->assigned_to;
        AssignmentService::assign($ticket->id, $agent_id, $user_id);

        $updated_ticket = Ticket::find($ticket->id);

        return $this->success([
            'message' => __('Ticket assigned successfully.', 'escalated'),
            'ticket' => $updated_ticket,
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
        global $wpdb;

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
            'ticket' => $updated_ticket,
        ]);
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
