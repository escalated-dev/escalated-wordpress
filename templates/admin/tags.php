<?php
/**
 * Admin template: Tags
 *
 * @var array $tags      All tag objects.
 * @var object|null $edit_item Tag being edited (or null).
 * @var string $message   Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Tags', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __('Tag created successfully.', 'escalated'),
                    'updated' => __('Tag updated successfully.', 'escalated'),
                    'deleted' => __('Tag deleted successfully.', 'escalated'),
                    'error' => __('An error occurred. Please try again.', 'escalated'),
                ];
        echo esc_html($messages[$message] ?? __('Action completed.', 'escalated'));
        ?>
            </p>
        </div>
    <?php } ?>

    <div style="display: flex; gap: 20px; align-items: flex-start;">

        <!-- Form -->
        <div style="width: 350px; flex-shrink: 0;">
            <div class="escalated-card" style="padding: 15px;">
                <h2 style="margin-top: 0; font-size: 15px;">
                    <?php echo $edit_item ? esc_html__('Edit Tag', 'escalated') : esc_html__('Add New Tag', 'escalated'); ?>
                </h2>

                <form method="post">
                    <?php if ($edit_item) { ?>
                        <input type="hidden" name="escalated_tag_action" value="update">
                        <input type="hidden" name="id" value="<?php echo esc_attr($edit_item->id); ?>">
                        <?php wp_nonce_field('escalated_tag_update_'.$edit_item->id, '_escalated_nonce'); ?>
                    <?php } else { ?>
                        <input type="hidden" name="escalated_tag_action" value="create">
                        <?php wp_nonce_field('escalated_tag_create', '_escalated_nonce'); ?>
                    <?php } ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="tag-name"><?php esc_html_e('Name', 'escalated'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="tag-name" name="name" class="regular-text" required
                                       value="<?php echo esc_attr($edit_item->name ?? ''); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="tag-slug"><?php esc_html_e('Slug', 'escalated'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="tag-slug" name="slug" class="regular-text"
                                       value="<?php echo esc_attr($edit_item->slug ?? ''); ?>">
                                <p class="description"><?php esc_html_e('Leave blank to auto-generate from name.', 'escalated'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="tag-color"><?php esc_html_e('Color', 'escalated'); ?></label>
                            </th>
                            <td>
                                <input type="color" id="tag-color" name="color"
                                       value="<?php echo esc_attr($edit_item->color ?? '#6B7280'); ?>"
                                       style="width: 60px; height: 35px; padding: 2px; cursor: pointer;">
                                <span id="tag-color-preview" style="margin-left: 10px; display: inline-block; padding: 3px 10px; border-radius: 3px; color: #fff; font-size: 12px; background: <?php echo esc_attr($edit_item->color ?? '#6B7280'); ?>;">
                                    <?php esc_html_e('Preview', 'escalated'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <?php
            submit_button(
                $edit_item ? __('Update Tag', 'escalated') : __('Add Tag', 'escalated')
            );
?>

                    <?php if ($edit_item) { ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-tags')); ?>" class="button">
                            <?php esc_html_e('Cancel', 'escalated'); ?>
                        </a>
                    <?php } ?>
                </form>
            </div>
        </div>

        <!-- List -->
        <div style="flex: 1; min-width: 0;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width: 40px;"><?php esc_html_e('Color', 'escalated'); ?></th>
                        <th scope="col"><?php esc_html_e('Name', 'escalated'); ?></th>
                        <th scope="col" style="width: 120px;"><?php esc_html_e('Slug', 'escalated'); ?></th>
                        <th scope="col" style="width: 150px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tags)) { ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No tags found.', 'escalated'); ?></td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($tags as $tag) { ?>
                            <tr>
                                <td>
                                    <span style="display: inline-block; width: 20px; height: 20px; border-radius: 50%; background: <?php echo esc_attr($tag->color); ?>;"></span>
                                </td>
                                <td>
                                    <strong>
                                        <span style="display: inline-block; background: <?php echo esc_attr($tag->color); ?>; color: #fff; padding: 2px 10px; border-radius: 3px; font-size: 12px;">
                                            <?php echo esc_html($tag->name); ?>
                                        </span>
                                    </strong>
                                </td>
                                <td><code><?php echo esc_html($tag->slug); ?></code></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-tags&action=edit&id='.$tag->id)); ?>" class="button button-small">
                                        <?php esc_html_e('Edit', 'escalated'); ?>
                                    </a>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this tag?', 'escalated')); ?>');">
                                        <input type="hidden" name="escalated_tag_action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($tag->id); ?>">
                                        <?php wp_nonce_field('escalated_tag_delete_'.$tag->id, '_escalated_nonce'); ?>
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
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var colorInput = document.getElementById('tag-color');
    var preview = document.getElementById('tag-color-preview');
    if (colorInput && preview) {
        colorInput.addEventListener('input', function() {
            preview.style.backgroundColor = this.value;
        });
    }
});
</script>
