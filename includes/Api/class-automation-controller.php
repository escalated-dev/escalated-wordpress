<?php
/**
 * Automation Controller - CRUD endpoints for automations.
 *
 * @package Escalated\Api
 */

namespace Escalated\Api;

use Escalated\Models\Automation;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Automation_Controller extends Base_Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'automations';

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        // List / Create
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'token_permissions_check' ],
                    'args'                => [
                        'active' => [
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_item' ],
                    'permission_callback' => [ $this, 'token_permissions_check' ],
                    'args'                => [
                        'name' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'conditions' => [
                            'required' => true,
                            'type'     => 'array',
                        ],
                        'actions' => [
                            'required' => true,
                            'type'     => 'array',
                        ],
                    ],
                ],
            ]
        );

        // Get / Update / Delete
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_item' ],
                    'permission_callback' => [ $this, 'token_permissions_check' ],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_item' ],
                    'permission_callback' => [ $this, 'token_permissions_check' ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_item' ],
                    'permission_callback' => [ $this, 'token_permissions_check' ],
                ],
            ]
        );
    }

    /**
     * List automations.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items( $request ) {
        $user_id = $this->check_token_permission( $request, 'automations:read' );

        if ( null === $user_id ) {
            return $this->error( 'escalated_unauthorized', __( 'Unauthorized.', 'escalated' ), 401 );
        }

        $filters = [];
        if ( $request->has_param( 'active' ) ) {
            $filters['active'] = (int) $request->get_param( 'active' );
        }

        $automations = Automation::all( $filters );

        return $this->success( [
            'automations' => array_map( [ $this, 'format_automation' ], $automations ),
        ] );
    }

    /**
     * Get a single automation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function get_item( $request ) {
        $user_id = $this->check_token_permission( $request, 'automations:read' );

        if ( null === $user_id ) {
            return $this->error( 'escalated_unauthorized', __( 'Unauthorized.', 'escalated' ), 401 );
        }

        $automation = Automation::find( (int) $request->get_param( 'id' ) );

        if ( ! $automation ) {
            return $this->error( 'escalated_not_found', __( 'Automation not found.', 'escalated' ), 404 );
        }

        return $this->success( $this->format_automation( $automation ) );
    }

    /**
     * Create a new automation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function create_item( $request ) {
        $user_id = $this->check_token_permission( $request, 'automations:write' );

        if ( null === $user_id ) {
            return $this->error( 'escalated_unauthorized', __( 'Unauthorized.', 'escalated' ), 401 );
        }

        $data = [
            'name'       => sanitize_text_field( $request->get_param( 'name' ) ),
            'conditions' => $request->get_param( 'conditions' ),
            'actions'    => $request->get_param( 'actions' ),
            'active'     => $request->has_param( 'active' ) ? (int) $request->get_param( 'active' ) : 1,
            'position'   => $request->has_param( 'position' ) ? absint( $request->get_param( 'position' ) ) : 0,
        ];

        $id = Automation::create( $data );

        if ( ! $id ) {
            return $this->error( 'escalated_create_failed', __( 'Failed to create automation.', 'escalated' ), 500 );
        }

        $automation = Automation::find( $id );

        return $this->success( $this->format_automation( $automation ), 201 );
    }

    /**
     * Update an automation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function update_item( $request ) {
        $user_id = $this->check_token_permission( $request, 'automations:write' );

        if ( null === $user_id ) {
            return $this->error( 'escalated_unauthorized', __( 'Unauthorized.', 'escalated' ), 401 );
        }

        $id         = (int) $request->get_param( 'id' );
        $automation = Automation::find( $id );

        if ( ! $automation ) {
            return $this->error( 'escalated_not_found', __( 'Automation not found.', 'escalated' ), 404 );
        }

        $data = [];

        if ( $request->has_param( 'name' ) ) {
            $data['name'] = sanitize_text_field( $request->get_param( 'name' ) );
        }
        if ( $request->has_param( 'conditions' ) ) {
            $data['conditions'] = $request->get_param( 'conditions' );
        }
        if ( $request->has_param( 'actions' ) ) {
            $data['actions'] = $request->get_param( 'actions' );
        }
        if ( $request->has_param( 'active' ) ) {
            $data['active'] = (int) $request->get_param( 'active' );
        }
        if ( $request->has_param( 'position' ) ) {
            $data['position'] = absint( $request->get_param( 'position' ) );
        }

        Automation::update( $id, $data );

        $automation = Automation::find( $id );

        return $this->success( $this->format_automation( $automation ) );
    }

    /**
     * Delete an automation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|\WP_Error
     */
    public function delete_item( $request ) {
        $user_id = $this->check_token_permission( $request, 'automations:write' );

        if ( null === $user_id ) {
            return $this->error( 'escalated_unauthorized', __( 'Unauthorized.', 'escalated' ), 401 );
        }

        $id         = (int) $request->get_param( 'id' );
        $automation = Automation::find( $id );

        if ( ! $automation ) {
            return $this->error( 'escalated_not_found', __( 'Automation not found.', 'escalated' ), 404 );
        }

        Automation::delete( $id );

        return $this->success( [ 'deleted' => true ] );
    }

    /**
     * Format an automation object for API response.
     *
     * @param object $automation
     * @return array
     */
    protected function format_automation( object $automation ): array {
        $conditions = $automation->conditions;
        if ( is_string( $conditions ) ) {
            $decoded    = json_decode( $conditions, true );
            $conditions = is_array( $decoded ) ? $decoded : [];
        }

        $actions = $automation->actions;
        if ( is_string( $actions ) ) {
            $decoded = json_decode( $actions, true );
            $actions = is_array( $decoded ) ? $decoded : [];
        }

        return [
            'id'          => (int) $automation->id,
            'name'        => $automation->name,
            'conditions'  => $conditions,
            'actions'     => $actions,
            'active'      => (bool) $automation->active,
            'position'    => (int) $automation->position,
            'last_run_at' => $automation->last_run_at,
            'created_at'  => $automation->created_at,
            'updated_at'  => $automation->updated_at,
        ];
    }
}
