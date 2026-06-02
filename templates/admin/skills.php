<?php
/**
 * Admin template: Skills
 *
 * Mirrors the shared package pages Escalated/Admin/Skills/Index.vue and
 * Escalated/Admin/Skills/Form.vue (see class-admin-skills.php). REST:
 * /wp-json/escalated/v1/admin/skills
 *
 * @var array $skills
 * @var array $ctx keys: available_agents, available_tags, available_departments
 * @var array|null $edit_skill
 * @var string $message
 * @var string $error
 */
if (! defined('ABSPATH')) {
    exit;
}

$is_edit = is_array($edit_skill);
$routing_tag_ids = $is_edit ? ($edit_skill['routing_tag_ids'] ?? []) : [];
$routing_department_ids = $is_edit ? ($edit_skill['routing_department_ids'] ?? []) : [];
$agent_map = [];
if ($is_edit && ! empty($edit_skill['agents'])) {
    foreach ($edit_skill['agents'] as $row) {
        $agent_map[(int) $row['user_id']] = (int) $row['proficiency'];
    }
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Skills', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <p class="description" style="max-width: 720px;">
        <?php esc_html_e('Inertia/Vue component paths when embedding the shared frontend: Escalated/Admin/Skills/Index and Escalated/Admin/Skills/Form.', 'escalated'); ?>
    </p>

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php
            $messages = [
                'created' => __('Skill created successfully.', 'escalated'),
                'updated' => __('Skill updated successfully.', 'escalated'),
                'deleted' => __('Skill deleted successfully.', 'escalated'),
            ];
        echo esc_html($messages[$message] ?? __('Action completed.', 'escalated'));
        ?>
        </p></div>
    <?php } ?>

    <?php if ($error) { ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
    <?php } ?>

    <div style="display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap;">

        <div style="flex: 1; min-width: 320px; max-width: 520px;">
            <div class="escalated-card" style="padding: 16px;">
                <h2 style="margin-top: 0; font-size: 15px;">
                    <?php echo $is_edit ? esc_html__('Edit skill', 'escalated') : esc_html__('Add skill', 'escalated'); ?>
                </h2>

                <form method="post" action="">
                    <?php if ($is_edit) { ?>
                        <input type="hidden" name="escalated_skill_action" value="update">
                        <input type="hidden" name="skill_id" value="<?php echo esc_attr((string) $edit_skill['id']); ?>">
                        <?php wp_nonce_field('escalated_skill_update_'.$edit_skill['id'], '_escalated_nonce'); ?>
                    <?php } else { ?>
                        <input type="hidden" name="escalated_skill_action" value="create">
                        <?php wp_nonce_field('escalated_skill_create', '_escalated_nonce'); ?>
                    <?php } ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="skill-name"><?php esc_html_e('Name', 'escalated'); ?></label></th>
                            <td>
                                <input type="text" id="skill-name" name="name" class="regular-text" required maxlength="100"
                                       value="<?php echo esc_attr($is_edit ? $edit_skill['name'] : ''); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="routing-tags"><?php esc_html_e('Routing tags', 'escalated'); ?></label></th>
                            <td>
                                <select id="routing-tags" name="routing_tag_ids[]" multiple size="6" class="large-text" style="height: auto;">
                                    <?php foreach ($ctx['available_tags'] as $tag) { ?>
                                        <option value="<?php echo esc_attr((string) $tag['id']); ?>"
                                            <?php selected(in_array((int) $tag['id'], $routing_tag_ids, true), true); ?>>
                                            <?php echo esc_html($tag['name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                                <p class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple tags.', 'escalated'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="routing-depts"><?php esc_html_e('Routing departments', 'escalated'); ?></label></th>
                            <td>
                                <select id="routing-depts" name="routing_department_ids[]" multiple size="6" class="large-text" style="height: auto;">
                                    <?php foreach ($ctx['available_departments'] as $dep) { ?>
                                        <option value="<?php echo esc_attr((string) $dep['id']); ?>"
                                            <?php selected(in_array((int) $dep['id'], $routing_department_ids, true), true); ?>>
                                            <?php echo esc_html($dep['name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <h3 style="font-size: 14px;"><?php esc_html_e('Agents & proficiency', 'escalated'); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 40px;"></th>
                                <th><?php esc_html_e('Agent', 'escalated'); ?></th>
                                <th style="width: 90px;"><?php esc_html_e('Level 1–5', 'escalated'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ctx['available_agents'] as $agent) {
                                $aid = (int) $agent['id'];
                                $checked = isset($agent_map[$aid]);
                                $prof = $checked ? $agent_map[$aid] : 3;
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="enabled_agent_ids[]" value="<?php echo esc_attr((string) $aid); ?>"
                                            <?php checked($checked); ?>>
                                    </td>
                                    <td><?php echo esc_html($agent['name']); ?><br><span class="description"><?php echo esc_html($agent['email']); ?></span></td>
                                    <td>
                                        <select name="agent_proficiency[<?php echo esc_attr((string) $aid); ?>]">
                                            <?php for ($p = 1; $p <= 5; $p++) { ?>
                                                <option value="<?php echo esc_attr((string) $p); ?>" <?php selected($prof, $p); ?>><?php echo esc_html((string) $p); ?></option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>

                    <?php
                    submit_button($is_edit ? __('Update skill', 'escalated') : __('Add skill', 'escalated'));
if ($is_edit) {
    ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=escalated-skills')); ?>"><?php esc_html_e('Cancel', 'escalated'); ?></a>
                        <?php
}
?>
                </form>
            </div>
        </div>

        <div style="flex: 1; min-width: 280px;">
            <h2 class="title" style="font-size: 15px;"><?php esc_html_e('Existing skills', 'escalated'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'escalated'); ?></th>
                        <th><?php esc_html_e('Agents', 'escalated'); ?></th>
                        <th><?php esc_html_e('Tags', 'escalated'); ?></th>
                        <th><?php esc_html_e('Depts', 'escalated'); ?></th>
                        <th><?php esc_html_e('Actions', 'escalated'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($skills)) { ?>
                        <tr><td colspan="5"><?php esc_html_e('No skills yet.', 'escalated'); ?></td></tr>
                    <?php } ?>
                    <?php foreach ($skills as $row) { ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['name']); ?></strong></td>
                            <td><?php echo esc_html((string) $row['agents_count']); ?></td>
                            <td><?php echo esc_html((string) $row['routing_tags_count']); ?></td>
                            <td><?php echo esc_html((string) $row['routing_departments_count']); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $row['id']], admin_url('admin.php?page=escalated-skills'))); ?>"><?php esc_html_e('Edit', 'escalated'); ?></a>
                                |
                                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this skill?', 'escalated')); ?>');">
                                    <?php wp_nonce_field('escalated_skill_delete_'.$row['id'], '_escalated_nonce'); ?>
                                    <input type="hidden" name="escalated_skill_action" value="delete">
                                    <input type="hidden" name="skill_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                                    <button type="submit" class="button-link-delete"><?php esc_html_e('Delete', 'escalated'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
