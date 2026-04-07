<?php
/**
 * Admin template: Macros
 *
 * @var array $macros    All macro objects.
 * @var object|null $edit_item Macro being edited (or null).
 * @var string $message   Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

$edit_actions = [];
if ($edit_item) {
    $edit_actions = json_decode($edit_item->actions ?? '[]', true) ?: [];
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Macros', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __('Macro created successfully.', 'escalated'),
                    'updated' => __('Macro updated successfully.', 'escalated'),
                    'deleted' => __('Macro deleted successfully.', 'escalated'),
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
                <th scope="col" style="width: 50px;"><?php esc_html_e('Order', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Name', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Description', 'escalated'); ?></th>
                <th scope="col" style="width: 100px;"><?php esc_html_e('Actions #', 'escalated'); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e('Created By', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Shared', 'escalated'); ?></th>
                <th scope="col" style="width: 150px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($macros)) { ?>
                <tr>
                    <td colspan="7"><?php esc_html_e('No macros found.', 'escalated'); ?></td>
                </tr>
            <?php } else { ?>
                <?php foreach ($macros as $macro) { ?>
                    <?php
            $creator_name = __('Unknown', 'escalated');
                    if ($macro->created_by) {
                        $creator = get_userdata($macro->created_by);
                        if ($creator) {
                            $creator_name = $creator->display_name;
                        }
                    }
                    $action_count = 0;
                    $decoded = json_decode($macro->actions ?? '[]', true);
                    if (is_array($decoded)) {
                        $action_count = count($decoded);
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($macro->sort_order ?? 0); ?></td>
                        <td><strong><?php echo esc_html($macro->name); ?></strong></td>
                        <td><?php echo esc_html(wp_trim_words($macro->description ?? '', 15)); ?></td>
                        <td><?php echo esc_html($action_count); ?></td>
                        <td><?php echo esc_html($creator_name); ?></td>
                        <td>
                            <?php if ($macro->is_shared) { ?>
                                <span class="escalated-text-success"><?php esc_html_e('Yes', 'escalated'); ?></span>
                            <?php } else { ?>
                                <span class="escalated-text-muted"><?php esc_html_e('No', 'escalated'); ?></span>
                            <?php } ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-macros&action=edit&id='.$macro->id)); ?>" class="button button-small">
                                <?php esc_html_e('Edit', 'escalated'); ?>
                            </a>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this macro?', 'escalated')); ?>');">
                                <input type="hidden" name="escalated_macro_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($macro->id); ?>">
                                <?php wp_nonce_field('escalated_macro_delete_'.$macro->id, '_escalated_nonce'); ?>
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
            <?php echo $edit_item ? esc_html__('Edit Macro', 'escalated') : esc_html__('Add New Macro', 'escalated'); ?>
        </h2>

        <form method="post">
            <?php if ($edit_item) { ?>
                <input type="hidden" name="escalated_macro_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr($edit_item->id); ?>">
                <?php wp_nonce_field('escalated_macro_update_'.$edit_item->id, '_escalated_nonce'); ?>
            <?php } else { ?>
                <input type="hidden" name="escalated_macro_action" value="create">
                <?php wp_nonce_field('escalated_macro_create', '_escalated_nonce'); ?>
            <?php } ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="macro-name"><?php esc_html_e('Name', 'escalated'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="macro-name" name="name" class="regular-text" required
                               value="<?php echo esc_attr($edit_item->name ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="macro-description"><?php esc_html_e('Description', 'escalated'); ?></label>
                    </th>
                    <td>
                        <textarea id="macro-description" name="description" rows="3" class="large-text"><?php echo esc_textarea($edit_item->description ?? ''); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="macro-actions"><?php esc_html_e('Actions (JSON)', 'escalated'); ?></label>
                    </th>
                    <td>
                        <textarea id="macro-actions" name="actions_json" rows="8" class="large-text code"
                                  placeholder='[{"type": "change_status", "value": "resolved"}, {"type": "change_priority", "value": "low"}, {"type": "add_reply", "value": "This ticket has been resolved."}]'><?php echo esc_textarea($edit_item ? wp_json_encode($edit_actions, JSON_PRETTY_PRINT) : ''); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Define macro actions as a JSON array. Supported action types: change_status, change_priority, assign_to, add_tag, remove_tag, add_reply, add_note, change_department.', 'escalated'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="macro-order"><?php esc_html_e('Sort Order', 'escalated'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="macro-order" name="sort_order" class="small-text" min="0" step="1"
                               value="<?php echo esc_attr($edit_item->sort_order ?? 0); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Visibility', 'escalated'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_shared" value="1"
                                <?php checked($edit_item->is_shared ?? 0, 1); ?>>
                            <?php esc_html_e('Shared with all agents', 'escalated'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('If unchecked, only you can use this macro.', 'escalated'); ?></p>
                    </td>
                </tr>
            </table>

            <p>
                <?php
                submit_button(
                    $edit_item ? __('Update Macro', 'escalated') : __('Add Macro', 'escalated'),
                    'primary',
                    'submit',
                    false
                );
?>
                <?php if ($edit_item) { ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-macros')); ?>" class="button" style="margin-left: 5px;">
                        <?php esc_html_e('Cancel', 'escalated'); ?>
                    </a>
                <?php } ?>
            </p>
        </form>
    </div>
</div>
