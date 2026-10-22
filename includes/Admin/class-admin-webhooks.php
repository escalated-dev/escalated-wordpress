<?php

namespace Escalated\Admin;

use Escalated\Models\AuditLog;
use Escalated\Models\Webhook;
use Escalated\Models\WebhookDelivery;
use Escalated\Services\EventVocabulary;
use Escalated\Services\OutboundUrlGuard;
use Escalated\Services\WebhookDispatcher;

/**
 * Escalated > Webhooks: outbound webhook subscriptions and their delivery log.
 */
class Admin_Webhooks
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function render(): void
    {
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        $available_events = EventVocabulary::WEBHOOK_EVENTS;

        if ($action === 'deliveries' && $id && ($webhook = Webhook::find($id))) {
            $per_page = 25;
            $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
            $deliveries = WebhookDelivery::for_webhook($id, $per_page, ($paged - 1) * $per_page);
            $total = WebhookDelivery::count_for_webhook($id);
            include ESCALATED_PLUGIN_DIR.'templates/admin/webhook-deliveries.php';

            return;
        }

        $webhooks = Webhook::all();
        $edit_item = ($action === 'edit' && $id) ? Webhook::find($id) : null;
        $edit_events = $edit_item ? Webhook::subscribed_events($edit_item) : $available_events;

        include ESCALATED_PLUGIN_DIR.'templates/admin/webhooks.php';
    }

    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_webhook_action'])) {
            return;
        }

        if (! current_user_can('escalated_webhook_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $redirect = $this->process(wp_unslash($_POST));

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Apply a submitted (unslashed) form and return the URL to redirect to.
     * Split from handle_actions() so it can be tested without the exit.
     *
     * @param  array<string, mixed>  $input
     */
    public function process(array $input): string
    {
        $action = sanitize_key((string) ($input['escalated_webhook_action'] ?? ''));
        $id = absint($input['id'] ?? 0);
        $nonce = sanitize_text_field((string) ($input['_escalated_nonce'] ?? ''));
        $base = admin_url('admin.php?page=escalated-webhooks');

        $nonce_action = in_array($action, ['create'], true) ? 'escalated_webhook_create' : 'escalated_webhook_'.$action.'_'.$id;
        if (! wp_verify_nonce($nonce, $nonce_action)) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }

        switch ($action) {
            case 'create':
            case 'update':
                $data = $this->validated($input, $action === 'update' ? Webhook::find($id) : null, $error);
                if ($data === null) {
                    $args = ['message' => $error];
                    if ($action === 'update') {
                        $args += ['action' => 'edit', 'id' => $id];
                    }

                    return add_query_arg($args, $base);
                }

                if ($action === 'create') {
                    $new_id = Webhook::create($data);
                    if (! $new_id) {
                        return add_query_arg('message', 'error', $base);
                    }
                    AuditLog::record('webhook.created', 'Webhook', $new_id, null, $this->audit_values($data));

                    return add_query_arg('message', 'created', $base);
                }

                $before = Webhook::find($id);
                if (! $before) {
                    return add_query_arg('message', 'error', $base);
                }
                Webhook::update($id, $data);
                AuditLog::record('webhook.updated', 'Webhook', $id, $this->audit_values([
                    'url' => $before->url,
                    'events' => Webhook::subscribed_events($before),
                    'secret' => $before->secret,
                    'active' => (int) $before->active,
                ]), $this->audit_values($data));

                return add_query_arg('message', 'updated', $base);

            case 'delete':
                $before = Webhook::find($id);
                if ($before) {
                    Webhook::delete($id);
                    AuditLog::record('webhook.deleted', 'Webhook', $id, ['url' => $before->url], null);
                }

                return add_query_arg('message', 'deleted', $base);

            case 'retry':
                $delivery = WebhookDelivery::find($id);
                $webhook_id = $delivery ? (int) $delivery->webhook_id : 0;
                $ok = (new WebhookDispatcher)->retry_delivery($id);

                return add_query_arg([
                    'action' => 'deliveries',
                    'id' => $webhook_id,
                    'message' => $ok ? 'retried' : 'error',
                ], $base);
        }

        return $base;
    }

    /**
     * The fields to save, or null with $error set to a message key.
     *
     * @param  array<string, mixed>  $input
     */
    private function validated(array $input, ?object $existing, ?string &$error): ?array
    {
        $url = esc_url_raw(trim((string) ($input['url'] ?? '')), ['http', 'https']);
        if ($url === '' || strlen($url) > 500) {
            $error = 'invalid_url';

            return null;
        }
        if (! (new OutboundUrlGuard)->allows($url)) {
            $error = 'unsafe_url';

            return null;
        }

        $events = array_values(array_intersect(
            EventVocabulary::canonical_list(array_map('sanitize_text_field', (array) ($input['events'] ?? []))),
            EventVocabulary::WEBHOOK_EVENTS
        ));
        if ($events === []) {
            $error = 'no_events';

            return null;
        }

        $secret = sanitize_text_field((string) ($input['secret'] ?? ''));
        // The form never receives the stored secret, so a blank one on edit
        // keeps it; `clear_secret` (a truthy flag, not just any value)
        // removes it.
        $clear = filter_var($input['clear_secret'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($existing && $clear) {
            $secret = '';
        } elseif ($existing && $secret === '') {
            $secret = (string) ($existing->secret ?? '');
        }

        return [
            'url' => $url,
            'events' => $events,
            'secret' => substr($secret, 0, 255),
            'active' => ! empty($input['active']) ? 1 : 0,
        ];
    }

    private function audit_values(array $data): array
    {
        return [
            'url' => $data['url'],
            'events' => array_values((array) $data['events']),
            'secret' => ($data['secret'] ?? '') !== '' && $data['secret'] !== null ? '********' : null,
            'active' => (int) $data['active'],
        ];
    }
}
