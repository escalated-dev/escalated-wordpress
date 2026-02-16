<?php
/**
 * Tag Controller - list all tags.
 *
 * @package Escalated\Api
 */

namespace Escalated\Api;

use Escalated\Models\Tag;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Tag_Controller extends Base_Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'tags';

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
     * List all tags, optionally filtered by search.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response|\WP_Error
     */
    public function get_items( $request ) {
        $user_id = $this->check_token_permission( $request, 'tags:read' );

        if ( null === $user_id ) {
            return $this->error( 'escalated_unauthorized', __( 'Unauthorized.', 'escalated' ), 401 );
        }

        $filters = [];

        if ( $request->has_param( 'search' ) ) {
            $filters['search'] = sanitize_text_field( $request->get_param( 'search' ) );
        }

        $tags = Tag::all( $filters );

        $result = [];
        foreach ( $tags as $tag ) {
            $result[] = [
                'id'         => (int) $tag->id,
                'name'       => $tag->name,
                'slug'       => $tag->slug,
                'color'      => $tag->color,
                'created_at' => $tag->created_at,
                'updated_at' => $tag->updated_at,
            ];
        }

        return $this->success( [
            'tags' => $result,
        ] );
    }
}
