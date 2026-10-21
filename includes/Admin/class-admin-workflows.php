<?php

namespace Escalated\Admin;

use Escalated\Models\AuditLog;
use Escalated\Models\Workflow;
use Escalated\Services\WorkflowAdmin;

/**
 * Escalated > Workflows: create, edit, enable and delete the event-driven
 * workflows the engine runs. The form builds the workflow admin contract's
 * body and saves it through the same validation as the REST routes.
 */
class Admin_Workflows
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function render(): void
    {
        $workflows = WorkflowAdmin::all_formatted();
        $options = WorkflowAdmin::form_options();
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';

        $edit_item = null;
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && ! empty($_GET['id'])) {
            $row = Workflow::find(absint($_GET['id']));
            $edit_item = $row ? Workflow::format($row) : null;
        }

        // A form that failed validation comes back with what was typed.
        $flash = get_transient(self::flash_key());
        delete_transient(self::flash_key());
        $errors = is_array($flash) ? (array) ($flash['errors'] ?? []) : [];
        $old = is_array($flash) ? (array) ($flash['input'] ?? []) : null;

        include ESCALATED_PLUGIN_DIR.'templates/admin/workflows.php';
    }

    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_workflow_action'])) {
            return;
        }

        if (! current_user_can('escalated_automation_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        wp_safe_redirect($this->process(wp_unslash($_POST)));
        exit;
    }

    /**
     * Apply a submitted (unslashed) form and return the URL to redirect to.
     *
     * @param  array<string, mixed>  $input
     */
    public function process(array $input): string
    {
        $action = sanitize_key((string) ($input['escalated_workflow_action'] ?? ''));
        $id = absint($input['id'] ?? 0);
        $nonce = sanitize_text_field((string) ($input['_escalated_nonce'] ?? ''));
        $base = admin_url('admin.php?page=escalated-workflows');

        $nonce_action = $action === 'create' ? 'escalated_workflow_create' : 'escalated_workflow_'.$action.'_'.$id;
        if (! wp_verify_nonce($nonce, $nonce_action)) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }

        switch ($action) {
            case 'create':
            case 'update':
                if ($action === 'update' && ! Workflow::find($id)) {
                    return add_query_arg('message', 'error', $base);
                }

                [$data, $errors] = WorkflowAdmin::validate($this->contract_body($input, $json_errors));
                $errors = $json_errors + $errors;
                if ($errors !== []) {
                    set_transient(self::flash_key(), ['errors' => $errors, 'input' => $input], 5 * MINUTE_IN_SECONDS);
                    $args = ['message' => 'invalid'];
                    if ($action === 'update') {
                        $args += ['action' => 'edit', 'id' => $id];
                    }

                    return add_query_arg($args, $base);
                }

                if ($action === 'create') {
                    $new_id = Workflow::create($data);
                    if (! $new_id) {
                        return add_query_arg('message', 'error', $base);
                    }
                    AuditLog::record('workflow.created', 'Workflow', $new_id, null, ['name' => $data['name'], 'trigger_event' => $data['trigger_event']]);

                    return add_query_arg('message', 'created', $base);
                }

                Workflow::update($id, $data);
                AuditLog::record('workflow.updated', 'Workflow', $id, null, ['name' => $data['name'], 'trigger_event' => $data['trigger_event']]);

                return add_query_arg('message', 'updated', $base);

            case 'toggle':
                $workflow = Workflow::find($id);
                if ($workflow) {
                    Workflow::update($id, ['is_active' => empty($workflow->is_active)]);
                }

                return add_query_arg('message', 'updated', $base);

            case 'delete':
                $workflow = Workflow::find($id);
                if ($workflow) {
                    Workflow::delete($id);
                    AuditLog::record('workflow.deleted', 'Workflow', $id, ['name' => $workflow->name], null);
                }

                return add_query_arg('message', 'deleted', $base);
        }

        return $base;
    }

    /**
     * Turn the form fields into the contract's request body.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>|null  $errors  JSON that did not parse.
     * @return array<string, mixed>
     */
    private function contract_body(array $input, ?array &$errors): array
    {
        $errors = [];
        $match = ($input['match'] ?? 'all') === 'any' ? 'any' : 'all';

        $conditions = $this->decode_list((string) ($input['conditions'] ?? ''));
        if ($conditions === null) {
            $errors['conditions'] = __('Conditions must be a JSON list of {"field", "operator", "value"} objects.', 'escalated');
            $conditions = [];
        }

        $actions = $this->decode_list((string) ($input['actions_json'] ?? ''));
        if ($actions === null) {
            $errors['actions'] = __('Actions must be a JSON list of {"type", "value"} objects.', 'escalated');
            $actions = [];
        }

        return [
            'name' => (string) ($input['name'] ?? ''),
            'trigger_event' => (string) ($input['trigger_event'] ?? ''),
            'conditions' => [$match => $conditions],
            'actions' => $actions,
            'is_active' => ! empty($input['is_active']),
            'stop_on_match' => ! empty($input['stop_on_match']),
            'position' => $input['position'] ?? null,
        ];
    }

    private function decode_list(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    private static function flash_key(): string
    {
        return 'escalated_workflow_form_'.get_current_user_id();
    }
}
