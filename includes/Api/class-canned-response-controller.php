<?php

/**
 * Canned Response Controller - list canned responses for the authenticated agent.
 */

namespace Escalated\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Canned_Response_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'canned-responses';

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
                        'category' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
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
     * List canned responses visible to the authenticated agent.
     *
     * Returns shared responses and those created by the current user.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items($request)
    {
        global $wpdb;

        $user_id = $this->check_token_permission($request, 'canned_responses:read');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $table = \Escalated\Escalated::table('canned_responses');
        $where = ['(is_shared = 1 OR created_by = %d)'];
        $values = [$user_id];

        // Category filter.
        if ($request->has_param('category')) {
            $category = sanitize_text_field($request->get_param('category'));
            if (! empty($category)) {
                $where[] = 'category = %s';
                $values[] = $category;
            }
        }

        // Search filter.
        if ($request->has_param('search')) {
            $search = sanitize_text_field($request->get_param('search'));
            if (! empty($search)) {
                $like = '%'.$wpdb->esc_like($search).'%';
                $where[] = '(title LIKE %s OR body LIKE %s)';
                $values[] = $like;
                $values[] = $like;
            }
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY title ASC";
        $responses = $wpdb->get_results($wpdb->prepare($sql, $values)) ?: [];

        $result = [];
        foreach ($responses as $response) {
            $result[] = [
                'id' => (int) $response->id,
                'title' => $response->title,
                'body' => $response->body,
                'category' => $response->category,
                'is_shared' => (bool) $response->is_shared,
                'created_by' => (int) $response->created_by,
                'created_at' => $response->created_at,
                'updated_at' => $response->updated_at,
            ];
        }

        return $this->success([
            'canned_responses' => $result,
        ]);
    }
}
