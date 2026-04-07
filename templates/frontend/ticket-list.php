<?php
/**
 * Frontend template: Ticket List
 *
 * Displays the current user's tickets with filtering and pagination.
 *
 * Available variables:
 *
 * @var array $tickets      Array of ticket objects.
 * @var int $total        Total number of matching tickets.
 * @var int $per_page     Tickets per page.
 * @var int $current_page Current page number.
 * @var string $status       Active status filter (or empty for all).
 *
 * @since   1.0.0
 */
if (! defined('ABSPATH')) {
    exit;
}

$statuses = \Escalated\Helpers\Enums::ticket_statuses();
$total_pages = max(1, (int) ceil($total / max(1, $per_page)));
$base_url = remove_query_arg(['paged', 'status']);
?>

<div class="escalated-portal">
    <div class="escalated-actions-bar">
        <h1><?php esc_html_e('My Tickets', 'escalated'); ?></h1>
        <a href="<?php echo esc_url(add_query_arg('action', 'create')); ?>" class="escalated-btn escalated-btn--primary">
            <?php esc_html_e('Create New Ticket', 'escalated'); ?>
        </a>
    </div>

    <!-- Filter Bar -->
    <div class="escalated-filter-bar" style="margin-bottom: 16px; display: flex; gap: 8px; align-items: center;">
        <label for="escalated-status-filter" class="screen-reader-text"><?php esc_html_e('Filter by status', 'escalated'); ?></label>
        <select id="escalated-status-filter" class="escalated-select" onchange="window.location.href=this.value" style="width: auto; min-width: 160px;">
            <option value="<?php echo esc_url(remove_query_arg('status', $base_url)); ?>"<?php selected($status, ''); ?>>
                <?php esc_html_e('All Statuses', 'escalated'); ?>
            </option>
            <?php foreach ($statuses as $key => $meta) { ?>
                <option value="<?php echo esc_url(add_query_arg('status', $key, $base_url)); ?>"<?php selected($status, $key); ?>>
                    <?php echo esc_html($meta['label']); ?>
                </option>
            <?php } ?>
        </select>
    </div>

    <?php if (empty($tickets)) { ?>
        <div class="escalated-empty-state">
            <p><?php esc_html_e('You have no tickets yet.', 'escalated'); ?></p>
            <a href="<?php echo esc_url(add_query_arg('action', 'create')); ?>" class="escalated-btn escalated-btn--primary">
                <?php esc_html_e('Create Your First Ticket', 'escalated'); ?>
            </a>
        </div>
    <?php } else { ?>
        <!-- Desktop Table -->
        <table class="escalated-ticket-list">
            <thead>
                <tr>
                    <th><?php esc_html_e('Reference', 'escalated'); ?></th>
                    <th><?php esc_html_e('Subject', 'escalated'); ?></th>
                    <th><?php esc_html_e('Status', 'escalated'); ?></th>
                    <th><?php esc_html_e('Priority', 'escalated'); ?></th>
                    <th><?php esc_html_e('Date', 'escalated'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $ticket) {
                    $priorities = \Escalated\Helpers\Enums::ticket_priorities();
                    ?>
                    <tr class="escalated-ticket-row">
                        <td class="escalated-ticket-ref"><?php echo esc_html($ticket->reference); ?></td>
                        <td class="escalated-ticket-subject">
                            <a href="<?php echo esc_url(add_query_arg('ticket', $ticket->reference)); ?>">
                                <?php echo esc_html($ticket->subject); ?>
                            </a>
                        </td>
                        <td>
                            <span class="escalated-badge escalated-badge--<?php echo esc_attr($ticket->status); ?>">
                                <?php echo esc_html($statuses[$ticket->status]['label'] ?? $ticket->status); ?>
                            </span>
                        </td>
                        <td>
                            <span class="escalated-badge escalated-badge--<?php echo esc_attr($ticket->priority); ?>">
                                <?php echo esc_html($priorities[$ticket->priority]['label'] ?? $ticket->priority); ?>
                            </span>
                        </td>
                        <td class="escalated-ticket-date">
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($ticket->created_at))); ?>
                        </td>
                    </tr>

                    <!-- Mobile Card (hidden on desktop) -->
                    <tr class="escalated-ticket-card">
                        <td colspan="5">
                            <div class="escalated-ticket-card-header">
                                <span class="escalated-ticket-card-subject">
                                    <a href="<?php echo esc_url(add_query_arg('ticket', $ticket->reference)); ?>">
                                        <?php echo esc_html($ticket->subject); ?>
                                    </a>
                                </span>
                                <span class="escalated-badge escalated-badge--<?php echo esc_attr($ticket->status); ?>">
                                    <?php echo esc_html($statuses[$ticket->status]['label'] ?? $ticket->status); ?>
                                </span>
                            </div>
                            <div class="escalated-ticket-card-meta">
                                <span><?php echo esc_html($ticket->reference); ?></span>
                                <span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($ticket->created_at))); ?></span>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1) { ?>
            <div class="escalated-pagination">
                <?php if ($current_page > 1) { ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $current_page - 1)); ?>">
                        &laquo; <?php esc_html_e('Previous', 'escalated'); ?>
                    </a>
                <?php } ?>

                <?php for ($i = 1; $i <= $total_pages; $i++) { ?>
                    <?php if ($i === $current_page) { ?>
                        <span class="current"><?php echo esc_html($i); ?></span>
                    <?php } elseif ($i === 1 || $i === $total_pages || abs($i - $current_page) <= 2) { ?>
                        <a href="<?php echo esc_url(add_query_arg('paged', $i)); ?>">
                            <?php echo esc_html($i); ?>
                        </a>
                    <?php } elseif (abs($i - $current_page) === 3) { ?>
                        <span class="dots">&hellip;</span>
                    <?php } ?>
                <?php } ?>

                <?php if ($current_page < $total_pages) { ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $current_page + 1)); ?>">
                        <?php esc_html_e('Next', 'escalated'); ?> &raquo;
                    </a>
                <?php } ?>
            </div>
        <?php } ?>
    <?php } ?>
</div>
