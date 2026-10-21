<?php
/**
 * Admin template: Workflows
 *
 * @var array $workflows Workflows in the contract's shape, by position.
 * @var array|null $edit_item Workflow being edited (contract shape) or null.
 * @var array $options trigger_events, action_types, operators, condition_fields.
 * @var array $errors Validation errors from the last submit.
 * @var array|null $old The last submitted form, when it failed validation.
 * @var string $message Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

$trigger_labels = [];
foreach ($options['trigger_events'] as $option) {
    $trigger_labels[$option['value']] = $option['label'];
}

$form = [
    'name' => $edit_item['name'] ?? '',
    'trigger_event' => $edit_item['trigger_event'] ?? 'ticket.created',
    'match' => isset($edit_item['conditions']['any']) ? 'any' : 'all',
    'conditions' => $edit_item ? wp_json_encode(array_values($edit_item['conditions']['any'] ?? $edit_item['conditions']['all'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
    'actions_json' => $edit_item ? wp_json_encode($edit_item['actions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
    'position' => $edit_item['position'] ?? '',
    'is_active' => $edit_item ? $edit_item['is_active'] : true,
    'stop_on_match' => $edit_item ? $edit_item['stop_on_match'] : false,
];
if (is_array($old)) {
    foreach (['name', 'trigger_event', 'match', 'conditions', 'actions_json', 'position'] as $key) {
        $form[$key] = (string) ($old[$key] ?? '');
    }
    $form['is_active'] = ! empty($old['is_active']);
    $form['stop_on_match'] = ! empty($old['stop_on_match']);
}

$messages = [
    'created' => ['success', __('Workflow created.', 'escalated')],
    'updated' => ['success', __('Workflow updated.', 'escalated')],
    'deleted' => ['success', __('Workflow deleted.', 'escalated')],
    'invalid' => ['error', __('The workflow was not saved. Fix the fields below.', 'escalated')],
    'error' => ['error', __('An error occurred. Please try again.', 'escalated')],
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Workflows', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message && isset($messages[$message])) { ?>
        <div class="notice notice-<?php echo esc_attr($messages[$message][0]); ?> is-dismissible">
            <p><?php echo esc_html($messages[$message][1]); ?></p>
            <?php if ($errors) { ?>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach ($errors as $error) { ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php } ?>
                </ul>
            <?php } ?>
        </div>
    <?php } ?>

    <p class="description">
        <?php esc_html_e('A workflow runs its actions when an event happens on a ticket and the ticket matches its conditions. Workflows run in position order.', 'escalated'); ?>
    </p>

    <table class="wp-list-table widefat fixed striped" style="margin: 15px 0 20px;">
        <thead>
            <tr>
                <th scope="col" style="width: 60px;"><?php esc_html_e('Position', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Name', 'escalated'); ?></th>
                <th scope="col" style="width: 200px;"><?php esc_html_e('Trigger', 'escalated'); ?></th>
                <th scope="col" style="width: 90px;"><?php esc_html_e('Conditions', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Active', 'escalated'); ?></th>
                <th scope="col" style="width: 220px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($workflows)) { ?>
                <tr><td colspan="7"><?php esc_html_e('No workflows yet.', 'escalated'); ?></td></tr>
            <?php } else { ?>
                <?php foreach ($workflows as $workflow) { ?>
                    <?php $condition_list = $workflow['conditions']['any'] ?? $workflow['conditions']['all'] ?? []; ?>
                    <tr>
                        <td><?php echo esc_html($workflow['position']); ?></td>
                        <td><strong><?php echo esc_html($workflow['name']); ?></strong></td>
                        <td><?php echo esc_html($trigger_labels[$workflow['trigger_event']] ?? $workflow['trigger_event']); ?><br><code><?php echo esc_html($workflow['trigger_event']); ?></code></td>
                        <td><?php echo esc_html(count($condition_list).(isset($workflow['conditions']['any']) ? ' (any)' : '')); ?></td>
                        <td><?php echo esc_html(count($workflow['actions'])); ?></td>
                        <td>
                            <?php if ($workflow['is_active']) { ?>
                                <span class="escalated-text-success"><?php esc_html_e('Yes', 'escalated'); ?></span>
                            <?php } else { ?>
                                <span class="escalated-text-danger"><?php esc_html_e('No', 'escalated'); ?></span>
                            <?php } ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-workflows&action=edit&id='.$workflow['id'])); ?>" class="button button-small"><?php esc_html_e('Edit', 'escalated'); ?></a>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="escalated_workflow_action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo esc_attr($workflow['id']); ?>">
                                <?php wp_nonce_field('escalated_workflow_toggle_'.$workflow['id'], '_escalated_nonce'); ?>
                                <button type="submit" class="button button-small"><?php echo $workflow['is_active'] ? esc_html__('Disable', 'escalated') : esc_html__('Enable', 'escalated'); ?></button>
                            </form>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this workflow?', 'escalated')); ?>');">
                                <input type="hidden" name="escalated_workflow_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($workflow['id']); ?>">
                                <?php wp_nonce_field('escalated_workflow_delete_'.$workflow['id'], '_escalated_nonce'); ?>
                                <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete', 'escalated'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
    </table>

    <div class="escalated-card" style="padding: 15px; max-width: 800px;">
        <h2 style="margin-top: 0; font-size: 15px;">
            <?php echo $edit_item ? esc_html__('Edit Workflow', 'escalated') : esc_html__('Add Workflow', 'escalated'); ?>
        </h2>

        <form method="post">
            <?php if ($edit_item) { ?>
                <input type="hidden" name="escalated_workflow_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr($edit_item['id']); ?>">
                <?php wp_nonce_field('escalated_workflow_update_'.$edit_item['id'], '_escalated_nonce'); ?>
            <?php } else { ?>
                <input type="hidden" name="escalated_workflow_action" value="create">
                <?php wp_nonce_field('escalated_workflow_create', '_escalated_nonce'); ?>
            <?php } ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="workflow-name"><?php esc_html_e('Name', 'escalated'); ?></label></th>
                    <td><input type="text" id="workflow-name" name="name" class="regular-text" required maxlength="255" value="<?php echo esc_attr($form['name']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="workflow-trigger"><?php esc_html_e('When', 'escalated'); ?></label></th>
                    <td>
                        <select id="workflow-trigger" name="trigger_event">
                            <?php foreach ($options['trigger_events'] as $option) { ?>
                                <option value="<?php echo esc_attr($option['value']); ?>" <?php selected($form['trigger_event'], $option['value']); ?>><?php echo esc_html($option['label']); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="workflow-conditions"><?php esc_html_e('If', 'escalated'); ?></label></th>
                    <td>
                        <select name="match" aria-label="<?php esc_attr_e('Match', 'escalated'); ?>">
                            <option value="all" <?php selected($form['match'], 'all'); ?>><?php esc_html_e('All conditions match', 'escalated'); ?></option>
                            <option value="any" <?php selected($form['match'], 'any'); ?>><?php esc_html_e('Any condition matches', 'escalated'); ?></option>
                        </select>
                        <textarea id="workflow-conditions" name="conditions" rows="6" class="large-text code" style="margin-top: 6px;"
                                  placeholder='[{"field": "subject", "operator": "contains", "value": "refund"}]'><?php echo esc_textarea($form['conditions']); ?></textarea>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: condition fields, 2: operators */
                                esc_html__('JSON list; leave empty to match every ticket. Fields: %1$s. Operators: %2$s.', 'escalated'),
                                esc_html(implode(', ', $options['condition_fields'])),
                                esc_html(implode(', ', $options['operators']))
                            );
