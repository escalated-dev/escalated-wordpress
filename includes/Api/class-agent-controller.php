<?php
/**
 * Agent Controller - list users with escalated agent or admin roles.
 *
 * @package Escalated\Api
 */

namespace Escalated\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User_Query;

class Agent_Controller extends Base_Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'agents';

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'token_permissions_check' ],
                    'args'                => [
                        'search' => [
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * List all users with escalated_agent or escalated_admin role.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items( $request ) {
        $user_id = $this->check_token_permission( $request, 'agents:read' );

        if ( null === $user_id ) {
            return $this->error( 'escalated_unauthorized', __( 'Unauthorized.', 'escalated' ), 401 );
        }

        // Query users with escalated_agent role.
        $agent_query = new WP_User_Query( [
            'role'    => 'escalated_agent',
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => 'all',
        ] );

        // Query users with escalated_admin role.
        $admin_query = new WP_User_Query( [
            'role'    => 'escalated_admin',
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => 'all',
        ] );

        // Merge results, avoiding duplicates.
        $agents_map = [];

        $all_results = array_merge(
            $agent_query->get_results() ?: [],
            $admin_query->get_results() ?: []
        );

        foreach ( $all_results as $user ) {
            if ( ! isset( $agents_map[ $user->ID ] ) ) {
                $agents_map[ $user->ID ] = [
                    'id'           => $user->ID,
                    'display_name' => $user->display_name,
                    'email'        => $user->user_email,
                    'roles'        => $user->roles,
                ];
            }
        }

        $agents = array_values( $agents_map );

        // Optional search filter (client-side filter for simplicity).
        if ( $request->has_param( 'search' ) ) {
            $search = strtolower( sanitize_text_field( $request->get_param( 'search' ) ) );
            if ( ! empty( $search ) ) {
                $agents = array_values( array_filter( $agents, function ( $agent ) use ( $search ) {
                    return str_contains( strtolower( $agent['display_name'] ), $search )
                        || str_contains( strtolower( $agent['email'] ), $search );
                } ) );
            }
        }

        // Sort by display_name.
        usort( $agents, function ( $a, $b ) {
            return strcasecmp( $a['display_name'], $b['display_name'] );
        } );

        return $this->success( [
            'agents' => $agents,
        ] );
    }
}
