<?php

/**
 * Admin Workflows REST, following the workflow admin contract
 * (escalated-developer-context domain-model/workflow-admin-contract.md).
 *
 * The request bodies are the contract's. WordPress has no Inertia pages, so
 * these routes answer JSON: the Index and Form props on GET, and the stored
 * workflow (or 422 with `errors`) on writes.
 */

namespace Escalated\Api;

use Escalated\Models\AuditLog;
use Escalated\Models\Workflow;
use Escalated\Services\WorkflowAdmin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Workflow_Controller extends Base_Controller
{
    public const CAPABILITY = 'escalated_automation_manage';

    /**
     * @return bool|WP_Error
     */
    public function admin_permissions_check()
    {
        if (! is_user_logged_in()) {
            return new WP_Error('escalated_unauthorized', __('You must be logged in.', 'escalated'), ['status' => 401]);
        }

        if (! current_user_can(self::CAPABILITY)) {
            return new WP_Error('escalated_forbidden', __('You do not have permission to manage workflows.', 'escalated'), ['status' => 403]);
        }

        return true;
    }

    public function register_routes(): void
    {
        $ns = $this->namespace;
        $perm = [$this, 'admin_permissions_check'];
        $id_arg = ['id' => ['required' => true, 'type' => 'integer']];

        register_rest_route($ns, '/admin/workflows', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $perm],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'store'], 'permission_callback' => $perm],
        ]);

        register_rest_route($ns, '/admin/workflows/new', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'create_form'], 'permission_callback' => $perm],
        ]);

        register_rest_route($ns, '/admin/workflows/reorder', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'reorder'], 'permission_callback' => $perm],
        ]);

        register_rest_route($ns, '/admin/workflows/(?P<id>\d+)/edit', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'edit_form'], 'permission_callback' => $perm, 'args' => $id_arg],
        ]);

        register_rest_route($ns, '/admin/workflows/(?P<id>\d+)/toggle', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'toggle'], 'permission_callback' => $perm, 'args' => $id_arg],
        ]);

        register_rest_route($ns, '/admin/workflows/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'show'], 'permission_callback' => $perm, 'args' => $id_arg],
            ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'update'], 'permission_callback' => $perm, 'args' => $id_arg],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'destroy'], 'permission_callback' => $perm, 'args' => $id_arg],
        ]);
    }

    /**
     * GET /admin/workflows: Index props.
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->success(['workflows' => WorkflowAdmin::all_formatted()]);
    }

    /**
     * GET /admin/workflows/new: Form props for creating.
     */
    public function create_form(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return $this->success(['workflow' => null] + WorkflowAdmin::form_options());
    }

    /**
     * GET /admin/workflows/{id}/edit: Form props for editing.
     */
    public function edit_form(WP_REST_Request $request)
    {
        $workflow = Workflow::find((int) $request->get_param('id'));
        if (! $workflow) {
            return $this->error('escalated_not_found', __('Workflow not found.', 'escalated'), 404);
        }

        return $this->success(['workflow' => Workflow::format($workflow)] + WorkflowAdmin::form_options());
    }

    public function show(WP_REST_Request $request)
    {
        $workflow = Workflow::find((int) $request->get_param('id'));
        if (! $workflow) {
            return $this->error('escalated_not_found', __('Workflow not found.', 'escalated'), 404);
        }

        return $this->success(['workflow' => Workflow::format($workflow)]);
    }

    /**
     * POST /admin/workflows: the contract's create body, keys top-level.
     */
    public function store(WP_REST_Request $request)
    {
        [$data, $errors] = WorkflowAdmin::validate($this->body($request));
        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $id = Workflow::create($data);
        if (! $id) {
            return $this->error('escalated_workflow_not_saved', __('The workflow could not be saved.', 'escalated'), 500);
        }
        AuditLog::record('workflow.created', 'Workflow', $id, null, ['name' => $data['name'], 'trigger_event' => $data['trigger_event']]);

        return $this->success(['workflow' => Workflow::format(Workflow::find($id))], 201);
    }

    /**
     * PUT /admin/workflows/{id}: the same body as create.
     */
    public function update(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $workflow = Workflow::find($id);
        if (! $workflow) {
            return $this->error('escalated_not_found', __('Workflow not found.', 'escalated'), 404);
        }

        [$data, $errors] = WorkflowAdmin::validate($this->body($request), $request->get_method() === 'PATCH');
        if ($errors !== []) {
            return $this->invalid($errors);
        }

        Workflow::update($id, $data);
        AuditLog::record('workflow.updated', 'Workflow', $id, ['name' => $workflow->name, 'trigger_event' => $workflow->trigger_event], array_intersect_key($data, ['name' => 1, 'trigger_event' => 1, 'is_active' => 1]));

        return $this->success(['workflow' => Workflow::format(Workflow::find($id))]);
    }

    /**
     * POST /admin/workflows/{id}/toggle: flips is_active.
     */
    public function toggle(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $workflow = Workflow::find($id);
        if (! $workflow) {
            return $this->error('escalated_not_found', __('Workflow not found.', 'escalated'), 404);
        }

        Workflow::update($id, ['is_active' => empty($workflow->is_active)]);

        return $this->success(['workflow' => Workflow::format(Workflow::find($id))]);
    }

    /**
     * POST /admin/workflows/reorder: {workflow_ids: [3, 1, 2]}.
     */
    public function reorder(WP_REST_Request $request)
    {
        $ids = $this->body($request)['workflow_ids'] ?? null;
        if (! is_array($ids) || $ids === [] || array_filter($ids, static fn ($id) => ! is_numeric($id)) !== []) {
            return $this->invalid(['workflow_ids' => __('Send the workflow ids in their new order.', 'escalated')]);
        }

        Workflow::reorder(array_map('intval', $ids));

        return $this->success(['workflows' => WorkflowAdmin::all_formatted()]);
    }

    /**
     * DELETE /admin/workflows/{id}
     */
    public function destroy(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $workflow = Workflow::find($id);
        if (! $workflow) {
            return $this->error('escalated_not_found', __('Workflow not found.', 'escalated'), 404);
        }

        Workflow::delete($id);
        AuditLog::record('workflow.deleted', 'Workflow', $id, ['name' => $workflow->name], null);

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        if (is_array($json) && $json !== []) {
            return $json;
        }

        return $request->get_body_params() ?: [];
    }

    private function invalid(array $errors): WP_REST_Response
    {
        return new WP_REST_Response([
            'code' => 'escalated_validation_failed',
            'message' => reset($errors),
            'errors' => $errors,
        ], 422);
    }
}
