<?php

/**
 * Department Controller - list active departments.
 */

namespace Escalated\Api;

use Escalated\Models\Department;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Department_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'departments';

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
                    'args' => [],
                ],
            ]
        );
    }

    /**
     * List all active departments.
     *
     * @param  WP_REST_Request  $request  The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items($request)
    {
        $user_id = $this->check_token_permission($request, 'departments:read');

        if ($user_id === null) {
            return $this->error('escalated_unauthorized', __('Unauthorized.', 'escalated'), 401);
        }

        $departments = Department::active();

        // Enrich with agent count for each department.
        $result = [];
        foreach ($departments as $dept) {
            $agents = Department::agents($dept->id);
            $result[] = [
                'id' => (int) $dept->id,
                'name' => $dept->name,
                'slug' => $dept->slug,
                'description' => $dept->description,
                'is_active' => (bool) $dept->is_active,
                'agent_count' => count($agents),
                'created_at' => $dept->created_at,
                'updated_at' => $dept->updated_at,
            ];
        }

        return $this->success([
            'departments' => $result,
        ]);
    }
}
