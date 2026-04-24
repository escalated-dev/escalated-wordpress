<?php
/**
 * Admin template: Settings
 *
 * @var array $settings Key => value array of all settings.
 * @var string $message  Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

// Helper to get a setting value with default.
$s = function ($key, $default = '') use ($settings) {
    return $settings[$key] ?? $default;
};
?>
<div class="wrap">
    <h1><?php esc_html_e('Escalated Settings', 'escalated'); ?></h1>

    <?php if ($message === 'saved') { ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Settings saved successfully.', 'escalated'); ?></p>
        </div>
    <?php } ?>

    <form method="post">
        <input type="hidden" name="escalated_settings_action" value="save">
        <?php wp_nonce_field('escalated_settings_save', '_escalated_nonce'); ?>

        <!-- General Settings -->
        <h2 class="title"><?php esc_html_e('General', 'escalated'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="ticket_reference_prefix"><?php esc_html_e('Ticket Reference Prefix', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="text" id="ticket_reference_prefix" name="ticket_reference_prefix" class="regular-text"
                           value="<?php echo esc_attr($s('ticket_reference_prefix', 'ESC')); ?>">
                    <p class="description"><?php esc_html_e('Prefix for ticket references (e.g., ESC-00001).', 'escalated'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="default_priority"><?php esc_html_e('Default Priority', 'escalated'); ?></label>
                </th>
                <td>
                    <select id="default_priority" name="default_priority">
                        <?php
                        $priorities = \Escalated\Helpers\Enums::ticket_priorities();
foreach ($priorities as $key => $data) {
    ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($s('default_priority', 'medium'), $key); ?>>
                                <?php echo esc_html($data['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Guest Tickets', 'escalated'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="guest_tickets_enabled" value="1"
                            <?php checked($s('guest_tickets_enabled', '1'), '1'); ?>>
                        <?php esc_html_e('Allow guests (non-logged-in users) to submit tickets', 'escalated'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="guest_policy_mode"><?php esc_html_e('Guest policy', 'escalated'); ?></label>
                </th>
                <td>
                    <?php $guestPolicyMode = $s('guest_policy_mode', 'unassigned'); ?>
                    <select id="guest_policy_mode" name="guest_policy_mode">
                        <option value="unassigned" <?php selected($guestPolicyMode, 'unassigned'); ?>>
                            <?php esc_html_e('Unassigned (Contact carries the email; ticket has no owner)', 'escalated'); ?>
                        </option>
                        <option value="guest_user" <?php selected($guestPolicyMode, 'guest_user'); ?>>
                            <?php esc_html_e('Single shared guest user', 'escalated'); ?>
                        </option>
                        <option value="prompt_signup" <?php selected($guestPolicyMode, 'prompt_signup'); ?>>
                            <?php esc_html_e('Prompt signup (confirmation email embeds invite link)', 'escalated'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Who owns a ticket submitted via the public widget or inbound email. Applies at request time, so changes take effect on the next submission.', 'escalated'); ?>
                    </p>

                    <p style="margin-top: 12px;">
                        <label>
                            <?php esc_html_e('Shared guest user ID:', 'escalated'); ?>
                            <input type="number" min="1" name="guest_policy_user_id"
                                value="<?php echo esc_attr($s('guest_policy_user_id', '')); ?>"
                                class="small-text">
                        </label>
                        <span class="description">
                            <?php esc_html_e('Required when mode is "Single shared guest user".', 'escalated'); ?>
                        </span>
                    </p>

                    <p style="margin-top: 12px;">
                        <label style="display: block;">
                            <?php esc_html_e('Signup URL template (prompt_signup mode only):', 'escalated'); ?>
                            <input type="url" name="guest_policy_signup_url_template"
                                value="<?php echo esc_attr($s('guest_policy_signup_url_template', '')); ?>"
                                class="regular-text"
                                placeholder="https://app.example.com/register?email={{email}}">
                        </label>
                        <span class="description">
                            <?php esc_html_e('Optional. Use {{email}} as a placeholder for the guest email.', 'escalated'); ?>
                        </span>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Powered by Escalated', 'escalated'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="show_powered_by" value="1"
                            <?php checked($s('show_powered_by', '1'), '1'); ?>>
                        <?php esc_html_e('Show "Powered by Escalated" footer on customer portal pages', 'escalated'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="panel_theme"><?php esc_html_e('Panel Theme', 'escalated'); ?></label>
                </th>
                <td>
                    <select id="panel_theme" name="panel_theme">
                        <option value="auto" <?php selected($s('panel_theme', 'auto'), 'auto'); ?>><?php esc_html_e('Auto (follows system preference)', 'escalated'); ?></option>
                        <option value="light" <?php selected($s('panel_theme'), 'light'); ?>><?php esc_html_e('Light', 'escalated'); ?></option>
                        <option value="dark" <?php selected($s('panel_theme'), 'dark'); ?>><?php esc_html_e('Dark', 'escalated'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Choose the color theme for the Escalated admin panel.', 'escalated'); ?></p>
                </td>
            </tr>
        </table>

        <!-- SLA Settings -->
        <h2 class="title"><?php esc_html_e('SLA & Automation', 'escalated'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="sla_warning_minutes"><?php esc_html_e('SLA Warning (minutes)', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="number" id="sla_warning_minutes" name="sla_warning_minutes" class="small-text" min="0"
                           value="<?php echo esc_attr($s('sla_warning_minutes', '30')); ?>">
                    <p class="description"><?php esc_html_e('Send a warning notification this many minutes before SLA breach.', 'escalated'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Auto-Close', 'escalated'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="auto_close_enabled" value="1"
                            <?php checked($s('auto_close_enabled', '0'), '1'); ?>>
                        <?php esc_html_e('Automatically close resolved tickets after a period of inactivity', 'escalated'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="auto_close_days"><?php esc_html_e('Auto-Close After (days)', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="number" id="auto_close_days" name="auto_close_days" class="small-text" min="1"
                           value="<?php echo esc_attr($s('auto_close_days', '7')); ?>">
                    <p class="description"><?php esc_html_e('Number of days after which resolved tickets are automatically closed.', 'escalated'); ?></p>
                </td>
            </tr>
        </table>

        <!-- Inbound Email Settings -->
        <h2 class="title"><?php esc_html_e('Inbound Email', 'escalated'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable Inbound Email', 'escalated'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="inbound_email_enabled" value="1"
                            <?php checked($s('inbound_email_enabled', '0'), '1'); ?>>
                        <?php esc_html_e('Allow ticket creation and replies via email', 'escalated'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="inbound_email_address"><?php esc_html_e('Email Address', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="email" id="inbound_email_address" name="inbound_email_address" class="regular-text"
                           value="<?php echo esc_attr($s('inbound_email_address')); ?>"
                           placeholder="support@example.com">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="inbound_email_server"><?php esc_html_e('IMAP Server', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="text" id="inbound_email_server" name="inbound_email_server" class="regular-text"
                           value="<?php echo esc_attr($s('inbound_email_server')); ?>"
                           placeholder="imap.example.com">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="inbound_email_port"><?php esc_html_e('IMAP Port', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="number" id="inbound_email_port" name="inbound_email_port" class="small-text"
                           value="<?php echo esc_attr($s('inbound_email_port', '993')); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="inbound_email_username"><?php esc_html_e('Username', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="text" id="inbound_email_username" name="inbound_email_username" class="regular-text"
                           value="<?php echo esc_attr($s('inbound_email_username')); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="inbound_email_password"><?php esc_html_e('Password', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="password" id="inbound_email_password" name="inbound_email_password" class="regular-text"
                           value="<?php echo esc_attr($s('inbound_email_password')); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="inbound_email_encryption"><?php esc_html_e('Encryption', 'escalated'); ?></label>
                </th>
                <td>
                    <select id="inbound_email_encryption" name="inbound_email_encryption">
                        <option value="ssl" <?php selected($s('inbound_email_encryption', 'ssl'), 'ssl'); ?>><?php esc_html_e('SSL', 'escalated'); ?></option>
                        <option value="tls" <?php selected($s('inbound_email_encryption'), 'tls'); ?>><?php esc_html_e('TLS', 'escalated'); ?></option>
                        <option value="none" <?php selected($s('inbound_email_encryption'), 'none'); ?>><?php esc_html_e('None', 'escalated'); ?></option>
                    </select>
                </td>
            </tr>
        </table>

        <!-- API Settings -->
        <h2 class="title"><?php esc_html_e('API & Webhooks', 'escalated'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="webhook_url"><?php esc_html_e('Webhook URL', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="url" id="webhook_url" name="webhook_url" class="regular-text"
                           value="<?php echo esc_attr($s('webhook_url')); ?>"
                           placeholder="https://example.com/webhook">
                    <p class="description"><?php esc_html_e('URL to send webhook notifications for ticket events.', 'escalated'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="webhook_secret"><?php esc_html_e('Webhook Secret', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="text" id="webhook_secret" name="webhook_secret" class="regular-text"
                           value="<?php echo esc_attr($s('webhook_secret')); ?>">
                    <p class="description"><?php esc_html_e('Secret key used to sign webhook payloads.', 'escalated'); ?></p>
                </td>
            </tr>
        </table>

        <!-- Notification Settings -->
        <h2 class="title"><?php esc_html_e('Notifications', 'escalated'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="notification_from_name"><?php esc_html_e('From Name', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="text" id="notification_from_name" name="notification_from_name" class="regular-text"
                           value="<?php echo esc_attr($s('notification_from_name', get_bloginfo('name'))); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="notification_from_email"><?php esc_html_e('From Email', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="email" id="notification_from_email" name="notification_from_email" class="regular-text"
                           value="<?php echo esc_attr($s('notification_from_email', get_option('admin_email'))); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Email Notifications', 'escalated'); ?></th>
                <td>
                    <fieldset>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="notification_new_ticket" value="1"
                                <?php checked($s('notification_new_ticket', '1'), '1'); ?>>
                            <?php esc_html_e('New ticket created', 'escalated'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="notification_ticket_reply" value="1"
                                <?php checked($s('notification_ticket_reply', '1'), '1'); ?>>
                            <?php esc_html_e('New reply on ticket', 'escalated'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="notification_ticket_assigned" value="1"
                                <?php checked($s('notification_ticket_assigned', '1'), '1'); ?>>
                            <?php esc_html_e('Ticket assigned to agent', 'escalated'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 6px;">
                            <input type="checkbox" name="notification_sla_warning" value="1"
                                <?php checked($s('notification_sla_warning', '1'), '1'); ?>>
                            <?php esc_html_e('SLA warning (approaching deadline)', 'escalated'); ?>
                        </label>
                        <label style="display: block;">
                            <input type="checkbox" name="notification_sla_breach" value="1"
                                <?php checked($s('notification_sla_breach', '1'), '1'); ?>>
                            <?php esc_html_e('SLA breach', 'escalated'); ?>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>

        <!-- Attachment Settings -->
        <h2 class="title"><?php esc_html_e('Attachments', 'escalated'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="max_attachment_size_kb"><?php esc_html_e('Max File Size (KB)', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="number" id="max_attachment_size_kb" name="max_attachment_size_kb" class="small-text" min="0"
                           value="<?php echo esc_attr($s('max_attachment_size_kb', '10240')); ?>">
                    <p class="description"><?php esc_html_e('Maximum file size for attachments in kilobytes (10240 = 10 MB).', 'escalated'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="max_attachments_per_reply"><?php esc_html_e('Max Attachments Per Reply', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="number" id="max_attachments_per_reply" name="max_attachments_per_reply" class="small-text" min="1"
                           value="<?php echo esc_attr($s('max_attachments_per_reply', '5')); ?>">
                </td>
            </tr>
        </table>

        <!-- Maintenance Settings -->
        <h2 class="title"><?php esc_html_e('Maintenance', 'escalated'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="activity_purge_days"><?php esc_html_e('Activity Log Retention (days)', 'escalated'); ?></label>
                </th>
                <td>
                    <input type="number" id="activity_purge_days" name="activity_purge_days" class="small-text" min="0"
                           value="<?php echo esc_attr($s('activity_purge_days', '90')); ?>">
                    <p class="description"><?php esc_html_e('Activity log entries older than this will be automatically deleted. Set to 0 to keep forever.', 'escalated'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Settings', 'escalated')); ?>
    </form>
</div>
