<?php
/**
 * Admin template: Canned Responses
 *
 * @var array $responses All canned response objects.
 * @var object|null $edit_item Response being edited (or null).
 * @var string $message   Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Canned Responses', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __('Canned response created successfully.', 'escalated'),
                    'updated' => __('Canned response updated successfully.', 'escalated'),
                    'deleted' => __('Canned response deleted successfully.', 'escalated'),
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
                <th scope="col"><?php esc_html_e('Title', 'escalated'); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e('Category', 'escalated'); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e('Created By', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Shared', 'escalated'); ?></th>
                <th scope="col" style="width: 150px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($responses)) { ?>
                <tr>
                    <td colspan="5"><?php esc_html_e('No canned responses found.', 'escalated'); ?></td>
                </tr>
            <?php } else { ?>
                <?php foreach ($responses as $response) { ?>
                    <?php
            $creator_name = __('Unknown', 'escalated');
                    if ($response->created_by) {
                        $creator = get_userdata($response->created_by);
                        if ($creator) {
                            $creator_name = $creator->display_name;
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($response->title); ?></strong>
                            <p class="description" style="margin: 2px 0 0;">
                                <?php echo esc_html(wp_trim_words(wp_strip_all_tags($response->body), 20)); ?>
                            </p>
                        </td>
                        <td><?php echo esc_html($response->category ?: '&mdash;'); ?></td>
                        <td><?php echo esc_html($creator_name); ?></td>
                        <td>
                            <?php if ($response->is_shared) { ?>
                                <span class="escalated-text-success"><?php esc_html_e('Yes', 'escalated'); ?></span>
                            <?php } else { ?>
                                <span class="escalated-text-muted"><?php esc_html_e('No', 'escalated'); ?></span>
                            <?php } ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-canned-responses&action=edit&id='.$response->id)); ?>" class="button button-small">
                                <?php esc_html_e('Edit', 'escalated'); ?>
                            </a>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this canned response?', 'escalated')); ?>');">
                                <input type="hidden" name="escalated_canned_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($response->id); ?>">
                                <?php wp_nonce_field('escalated_canned_delete_'.$response->id, '_escalated_nonce'); ?>
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
            <?php echo $edit_item ? esc_html__('Edit Canned Response', 'escalated') : esc_html__('Add New Canned Response', 'escalated'); ?>
        </h2>

        <form method="post">
            <?php if ($edit_item) { ?>
                <input type="hidden" name="escalated_canned_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr($edit_item->id); ?>">
                <?php wp_nonce_field('escalated_canned_update_'.$edit_item->id, '_escalated_nonce'); ?>
            <?php } else { ?>
                <input type="hidden" name="escalated_canned_action" value="create">
                <?php wp_nonce_field('escalated_canned_create', '_escalated_nonce'); ?>
            <?php } ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="canned-title"><?php esc_html_e('Title', 'escalated'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="canned-title" name="title" class="regular-text" required
                               value="<?php echo esc_attr($edit_item->title ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="canned-category"><?php esc_html_e('Category', 'escalated'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="canned-category" name="category" class="regular-text"
                               value="<?php echo esc_attr($edit_item->category ?? ''); ?>"
                               placeholder="<?php esc_attr_e('e.g., Billing, Support, General', 'escalated'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Body', 'escalated'); ?></label>
                    </th>
                    <td>
                        <?php
                        wp_editor($edit_item->body ?? '', 'canned_body', [
                            'textarea_name' => 'body',
                            'media_buttons' => false,
                            'textarea_rows' => 10,
                            'teeny' => false,
                            'quicktags' => true,
                        ]);
?>
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
                        <p class="description"><?php esc_html_e('If unchecked, only you can use this canned response.', 'escalated'); ?></p>
                    </td>
                </tr>
            </table>

            <p>
                <?php
                submit_button(
                    $edit_item ? __('Update Response', 'escalated') : __('Add Response', 'escalated'),
                    'primary',
                    'submit',
                    false
                );
?>
                <?php if ($edit_item) { ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-canned-responses')); ?>" class="button" style="margin-left: 5px;">
                        <?php esc_html_e('Cancel', 'escalated'); ?>
                    </a>
                <?php } ?>
            </p>
        </form>
    </div>
</div>
