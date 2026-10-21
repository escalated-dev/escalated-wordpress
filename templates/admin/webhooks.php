<?php
/**
 * Admin template: Webhooks
 *
 * @var array $webhooks All webhook rows.
 * @var object|null $edit_item Webhook being edited (or null).
 * @var array $edit_events Events ticked in the form.
 * @var array $available_events Every event a webhook can subscribe to.
 * @var string $message Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

use Escalated\Models\Webhook;
use Escalated\Models\WebhookDelivery;

$messages = [
    'created' => ['success', __('Webhook created.', 'escalated')],
    'updated' => ['success', __('Webhook updated.', 'escalated')],
    'deleted' => ['success', __('Webhook deleted.', 'escalated')],
    'invalid_url' => ['error', __('Enter a valid http or https URL.', 'escalated')],
    'unsafe_url' => ['error', __('The webhook URL must use http or https and resolve to a public address.', 'escalated')],
    'no_events' => ['error', __('Choose at least one event.', 'escalated')],
    'error' => ['error', __('An error occurred. Please try again.', 'escalated')],
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Webhooks', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message && isset($messages[$message])) { ?>
        <div class="notice notice-<?php echo esc_attr($messages[$message][0]); ?> is-dismissible">
            <p><?php echo esc_html($messages[$message][1]); ?></p>
        </div>
    <?php } ?>

    <p class="description">
        <?php esc_html_e('Each webhook receives a signed JSON POST for the events it subscribes to. Deliveries are sent in the background and retried twice on failure.', 'escalated'); ?>
    </p>

    <table class="wp-list-table widefat fixed striped" style="margin: 15px 0 20px;">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('URL', 'escalated'); ?></th>
                <th scope="col" style="width: 90px;"><?php esc_html_e('Events', 'escalated'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Active', 'escalated'); ?></th>
                <th scope="col" style="width: 180px;"><?php esc_html_e('Last delivery', 'escalated'); ?></th>
                <th scope="col" style="width: 220px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($webhooks)) { ?>
                <tr>
                    <td colspan="5"><?php esc_html_e('No webhooks yet.', 'escalated'); ?></td>
                </tr>
            <?php } else { ?>
                <?php foreach ($webhooks as $webhook) { ?>
                    <?php $last = WebhookDelivery::latest_for_webhook((int) $webhook->id); ?>
                    <tr>
                        <td><code><?php echo esc_html($webhook->url); ?></code></td>
                        <td><?php echo esc_html(count(Webhook::subscribed_events($webhook))); ?></td>
                        <td>
                            <?php if ($webhook->active) { ?>
                                <span class="escalated-text-success"><?php esc_html_e('Yes', 'escalated'); ?></span>
                            <?php } else { ?>
                                <span class="escalated-text-danger"><?php esc_html_e('No', 'escalated'); ?></span>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($last) { ?>
                                <?php echo esc_html($last->event.' · '.$last->status.($last->response_code ? ' ('.$last->response_code.')' : '')); ?>
                            <?php } else { ?>
                                <em><?php esc_html_e('Never', 'escalated'); ?></em>
                            <?php } ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-webhooks&action=edit&id='.$webhook->id)); ?>" class="button button-small"><?php esc_html_e('Edit', 'escalated'); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-webhooks&action=deliveries&id='.$webhook->id)); ?>" class="button button-small"><?php esc_html_e('Deliveries', 'escalated'); ?></a>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this webhook and its delivery log?', 'escalated')); ?>');">
                                <input type="hidden" name="escalated_webhook_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($webhook->id); ?>">
                                <?php wp_nonce_field('escalated_webhook_delete_'.$webhook->id, '_escalated_nonce'); ?>
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
            <?php echo $edit_item ? esc_html__('Edit Webhook', 'escalated') : esc_html__('Add Webhook', 'escalated'); ?>
        </h2>

        <form method="post">
            <?php if ($edit_item) { ?>
                <input type="hidden" name="escalated_webhook_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr($edit_item->id); ?>">
                <?php wp_nonce_field('escalated_webhook_update_'.$edit_item->id, '_escalated_nonce'); ?>
            <?php } else { ?>
                <input type="hidden" name="escalated_webhook_action" value="create">
                <?php wp_nonce_field('escalated_webhook_create', '_escalated_nonce'); ?>
            <?php } ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="webhook-url"><?php esc_html_e('URL', 'escalated'); ?></label></th>
                    <td>
                        <input type="url" id="webhook-url" name="url" class="regular-text" required maxlength="500"
                               placeholder="https://example.com/webhook"
                               value="<?php echo esc_attr($edit_item->url ?? ''); ?>">
                        <p class="description"><?php esc_html_e('Must use http or https and resolve to a public address. Redirects are not followed.', 'escalated'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="webhook-secret"><?php esc_html_e('Secret', 'escalated'); ?></label></th>
                    <td>
                        <input type="password" id="webhook-secret" name="secret" class="regular-text" autocomplete="new-password" maxlength="255"
                               placeholder="<?php echo esc_attr($edit_item && ! empty($edit_item->secret) ? __('Unchanged', 'escalated') : ''); ?>">
                        <?php if ($edit_item && ! empty($edit_item->secret)) { ?>
                            <label style="margin-left: 8px;"><input type="checkbox" name="clear_secret" value="1"> <?php esc_html_e('Remove secret', 'escalated'); ?></label>
                        <?php } ?>
                        <p class="description"><?php esc_html_e('Signs each body with HMAC-SHA256 in the X-Escalated-Signature header.', 'escalated'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Events', 'escalated'); ?></th>
                    <td>
                        <fieldset>
                            <?php foreach ($available_events as $event) { ?>
                                <label style="display: inline-block; min-width: 230px; margin-bottom: 4px;">
                                    <input type="checkbox" name="events[]" value="<?php echo esc_attr($event); ?>" <?php checked(in_array($event, $edit_events, true)); ?>>
                                    <code><?php echo esc_html($event); ?></code>
                                </label>
                            <?php } ?>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Active', 'escalated'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="active" value="1" <?php checked($edit_item ? (int) $edit_item->active : 1, 1); ?>>
                            <?php esc_html_e('Send events to this webhook', 'escalated'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p>
                <?php submit_button($edit_item ? __('Update Webhook', 'escalated') : __('Add Webhook', 'escalated'), 'primary', 'submit', false); ?>
                <?php if ($edit_item) { ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-webhooks')); ?>" class="button" style="margin-left: 5px;"><?php esc_html_e('Cancel', 'escalated'); ?></a>
                <?php } ?>
            </p>
        </form>
    </div>
</div>
