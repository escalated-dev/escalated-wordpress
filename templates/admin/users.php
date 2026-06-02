<?php
/**
 * Admin template: Users management.
 *
 * Ports the Vue page `Escalated/Admin/Users/Index.vue` from the
 * canonical Laravel reference (escalated-laravel PR #94) to a
 * native WordPress admin screen. The plugin does not consume the
 * Vue SPA — it renders its own templates here under wp-admin —
 * so the page shape is rebuilt with the WP list-table CSS classes.
 *
 * @var array $users List of user rows: {id, name, email, is_admin, is_agent}.
 * @var int $total Total user count (across all pages).
 * @var int $paged Current page number (1-indexed).
 * @var int $per_page Page size.
 * @var int $total_pages Total page count.
 * @var int $current_user_id ID of the currently-logged-in admin.
 * @var string $search Active search term, or ''.
 * @var string $message Flash message key (success).
 * @var string $error Flash error key.
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Users', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message === 'updated') { ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('User updated.', 'escalated'); ?></p>
        </div>
    <?php } ?>

    <?php if ($error) { ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                $error_messages = [
                    'self_demote' => __('You cannot remove your own admin role.', 'escalated'),
                    'not_found' => __('That user no longer exists.', 'escalated'),
                    'invalid_role' => __('Unknown role.', 'escalated'),
                    'error' => __('Could not update user.', 'escalated'),
                ];
        echo esc_html($error_messages[$error] ?? $error_messages['error']);
        ?>
            </p>
        </div>
    <?php } ?>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin: 12px 0;">
        <input type="hidden" name="page" value="escalated-users">
        <p class="search-box">
            <label class="screen-reader-text" for="user-search-input"><?php esc_html_e('Search users', 'escalated'); ?></label>
            <input type="search" id="user-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search by name or email', 'escalated'); ?>">
            <?php submit_button(__('Search Users', 'escalated'), '', '', false); ?>
        </p>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Name', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Email', 'escalated'); ?></th>
                <th scope="col" style="width: 110px;"><?php esc_html_e('Admin', 'escalated'); ?></th>
                <th scope="col" style="width: 110px;"><?php esc_html_e('Agent', 'escalated'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)) { ?>
                <tr>
                    <td colspan="4"><?php esc_html_e('No users found.', 'escalated'); ?></td>
                </tr>
            <?php } else { ?>
                <?php foreach ($users as $user_row) { ?>
                    <?php $is_self = ((int) $user_row['id'] === (int) $current_user_id); ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($user_row['name']); ?></strong>
                            <?php if ($is_self) { ?>
                                <span class="description">— <?php esc_html_e('you', 'escalated'); ?></span>
                            <?php } ?>
                        </td>
                        <td><?php echo esc_html($user_row['email']); ?></td>
                        <td>
                            <form method="post" style="margin: 0;">
                                <input type="hidden" name="escalated_user_action" value="update_role">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr($user_row['id']); ?>">
                                <input type="hidden" name="role" value="admin">
                                <input type="hidden" name="value" value="<?php echo $user_row['is_admin'] ? '0' : '1'; ?>">
                                <?php wp_nonce_field('escalated_user_role_'.$user_row['id'], '_escalated_nonce'); ?>
                                <?php if ($user_row['is_admin']) { ?>
                                    <button type="submit" class="button button-small"<?php echo $is_self ? ' disabled aria-disabled="true"' : ''; ?>>
                                        <?php esc_html_e('Revoke admin', 'escalated'); ?>
                                    </button>
                                <?php } else { ?>
                                    <button type="submit" class="button button-small button-primary">
                                        <?php esc_html_e('Make admin', 'escalated'); ?>
                                    </button>
                                <?php } ?>
                            </form>
                        </td>
                        <td>
                            <form method="post" style="margin: 0;">
                                <input type="hidden" name="escalated_user_action" value="update_role">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr($user_row['id']); ?>">
                                <input type="hidden" name="role" value="agent">
                                <input type="hidden" name="value" value="<?php echo $user_row['is_agent'] ? '0' : '1'; ?>">
                                <?php wp_nonce_field('escalated_user_role_'.$user_row['id'], '_escalated_nonce'); ?>
                                <?php if ($user_row['is_agent']) { ?>
                                    <button type="submit" class="button button-small">
                                        <?php esc_html_e('Revoke agent', 'escalated'); ?>
                                    </button>
                                <?php } else { ?>
                                    <button type="submit" class="button button-small button-primary">
                                        <?php esc_html_e('Make agent', 'escalated'); ?>
                                    </button>
                                <?php } ?>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1) { ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                        /* translators: %s: number of users */
                        echo esc_html(sprintf(_n('%s user', '%s users', $total, 'escalated'), number_format_i18n($total)));
        ?>
                </span>
                <span class="pagination-links">
                    <?php
                        $base = admin_url('admin.php?page=escalated-users');
        if ($search !== '') {
            $base = add_query_arg('s', $search, $base);
        }

        // Previous link.
        if ($paged > 1) {
            $prev = add_query_arg('paged', $paged - 1, $base);
            echo '<a class="prev-page button" href="'.esc_url($prev).'">&lsaquo; '.esc_html__('Prev', 'escalated').'</a> ';
        }

        /* translators: 1: current page number, 2: total page count */
        echo '<span class="paging-input">'.esc_html(sprintf(__('Page %1$s of %2$s', 'escalated'), $paged, $total_pages)).'</span>';

        if ($paged < $total_pages) {
            $next = add_query_arg('paged', $paged + 1, $base);
            echo ' <a class="next-page button" href="'.esc_url($next).'">'.esc_html__('Next', 'escalated').' &rsaquo;</a>';
        }
        ?>
                </span>
            </div>
        </div>
    <?php } ?>
</div>