?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="workflow-actions"><?php esc_html_e('Then', 'escalated'); ?></label></th>
                    <td>
                        <textarea id="workflow-actions" name="actions_json" rows="6" class="large-text code" required
                                  placeholder='[{"type": "change_priority", "value": "high"}, {"type": "add_note", "value": "Refund request: {{subject}}"}]'><?php echo esc_textarea($form['actions_json']); ?></textarea>
                        <p class="description">
                            <?php
printf(
    /* translators: %s: action types */
    esc_html__('JSON list of {"type", "value"}. Types: %s.', 'escalated'),
    esc_html(implode(', ', array_column($options['action_types'], 'value')))
);
?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="workflow-position"><?php esc_html_e('Position', 'escalated'); ?></label></th>
                    <td>
                        <input type="number" id="workflow-position" name="position" class="small-text" min="0" step="1" value="<?php echo esc_attr($form['position']); ?>">
                        <p class="description"><?php esc_html_e('Lower numbers run first. Leave empty to add at the end.', 'escalated'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Options', 'escalated'); ?></th>
                    <td>
                        <label><input type="checkbox" name="is_active" value="1" <?php checked($form['is_active']); ?>> <?php esc_html_e('Active', 'escalated'); ?></label><br>
                        <label><input type="checkbox" name="stop_on_match" value="1" <?php checked($form['stop_on_match']); ?>> <?php esc_html_e('Stop running later workflows when this one matches', 'escalated'); ?></label>
                    </td>
                </tr>
            </table>

            <p>
                <?php submit_button($edit_item ? __('Update Workflow', 'escalated') : __('Add Workflow', 'escalated'), 'primary', 'submit', false); ?>
                <?php if ($edit_item) { ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-workflows')); ?>" class="button" style="margin-left: 5px;"><?php esc_html_e('Cancel', 'escalated'); ?></a>
                <?php } ?>
            </p>
        </form>
    </div>
</div>
