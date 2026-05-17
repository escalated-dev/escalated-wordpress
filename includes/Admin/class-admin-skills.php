<?php

namespace Escalated\Admin;

use Escalated\Services\SkillService;

/**
 * WordPress admin UI for skills (parity with shared Escalated/Admin/Skills/* Vue pages).
 *
 * When the host mounts the shared Inertia bundle, use the same props shape as:
 * - Escalated/Admin/Skills/Index.vue (route escalated.admin.skills.index)
 * - Escalated/Admin/Skills/Form.vue (create/edit — escalated.admin.skills.create / .edit)
 *
 * REST endpoints under /wp-json/escalated/v1/admin/skills supply those props for embedded panels.
 */
class Admin_Skills
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function render(): void
    {
        $skills = SkillService::list_for_admin();
        $ctx = SkillService::get_form_context();
        $edit_skill = null;

        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
            $edit_skill = SkillService::find_for_edit(absint($_GET['id']));
        }

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        include ESCALATED_PLUGIN_DIR.'templates/admin/skills.php';
    }

    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_skill_action'])) {
            return;
        }

        if (! current_user_can('escalated_skill_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['escalated_skill_action']));
        $redirect = admin_url('admin.php?page=escalated-skills');

        switch ($action) {
            case 'create':
            case 'update':
                if ($action === 'create') {
                    if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_skill_create')) {
                        wp_die(esc_html__('Security check failed.', 'escalated'));
                    }
                } else {
                    $id = absint($_POST['skill_id'] ?? 0);
                    if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_skill_update_'.$id)) {
                        wp_die(esc_html__('Security check failed.', 'escalated'));
                    }
                }

                $payload = [
                    'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                    'routing_tag_ids' => isset($_POST['routing_tag_ids']) && is_array($_POST['routing_tag_ids'])
                        ? array_map('absint', wp_unslash($_POST['routing_tag_ids']))
                        : [],
                    'routing_department_ids' => isset($_POST['routing_department_ids']) && is_array($_POST['routing_department_ids'])
                        ? array_map('absint', wp_unslash($_POST['routing_department_ids']))
                        : [],
                    'agents' => self::parse_agents_from_post(),
                ];

                if ($action === 'create') {
                    $result = SkillService::create($payload);
                    if (is_wp_error($result)) {
                        $redirect = add_query_arg('error', rawurlencode($result->get_error_message()), $redirect);
                    } else {
                        $redirect = add_query_arg('message', 'created', $redirect);
                    }
                } else {
                    $id = absint($_POST['skill_id'] ?? 0);
                    $result = SkillService::update($id, $payload);
                    if (is_wp_error($result)) {
                        $redirect = add_query_arg('error', rawurlencode($result->get_error_message()), $redirect);
                    } else {
                        $redirect = add_query_arg(['message' => 'updated', 'action' => 'edit', 'id' => $id], $redirect);
                    }
                }
                break;

            case 'delete':
                $id = absint($_POST['skill_id'] ?? 0);
                if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_skill_delete_'.$id)) {
                    wp_die(esc_html__('Security check failed.', 'escalated'));
                }
                $result = SkillService::delete($id);
                if (is_wp_error($result)) {
                    $redirect = add_query_arg('error', rawurlencode($result->get_error_message()), $redirect);
                } else {
                    $redirect = add_query_arg('message', 'deleted', $redirect);
                }
                break;
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * @return array<int, array{user_id: int, proficiency: int}>
     */
    private static function parse_agents_from_post(): array
    {
        $enabled = isset($_POST['enabled_agent_ids']) && is_array($_POST['enabled_agent_ids'])
            ? array_map('absint', wp_unslash($_POST['enabled_agent_ids']))
            : [];
        $prof_map = isset($_POST['agent_proficiency']) && is_array($_POST['agent_proficiency'])
            ? wp_unslash($_POST['agent_proficiency'])
            : [];

        $out = [];
        foreach ($enabled as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $p = isset($prof_map[$uid]) ? absint($prof_map[$uid]) : 3;
            if ($p < 1) {
                $p = 1;
            }
            if ($p > 5) {
                $p = 5;
            }
            $out[] = ['user_id' => $uid, 'proficiency' => $p];
        }

        return $out;
    }
}
