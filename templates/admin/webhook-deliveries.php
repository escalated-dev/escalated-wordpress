<?php
/**
 * Admin template: Webhook delivery log
 *
 * @var object $webhook The webhook.
 * @var array $deliveries Deliveries on this page, newest first.
 * @var int $total Total deliveries.
 * @var int $paged Current page.
 * @var int $per_page Rows per page.
 * @var string $message Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

$pages = max(1, (int) ceil($total / $per_page));
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Webhook Deliveries', 'escalated'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-webhooks')); ?>" class="page-title-action"><?php esc_html_e('Back to Webhooks', 'escalated'); ?></a>
    <hr class="wp-header-end">

    <p><code><?php echo esc_html($webhook->url); ?></code></p>

    <?php if ($message === 'retried') { ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Delivery queued again.', 'escalated'); ?></p></div>
    <?php } elseif ($message === 'error') { ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('An error occurred. Please try again.', 'escalated'); ?></p></div>
    <?php } ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width: 60px;"><?php esc_html_e('ID', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Event', 'escalated'); ?></th>
                <th scope="col" style="width: 90px;"><?php esc_html_e('Status', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Response', 'escalated'); ?></th>
                <th scope="col" style="width: 70px;"><?php esc_html_e('Attempts', 'escalated'); ?></th>
                <th scope="col" style="width: 160px;"><?php esc_html_e('Created (UTC)', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Response body', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($deliveries)) { ?>
                <tr><td colspan="8"><?php esc_html_e('No deliveries yet.', 'escalated'); ?></td></tr>
            <?php } else { ?>
                <?php foreach ($deliveries as $delivery) { ?>
                    <tr>
                        <td><?php echo esc_html($delivery->id); ?></td>
                        <td><code><?php echo esc_html($delivery->event); ?></code></td>
                        <td><?php echo esc_html($delivery->status); ?></td>
                        <td><?php echo $delivery->response_code !== null ? esc_html($delivery->response_code) : '&mdash;'; ?></td>
                        <td><?php echo esc_html($delivery->attempts); ?></td>
                        <td><?php echo esc_html($delivery->created_at); ?></td>
                        <td><code style="word-break: break-all;"><?php echo esc_html(mb_substr((string) $delivery->response_body, 0, 200)); ?></code></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="escalated_webhook_action" value="retry">
                                <input type="hidden" name="id" value="<?php echo esc_attr($delivery->id); ?>">
                                <?php wp_nonce_field('escalated_webhook_retry_'.$delivery->id, '_escalated_nonce'); ?>
                                <button type="submit" class="button button-small"><?php esc_html_e('Retry', 'escalated'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
    </table>

    <?php if ($pages > 1) { ?>
        <div class="tablenav"><div class="tablenav-pages">
            <?php
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $paged,
                'total' => $pages,
            ]));
        ?>
        </div></div>
    <?php } ?>
</div>
