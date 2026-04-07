<?php
/**
 * Admin template: Automations
 *
 * @var array $automations All automation objects.
 * @var object|null $edit_item   Automation being edited (or null).
 * @var string $message     Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

$edit_conditions = [];
$edit_actions = [];
if ($edit_item) {
    $edit_conditions = json_decode($edit_item->conditions ?? '[]', true) ?: [];
    $edit_actions = json_decode($edit_item->actions ?? '[]', true) ?: [];
}

$condition_fields = [
    'hours_since_created' => __('Hours Since Created', 'escalated'),
    'hours_since_updated' => __('Hours Since Updated', 'escalated'),
    'hours_since_assigned' => __('Hours Since Assigned', 'escalated'),
    'status' => __('Status', 'escalated'),
    'priority' => __('Priority', 'escalated'),
    'assigned' => __('Assigned / Unassigned', 'escalated'),
    'ticket_type' => __('Ticket Type', 'escalated'),
    'subject_contains' => __('Subject Contains', 'escalated'),
];

$action_types = [
    'change_status' => __('Change Status', 'escalated'),
    'assign' => __('Assign to Agent', 'escalated'),
    'add_tag' => __('Add Tag', 'escalated'),
    'change_priority' => __('Change Priority', 'escalated'),
    'add_note' => __('Add Internal Note', 'escalated'),
    'set_ticket_type' => __('Set Ticket Type', 'escalated'),
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Automations', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __('Automation created successfully.', 'escalated'),
                    'updated' => __('Automation updated successfully.', 'escalated'),
                    'deleted' => __('Automation deleted successfully.', 'escalated'),
                    'error' => __('An error occurred. Please try again.', 'escalated'),
                ];
        echo esc_html($messages[$message] ?? __('Action completed.', 'escalated'));
        ?>
            </p>
        </div>
    <?php } ?>

    <!-- List -->
    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th scope="col" style="width: 50px;"><?php esc_html_e('Position', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Name', 'escalated'); ?></th>
                <th scope="col" style="width: 100px;"><?php esc_html_e('Conditions', 'escalated'); ?></th>
                <th scope="col" style="width: 100px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Active', 'escalated'); ?></th>
                <th scope="col" style="width: 140px;"><?php esc_html_e('Last Run', 'escalated'); ?></th>
                <th scope="col" style="width: 150px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($automations)) { ?>
                <tr>
                    <td colspan="7"><?php esc_html_e('No automations found.', 'escalated'); ?></td>
                </tr>
            <?php } else { ?>
                <?php foreach ($automations as $automation) { ?>
                    <?php
            $conds = json_decode($automation->conditions ?? '[]', true) ?: [];
                    $acts = json_decode($automation->actions ?? '[]', true) ?: [];
                    ?>
                    <tr>
                        <td><?php echo esc_html($automation->position ?? 0); ?></td>
                        <td><strong><?php echo esc_html($automation->name); ?></strong></td>
                        <td><?php echo esc_html(count($conds)); ?></td>
                        <td><?php echo esc_html(count($acts)); ?></td>
                        <td>
                            <?php if ($automation->active) { ?>
                                <span class="escalated-text-success"><?php esc_html_e('Yes', 'escalated'); ?></span>
                            <?php } else { ?>
                                <span class="escalated-text-danger"><?php esc_html_e('No', 'escalated'); ?></span>
                            <?php } ?>
                        </td>
                        <td>
                            <?php echo $automation->last_run_at ? esc_html($automation->last_run_at) : '<em>'.esc_html__('Never', 'escalated').'</em>'; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-automations&action=edit&id='.$automation->id)); ?>" class="button button-small">
                                <?php esc_html_e('Edit', 'escalated'); ?>
                            </a>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this automation?', 'escalated')); ?>');">
                                <input type="hidden" name="escalated_automation_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($automation->id); ?>">
                                <?php wp_nonce_field('escalated_automation_delete_'.$automation->id, '_escalated_nonce'); ?>
                                <button type="submit" class="button button-small button-link-delete">
                                    <?php esc_html_e('Delete', 'escalated'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
    </table>

    <!-- Form -->
    <div class="escalated-card" style="padding: 15px; max-width: 800px;">
        <h2 style="margin-top: 0; font-size: 15px;">
            <?php echo $edit_item ? esc_html__('Edit Automation', 'escalated') : esc_html__('Add New Automation', 'escalated'); ?>
        </h2>

        <form method="post">
            <?php if ($edit_item) { ?>
                <input type="hidden" name="escalated_automation_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr($edit_item->id); ?>">
                <?php wp_nonce_field('escalated_automation_update_'.$edit_item->id, '_escalated_nonce'); ?>
            <?php } else { ?>
                <input type="hidden" name="escalated_automation_action" value="create">
                <?php wp_nonce_field('escalated_automation_create', '_escalated_nonce'); ?>
            <?php } ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="automation-name"><?php esc_html_e('Name', 'escalated'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="automation-name" name="name" class="regular-text" required
                               value="<?php echo esc_attr($edit_item->name ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="automation-conditions"><?php esc_html_e('Conditions (JSON)', 'escalated'); ?></label>
                    </th>
                    <td>
                        <textarea id="automation-conditions" name="conditions" rows="6" class="large-text code"
                                  placeholder='[{"field": "hours_since_created", "operator": ">", "value": 24}, {"field": "status", "value": "open"}]'><?php echo esc_textarea($edit_item ? wp_json_encode($edit_conditions, JSON_PRETTY_PRINT) : ''); ?></textarea>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: comma-separated list of condition field names */
                                esc_html__('JSON array of conditions. Supported fields: %s', 'escalated'),
                                esc_html(implode(', ', array_keys($condition_fields)))
                            );
?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="automation-actions"><?php esc_html_e('Actions (JSON)', 'escalated'); ?></label>
                    </th>
                    <td>
                        <textarea id="automation-actions" name="actions_json" rows="6" class="large-text code"
                                  placeholder='[{"type": "change_priority", "value": "urgent"}, {"type": "add_note", "value": "Auto-escalated due to age"}]'><?php echo esc_textarea($edit_item ? wp_json_encode($edit_actions, JSON_PRETTY_PRINT) : ''); ?></textarea>
                        <p class="description">
                            <?php
printf(
    /* translators: %s: comma-separated list of action type names */
    esc_html__('JSON array of actions. Supported types: %s', 'escalated'),
    esc_html(implode(', ', array_keys($action_types)))
);
?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="automation-position"><?php esc_html_e('Position', 'escalated'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="automation-position" name="position" class="small-text" min="0" step="1"
                               value="<?php echo esc_attr($edit_item->position ?? 0); ?>">
                        <p class="description"><?php esc_html_e('Lower numbers execute first.', 'escalated'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Active', 'escalated'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="active" value="1"
                                <?php checked($edit_item ? $edit_item->active : 1, 1); ?>>
                            <?php esc_html_e('Automation is active', 'escalated'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p>
                <?php
                submit_button(
                    $edit_item ? __('Update Automation', 'escalated') : __('Add Automation', 'escalated'),
                    'primary',
                    'submit',
                    false
                );
?>
                <?php if ($edit_item) { ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-automations')); ?>" class="button" style="margin-left: 5px;">
                        <?php esc_html_e('Cancel', 'escalated'); ?>
                    </a>
                <?php } ?>
            </p>
        </form>
    </div>
</div>
