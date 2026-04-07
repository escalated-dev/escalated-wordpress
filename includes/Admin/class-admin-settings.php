<?php

namespace Escalated\Admin;

use Escalated\Models\Setting;

class Admin_Settings
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_save']);
    }

    /**
     * Render the settings admin page.
     */
    public function render(): void
    {
        $settings = Setting::all();
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

        include ESCALATED_PLUGIN_DIR.'templates/admin/settings.php';
    }

    /**
     * Handle settings save via POST.
     */
    public function handle_save(): void
    {
        if (! isset($_POST['escalated_settings_action'])) {
            return;
        }

        if (! current_user_can('escalated_manage_settings')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_settings_save')) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }

        $fields = [
            // General
            'ticket_reference_prefix' => 'sanitize_text_field',
            'default_priority' => 'sanitize_text_field',
            'guest_tickets_enabled' => 'absint',
            'show_powered_by' => 'absint',
            'panel_theme' => 'sanitize_text_field',

            // SLA
            'auto_close_enabled' => 'absint',
            'auto_close_days' => 'absint',
            'sla_warning_minutes' => 'absint',

            // Inbound Email
            'inbound_email_enabled' => 'absint',
            'inbound_email_address' => 'sanitize_email',
            'inbound_email_server' => 'sanitize_text_field',
            'inbound_email_port' => 'absint',
            'inbound_email_username' => 'sanitize_text_field',
            'inbound_email_password' => 'sanitize_text_field',
            'inbound_email_encryption' => 'sanitize_text_field',

            // API
            'webhook_url' => 'esc_url_raw',
            'webhook_secret' => 'sanitize_text_field',

            // Notifications
            'notification_new_ticket' => 'absint',
            'notification_ticket_reply' => 'absint',
            'notification_ticket_assigned' => 'absint',
            'notification_sla_warning' => 'absint',
            'notification_sla_breach' => 'absint',
            'notification_from_name' => 'sanitize_text_field',
            'notification_from_email' => 'sanitize_email',

            // Attachments
            'max_attachment_size_kb' => 'absint',
            'max_attachments_per_reply' => 'absint',

            // Maintenance
            'activity_purge_days' => 'absint',
        ];

        foreach ($fields as $key => $sanitizer) {
            if (isset($_POST[$key])) {
                $value = wp_unslash($_POST[$key]);
                $value = call_user_func($sanitizer, $value);
                Setting::set($key, (string) $value);
            } else {
                // Checkbox fields: if not present, store 0.
                if (in_array($sanitizer, ['absint'], true) && in_array($key, [
                    'guest_tickets_enabled',
                    'show_powered_by',
                    'auto_close_enabled',
                    'inbound_email_enabled',
                    'notification_new_ticket',
                    'notification_ticket_reply',
                    'notification_ticket_assigned',
                    'notification_sla_warning',
                    'notification_sla_breach',
                ], true)) {
                    Setting::set($key, '0');
                }
            }
        }

        $redirect = admin_url('admin.php?page=escalated-settings&message=saved');
        wp_safe_redirect($redirect);
        exit;
    }
}
