<?php
/**
 * Admin template: Tickets List
 *
 * @var array $tickets      Array of ticket objects.
 * @var int $total        Total ticket count.
 * @var int $per_page     Results per page.
 * @var int $current_page Current page number.
 * @var int $total_pages  Total pages.
 * @var array $statuses     Ticket statuses from Enums.
 * @var array $priorities   Ticket priorities from Enums.
 * @var array $departments  All departments.
 * @var array $agents       Agent user objects.
 * @var string $message      Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Tickets', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'reply_added' => __('Reply added successfully.', 'escalated'),
                    'note_added' => __('Internal note added.', 'escalated'),
                    'status_changed' => __('Status updated.', 'escalated'),
                    'priority_changed' => __('Priority updated.', 'escalated'),
                    'assigned' => __('Assignment updated.', 'escalated'),
                    'department_changed' => __('Department updated.', 'escalated'),
                    'tags_updated' => __('Tags updated.', 'escalated'),
                ];
        echo esc_html($messages[$message] ?? __('Action completed.', 'escalated'));
        ?>
            </p>
        </div>
    <?php } ?>

    <!-- Filters -->
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="escalated-filters" style="margin-bottom: 15px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="page" value="escalated">

        <select name="status">
            <option value=""><?php esc_html_e('All Statuses', 'escalated'); ?></option>
            <?php foreach ($statuses as $key => $status_data) { ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected(isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '', $key); ?>>
                    <?php echo esc_html($status_data['label']); ?>
                </option>
            <?php } ?>
        </select>

        <select name="priority">
            <option value=""><?php esc_html_e('All Priorities', 'escalated'); ?></option>
            <?php foreach ($priorities as $key => $priority_data) { ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected(isset($_GET['priority']) ? sanitize_text_field(wp_unslash($_GET['priority'])) : '', $key); ?>>
                    <?php echo esc_html($priority_data['label']); ?>
                </option>
            <?php } ?>
        </select>

        <select name="department_id">
            <option value=""><?php esc_html_e('All Departments', 'escalated'); ?></option>
            <?php foreach ($departments as $dept) { ?>
                <option value="<?php echo esc_attr($dept->id); ?>" <?php selected(isset($_GET['department_id']) ? absint($_GET['department_id']) : 0, $dept->id); ?>>
                    <?php echo esc_html($dept->name); ?>
                </option>
            <?php } ?>
        </select>

        <select name="assigned_to">
            <option value=""><?php esc_html_e('All Agents', 'escalated'); ?></option>
            <?php foreach ($agents as $agent) { ?>
                <option value="<?php echo esc_attr($agent->ID); ?>" <?php selected(isset($_GET['assigned_to']) ? absint($_GET['assigned_to']) : 0, $agent->ID); ?>>
                    <?php echo esc_html($agent->display_name); ?>
                </option>
            <?php } ?>
        </select>

        <input type="search" name="s" value="<?php echo esc_attr(isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''); ?>" placeholder="<?php esc_attr_e('Search tickets...', 'escalated'); ?>" style="width: 200px;">

        <?php submit_button(__('Filter', 'escalated'), 'secondary', 'filter', false); ?>

        <?php if (! empty($_GET['status']) || ! empty($_GET['priority']) || ! empty($_GET['department_id']) || ! empty($_GET['assigned_to']) || ! empty($_GET['s'])) { ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=escalated')); ?>" class="button"><?php esc_html_e('Clear', 'escalated'); ?></a>
        <?php } ?>
    </form>

    <p class="displaying-num" style="margin-bottom: 10px;">
        <?php
        /* translators: %s: number of tickets */
        printf(esc_html(_n('%s ticket', '%s tickets', $total, 'escalated')), number_format_i18n($total));
