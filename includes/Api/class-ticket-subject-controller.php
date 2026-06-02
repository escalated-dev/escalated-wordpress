<?php

/**
 * Admin REST — attach/detach ticket subjects (host entities a ticket is about).
 */

namespace Escalated\Api;

use Escalated\Models\Ticket;
use Escalated\Services\TicketSubjectService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Ticket_Subject_Controller extends Base_Controller
{
    private const REF_PATTERN = '(?P<ref>[A-Z]+-\d+)';

    public function register_routes(): void
    {
        $perm = [$this, 'admin_permissions_check'];

        register_rest_route(
            $this->namespace,
            '/admin/tickets/'.self::REF_PATTERN.'/subjects',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'attach'],
                    'permission_callback' => $perm,
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'type' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'id' => [
                            'required' => true,
                        ],
                        'role' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/admin/tickets/'.self::REF_PATTERN.'/subjects/(?P<link_id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'detach'],
                    'permission_callback' => $perm,
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'link_id' => [
                            'required' => true,
                            'type' => 'integer',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @return bool|WP_Error
     */
    public function admin_permissions_check()
    {
        if (! is_user_logged_in()) {
            return new WP_Error(
                'escalated_unauthorized',
                __('You must be logged in.', 'escalated'),
                ['status' => 401]
            );
        }

        if (! current_user_can('escalated_ticket_edit')) {
            return new WP_Error(
                'escalated_forbidden',
                __('You do not have permission to edit tickets.', 'escalated'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function attach(WP_REST_Request $request)
    {
        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $type = (string) $request->get_param('type');
        $id = (string) $request->get_param('id');
        $role = $request->get_param('role');
        $role = is_string($role) && $role !== '' ? $role : null;

        try {
            TicketSubjectService::attach_for_api((int) $ticket->id, $type, $id, $role);
        } catch (\InvalidArgumentException $e) {
            return $this->error('escalated_subject_invalid', $e->getMessage(), 422);
        }

        $enriched = Ticket::enrich($ticket);

        return $this->success([
            'message' => __('Subject attached.', 'escalated'),
            'ticket' => $enriched,
            'subjects' => $enriched->subjects ?? [],
        ]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function detach(WP_REST_Request $request)
    {
        $ticket = $this->resolve_ticket($request);
        if (is_wp_error($ticket)) {
            return $ticket;
        }

        $link_id = (int) $request->get_param('link_id');
        $deleted = TicketSubjectService::detach_by_link_id((int) $ticket->id, $link_id);

        if ($deleted < 1) {
            return $this->error('escalated_not_found', __('Subject link not found.', 'escalated'), 404);
        }

        $enriched = Ticket::enrich($ticket);

        return $this->success([
            'message' => __('Subject detached.', 'escalated'),
            'ticket' => $enriched,
            'subjects' => $enriched->subjects ?? [],
        ]);
    }

    /**
     * @return object|\WP_Error
     */
    private function resolve_ticket(WP_REST_Request $request)
    {
        $ref = sanitize_text_field((string) $request->get_param('ref'));
        $ticket = Ticket::find_by_reference($ref);

        if (! $ticket) {
            return $this->error('escalated_not_found', __('Ticket not found.', 'escalated'), 404);
        }

        return $ticket;
    }
}
