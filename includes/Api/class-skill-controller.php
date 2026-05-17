<?php

/**
 * Admin Skills REST — canonical wire contract (skills-management.md).
 *
 * Shared Vue / Inertia pages (when embedded): Escalated/Admin/Skills/Index,
 * Escalated/Admin/Skills/Form — named routes escalated.admin.skills.* on other hosts.
 */

namespace Escalated\Api;

use Escalated\Services\SkillService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Skill_Controller extends Base_Controller
{
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

        if (! current_user_can('escalated_skill_manage')) {
            return new WP_Error(
                'escalated_forbidden',
                __('You do not have permission to manage skills.', 'escalated'),
                ['status' => 403]
            );
        }

        return true;
    }

    public function register_routes(): void
    {
        $ns = $this->namespace;
        $perm = [$this, 'admin_permissions_check'];

        register_rest_route($ns, '/admin/skills/new', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'create_form'],
                'permission_callback' => $perm,
            ],
        ]);

        register_rest_route($ns, '/admin/skills/(?P<id>\d+)/edit', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'edit_form'],
                'permission_callback' => $perm,
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        register_rest_route($ns, '/admin/skills/(?P<id>\d+)', [
            [
                'methods' => ['PUT', 'PATCH'],
                'callback' => [$this, 'update_item'],
                'permission_callback' => $perm,
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_item'],
                'permission_callback' => $perm,
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        register_rest_route($ns, '/admin/skills', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => $perm,
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'store'],
                'permission_callback' => $perm,
            ],
        ]);
    }

    /**
     * GET /admin/skills — index props.
     */
    public function index(WP_REST_Request $request)
    {
        unset($request);

        return $this->success([
            'skills' => SkillService::list_for_admin(),
        ]);
    }

    /**
     * GET /admin/skills/new — create form props.
     */
    public function create_form(WP_REST_Request $request)
    {
        unset($request);

        return $this->success(array_merge(
            SkillService::get_form_context(),
            ['skill' => null]
        ));
    }

    /**
     * GET /admin/skills/{id}/edit — edit form props.
     */
    public function edit_form(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $skill = SkillService::find_for_edit($id);
        if ($skill === null) {
            return $this->error('escalated_skill_not_found', __('Skill not found.', 'escalated'), 404);
        }

        return $this->success(array_merge(
            SkillService::get_form_context(),
            ['skill' => $skill]
        ));
    }

    /**
     * POST /admin/skills — store.
     */
    public function store(WP_REST_Request $request)
    {
        $payload = $this->parse_json_body($request);
        $id = SkillService::create($payload);
        if (is_wp_error($id)) {
            return $id;
        }

        return $this->success(['id' => $id], 201);
    }

    /**
     * PUT/PATCH /admin/skills/{id} — update.
     */
    public function update_item(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $payload = $this->parse_json_body($request);
        $result = SkillService::update($id, $payload);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->success(['ok' => true]);
    }

    /**
     * DELETE /admin/skills/{id} — destroy.
     */
    public function delete_item(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $result = SkillService::delete($id);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->success(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse_json_body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }

        $body = $request->get_body_params();

        return is_array($body) ? $body : [];
    }
}
