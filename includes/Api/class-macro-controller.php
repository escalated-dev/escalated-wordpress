<?php

/**
 * Macro Controller - list macros for the authenticated agent.
 */

namespace Escalated\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Macro_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'macros';

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
                    'callback' => [$this, 'get_items'],
                    'permission_callback' => [$this, 'token_permissions_check'],
                    'args' => [
                        'search' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * List macros visible to the authenticated agent.
     *
     * Returns shared macros and those created by the current user.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items($request)
    {
        global $wpdb;

        $user_id = $this->check_token_permission($request, 'macros:read');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $table = \Escalated\Escalated::table('macros');
        $where = ['(is_shared = 1 OR created_by = %d)'];
        $values = [$user_id];

        // Search filter.
        if ($request->has_param('search')) {
            $search = sanitize_text_field($request->get_param('search'));
            if (! empty($search)) {
                $like = '%'.$wpdb->esc_like($search).'%';
                $where[] = '(name LIKE %s OR description LIKE %s)';
                $values[] = $like;
                $values[] = $like;
            }
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY sort_order ASC, name ASC";
        $macros = $wpdb->get_results($wpdb->prepare($sql, $values)) ?: [];

        $result = [];
        foreach ($macros as $macro) {
            $actions = $macro->actions;
            if (is_string($actions)) {
                $decoded = json_decode($actions, true);
                $actions = is_array($decoded) ? $decoded : [];
            }

            $result[] = [
                'id' => (int) $macro->id,
                'name' => $macro->name,
                'description' => $macro->description,
                'actions' => $actions,
                'is_shared' => (bool) $macro->is_shared,
                'created_by' => (int) $macro->created_by,
                'sort_order' => (int) $macro->sort_order,
                'created_at' => $macro->created_at,
                'updated_at' => $macro->updated_at,
            ];
        }

        return $this->success([
            'macros' => $result,
        ]);
    }
}
