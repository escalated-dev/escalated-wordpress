<?php

namespace Escalated\Admin;

use Escalated\Models\AuditLog;
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

        if (! current_user_can('escalated_settings_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? '')), 'escalated_settings_save')) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }

        $this->persist(wp_unslash($_POST));

        $redirect = admin_url('admin.php?page=escalated-settings&message=saved');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * The editable settings keys and their sanitizers.
     *
     * @return array<string, string>
     */
    private function fields(): array
    {
        return [
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

            // Public-ticket guest policy
            'guest_policy_mode' => 'sanitize_text_field',
            'guest_policy_user_id' => 'absint',
            'guest_policy_signup_url_template' => 'esc_url_raw',

            // Maintenance
            'activity_purge_days' => 'absint',
        ];
    }

    /**
     * Persist a submitted (already unslashed) settings payload and write a
     * single `settings.updated` audit entry capturing the keys that changed.
     *
     * Extracted from handle_save() so it is unit-testable without the
     * redirect/exit at the HTTP boundary. Webhook fields (webhook_url,
     * webhook_secret) flow through here too, so webhook changes are audited.
     *
     * @param  array<string, mixed>  $input
     */
    public function persist(array $input): void
    {
        $fields = $this->fields();

        $audit_keys = array_merge(
            array_keys($fields),
            ['guest_policy_mode', 'guest_policy_user_id', 'guest_policy_signup_url_template']
        );

        // Snapshot the prior values so we can diff after saving.
        $before = [];
        foreach ($audit_keys as $key) {
            $before[$key] = Setting::get($key);
        }

        foreach ($fields as $key => $sanitizer) {
            if (isset($input[$key])) {
                $value = call_user_func($sanitizer, $input[$key]);

                if ($key === 'ticket_reference_prefix' && ! $this->is_valid_ticket_reference_prefix((string) $value)) {
                    continue;
                }

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

        // Guest policy mode: reject unknown values, clear fields that don't apply.
        $mode = Setting::get('guest_policy_mode', 'unassigned');
        if (! in_array($mode, ['unassigned', 'guest_user', 'prompt_signup'], true)) {
            Setting::set('guest_policy_mode', 'unassigned');
            $mode = 'unassigned';
        }
        if ($mode !== 'guest_user') {
            Setting::set('guest_policy_user_id', '');
        }
        if ($mode !== 'prompt_signup') {
            Setting::set('guest_policy_signup_url_template', '');
        }

        // Diff and record a single audit entry for the changed keys. Secret
        // values are recorded as a redacted marker rather than plaintext.
        $secret_keys = ['webhook_secret', 'inbound_email_password'];
        $old_values = [];
        $new_values = [];
        foreach ($audit_keys as $key) {
            $after = Setting::get($key);
            if ((string) $after === (string) $before[$key]) {
                continue;
            }
            if (in_array($key, $secret_keys, true)) {
                $old_values[$key] = $before[$key] !== null && $before[$key] !== '' ? '********' : null;
                $new_values[$key] = $after !== null && $after !== '' ? '********' : null;
            } else {
                $old_values[$key] = $before[$key];
                $new_values[$key] = $after;
            }
        }

        if ($new_values !== []) {
            AuditLog::record('settings.updated', 'Settings', null, $old_values, $new_values);
        }
    }

    /**
     * Ticket references are generated as PREFIX-00001, so the prefix itself
     * must not contain a hyphen.
     */
    private function is_valid_ticket_reference_prefix(string $value): bool
    {
        return $value !== '' && strlen($value) <= 10 && strpos($value, '-') === false;
    }
}
