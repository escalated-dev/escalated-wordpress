<?php

/**
 * Saved View Controller - CRUD operations for saved views / custom queues.
 */

namespace Escalated\Api;

use Escalated\Models\SavedView;
use WP_REST_Server;

class Saved_View_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'views';

    /**
     * Regex pattern for view ID parameter.
     *
     * @var string
     */
    private const ID_PATTERN = '(?P<id>[\d]+)';

    /**
     * Register saved view routes.
     */
    public function register_routes(): void
    {
        // List views for the current user.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_items'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                ],
            ]
        );

        // Create a new view.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base,
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_item'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'name' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'filters' => [
                            'required' => true,
                            'type' => 'object',
                        ],
                        'is_shared' => [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                        'position' => [
                            'type' => 'integer',
                            'default' => 0,
                        ],
                    ],
                ],
            ]
        );

        // Update a view.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::ID_PATTERN,
            [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'update_item'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'name' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'filters' => [
                            'type' => 'object',
                        ],
                        'is_shared' => [
                            'type' => 'boolean',
                        ],
                        'position' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
            ]
        );

        // Delete a view.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/'.self::ID_PATTERN,
            [
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_item'],
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
     * List saved views for the current user.
     */
    public function get_items($request)
    {
        $user_id = $this->check_token_permission($request);
        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        SavedView::ensure_table();
        $views = SavedView::for_user($user_id);

        // Decode filters JSON for each view.
        foreach ($views as $view) {
            $view->filters = json_decode($view->filters, true) ?: [];
        }

        return $this->success($views);
    }

    /**
     * Create a new saved view.
     */
    public function create_item($request)
    {
        $user_id = $this->check_token_permission($request);
        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        SavedView::ensure_table();

        $id = SavedView::create([
            'name' => $request->get_param('name'),
            'filters' => wp_json_encode($request->get_param('filters')),
            'user_id' => $user_id,
            'is_shared' => $request->get_param('is_shared') ? 1 : 0,
            'position' => (int) $request->get_param('position'),
        ]);

        if (! $id) {
            return $this->error('escalated_create_failed', __('Failed to create view.', 'escalated'), 500);
        }

        $view = SavedView::find($id);
        $view->filters = json_decode($view->filters, true) ?: [];

        return $this->success($view, 201);
    }

    /**
     * Update a saved view.
     */
    public function update_item($request)
    {
        $user_id = $this->check_token_permission($request);
        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        SavedView::ensure_table();

        $view_id = (int) $request->get_param('id');
        $view = SavedView::find($view_id);

        if (! $view) {
            return $this->error('escalated_not_found', __('View not found.', 'escalated'), 404);
        }

        // Only the owner can update their view.
        if ((int) $view->user_id !== $user_id) {
            return $this->error('escalated_forbidden', __('You can only edit your own views.', 'escalated'), 403);
        }

        $update = [];
        if ($request->has_param('name')) {
            $update['name'] = $request->get_param('name');
        }
        if ($request->has_param('filters')) {
            $update['filters'] = wp_json_encode($request->get_param('filters'));
        }
        if ($request->has_param('is_shared')) {
            $update['is_shared'] = $request->get_param('is_shared') ? 1 : 0;
        }
        if ($request->has_param('position')) {
            $update['position'] = (int) $request->get_param('position');
        }

        SavedView::update($view_id, $update);
        $updated = SavedView::find($view_id);
        $updated->filters = json_decode($updated->filters, true) ?: [];

        return $this->success($updated);
    }

    /**
     * Delete a saved view.
     */
    public function delete_item($request)
    {
        $user_id = $this->check_token_permission($request);
        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        SavedView::ensure_table();

        $view_id = (int) $request->get_param('id');
        $view = SavedView::find($view_id);

        if (! $view) {
            return $this->error('escalated_not_found', __('View not found.', 'escalated'), 404);
        }

        if ((int) $view->user_id !== $user_id) {
            return $this->error('escalated_forbidden', __('You can only delete your own views.', 'escalated'), 403);
        }

        SavedView::delete($view_id);

        return $this->success(['message' => __('View deleted.', 'escalated')]);
    }
}