?>
    </p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width: 100px;"><?php esc_html_e('Reference', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Subject', 'escalated'); ?></th>
                <th scope="col" style="width: 140px;"><?php esc_html_e('Status', 'escalated'); ?></th>
                <th scope="col" style="width: 100px;"><?php esc_html_e('Priority', 'escalated'); ?></th>
                <th scope="col" style="width: 140px;"><?php esc_html_e('Requester', 'escalated'); ?></th>
                <th scope="col" style="width: 140px;"><?php esc_html_e('Assignee', 'escalated'); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e('Department', 'escalated'); ?></th>
                <th scope="col" style="width: 140px;"><?php esc_html_e('Created', 'escalated'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tickets)) { ?>
                <tr>
                    <td colspan="8"><?php esc_html_e('No tickets found.', 'escalated'); ?></td>
                </tr>
            <?php } else { ?>
                <?php foreach ($tickets as $ticket) { ?>
                    <?php
            $status_info = $statuses[$ticket->status] ?? ['label' => ucfirst($ticket->status), 'color' => '#6B7280'];
                    $priority_info = $priorities[$ticket->priority] ?? ['label' => ucfirst($ticket->priority), 'color' => '#6B7280'];

                    // Requester name.
                    $requester_name = __('Guest', 'escalated');
                    if ($ticket->requester_id) {
                        $requester = get_userdata($ticket->requester_id);
                        if ($requester) {
                            $requester_name = $requester->display_name;
                        }
                    } elseif (! empty($ticket->guest_name)) {
                        $requester_name = $ticket->guest_name;
                    }

                    // Assignee name.
                    $assignee_name = __('Unassigned', 'escalated');
                    if ($ticket->assigned_to) {
                        $assignee = get_userdata($ticket->assigned_to);
                        if ($assignee) {
                            $assignee_name = $assignee->display_name;
                        }
                    }

                    // Department name.
                    $dept_name = '&mdash;';
                    if ($ticket->department_id) {
                        $dept = \Escalated\Models\Department::find($ticket->department_id);
                        if ($dept) {
                            $dept_name = $dept->name;
                        }
                    }

                    $view_url = admin_url('admin.php?page=escalated&action=view&ticket_id='.$ticket->id);
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($view_url); ?>">
                                <strong><?php echo esc_html($ticket->reference); ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($view_url); ?>">
                                <?php echo esc_html($ticket->subject); ?>
                            </a>
                        </td>
                        <td>
                            <span class="escalated-badge" style="background-color: <?php echo esc_attr($status_info['color']); ?>; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 12px; display: inline-block;">
                                <?php echo esc_html($status_info['label']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="escalated-badge" style="background-color: <?php echo esc_attr($priority_info['color']); ?>; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 12px; display: inline-block;">
                                <?php echo esc_html($priority_info['label']); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($requester_name); ?></td>
                        <td><?php echo esc_html($assignee_name); ?></td>
                        <td><?php echo wp_kses_post($dept_name); ?></td>
                        <td>
                            <span title="<?php echo esc_attr($ticket->created_at); ?>">
                                <?php echo esc_html(human_time_diff(strtotime($ticket->created_at), current_time('timestamp')).' '.__('ago', 'escalated')); ?>
                            </span>
                        </td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1) { ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    /* translators: %s: number of tickets */
                    printf(esc_html(_n('%s item', '%s items', $total, 'escalated')), number_format_i18n($total));
        ?>
                </span>
                <span class="pagination-links">
                    <?php
        $base_url = admin_url('admin.php?page=escalated');
        $query_args = [];
        if (! empty($_GET['status'])) {
            $query_args['status'] = sanitize_text_field(wp_unslash($_GET['status']));
        }
        if (! empty($_GET['priority'])) {
            $query_args['priority'] = sanitize_text_field(wp_unslash($_GET['priority']));
        }
        if (! empty($_GET['department_id'])) {
            $query_args['department_id'] = absint($_GET['department_id']);
        }
        if (! empty($_GET['assigned_to'])) {
            $query_args['assigned_to'] = absint($_GET['assigned_to']);
        }
        if (! empty($_GET['s'])) {
            $query_args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
        }

        // First page.
        if ($current_page > 1) {
            echo '<a class="first-page button" href="'.esc_url(add_query_arg(array_merge($query_args, ['paged' => 1]), $base_url)).'">&laquo;</a> ';
            echo '<a class="prev-page button" href="'.esc_url(add_query_arg(array_merge($query_args, ['paged' => $current_page - 1]), $base_url)).'">&lsaquo;</a> ';
        } else {
            echo '<span class="tablenav-pages-navspan button disabled">&laquo;</span> ';
            echo '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span> ';
        }

        /* translators: 1: current page, 2: total pages */
        printf(
            '<span class="paging-input">'.esc_html__('%1$s of %2$s', 'escalated').'</span>',
            '<strong>'.number_format_i18n($current_page).'</strong>',
            '<strong>'.number_format_i18n($total_pages).'</strong>'
        );

        // Next/Last page.
        if ($current_page < $total_pages) {
            echo ' <a class="next-page button" href="'.esc_url(add_query_arg(array_merge($query_args, ['paged' => $current_page + 1]), $base_url)).'">&rsaquo;</a>';
            echo ' <a class="last-page button" href="'.esc_url(add_query_arg(array_merge($query_args, ['paged' => $total_pages]), $base_url)).'">&raquo;</a>';
        } else {
            echo ' <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>';
            echo ' <span class="tablenav-pages-navspan button disabled">&raquo;</span>';
        }
        ?>
                </span>
            </div>
        </div>
    <?php } ?>
</div>
