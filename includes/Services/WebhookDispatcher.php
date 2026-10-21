<?php

namespace Escalated\Services;

use Escalated\Models\Setting;
use Escalated\Models\Webhook;
use Escalated\Models\WebhookDelivery;

/**
 * Outbound webhook subscriptions, after escalated-laravel's WebhookDispatcher.
 *
 * dispatch() only queues: it writes one delivery row per subscribed, active
 * webhook and asks WP-Cron (or Action Scheduler, when installed) to send them,
 * so a slow receiver never holds up the request that fired the event.
 * process_due() sends due deliveries. A delivery that fails is retried twice
 * with backoff (120 s, then 240 s), as Laravel does.
 *
 * The URL guard runs when a webhook is saved and again before every attempt:
 * a row can predate the check, and a host can be re-pointed at an internal
 * address after it passed. Redirects are never followed, and the connection
 * is pinned to the address that was checked.
 */
class WebhookDispatcher
{
    public const CRON_HOOK = 'escalated_process_webhook_deliveries';

    public const KICK_HOOK = 'escalated_send_webhook_deliveries';

    private OutboundUrlGuard $guard;

    public function __construct(?OutboundUrlGuard $guard = null)
    {
        $this->guard = $guard ?? new OutboundUrlGuard;
    }

    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run_cron']);
        add_action(self::KICK_HOOK, [self::class, 'run_cron']);
    }

    public static function run_cron(): void
    {
        (new self)->process_due();
    }

    /**
     * Queue an event for every active webhook subscribed to it.
     *
     * @return int The number of deliveries queued.
     */
    public function dispatch(string $event, array $payload): int
    {
        self::migrate_legacy_setting();

        $event = EventVocabulary::canonical($event);
        $occurred_at = gmdate('Y-m-d H:i:s');
        $queued = 0;

        foreach (Webhook::active() as $webhook) {
            if (Webhook::subscribed_to($webhook, $event)
                && WebhookDelivery::create((int) $webhook->id, $event, $payload, $occurred_at)) {
                $queued++;
            }
        }

        if ($queued > 0) {
            $this->schedule_send();
        }

        return $queued;
    }

    /**
     * Send every delivery whose attempt is due.
     *
     * @return int The number of deliveries attempted.
     */
    public function process_due(int $limit = 25): int
    {
        $sent = 0;
        foreach (WebhookDelivery::due($limit) as $delivery) {
            if (! WebhookDelivery::claim($delivery)) {
                continue;
            }
            $this->attempt(WebhookDelivery::find((int) $delivery->id));
            $sent++;
        }

        return $sent;
    }

    /**
     * Queue a fresh copy of a delivery (the admin's Retry button).
     *
     * @return int|false The new delivery id.
     */
    public function retry_delivery(int $delivery_id)
    {
        $delivery = WebhookDelivery::find($delivery_id);
        if (! $delivery || ! Webhook::find((int) $delivery->webhook_id)) {
            return false;
        }

        $payload = json_decode((string) $delivery->payload, true);
        $id = WebhookDelivery::create((int) $delivery->webhook_id, (string) $delivery->event, is_array($payload) ? $payload : [], $delivery->occurred_at ?? null);
        if ($id) {
            $this->schedule_send();
        }

        return $id;
    }

    /**
     * Make one attempt at a claimed delivery and record the outcome.
     */
    public function attempt(?object $delivery): void
    {
        if (! $delivery) {
            return;
        }

        $attempt = (int) $delivery->attempts + 1;
        $webhook = Webhook::find((int) $delivery->webhook_id);
        if (! $webhook) {
            WebhookDelivery::update($delivery->id, ['status' => 'failed', 'attempts' => $attempt, 'response_code' => 0, 'response_body' => 'Not sent: the webhook was deleted.']);

            return;
        }

        $event = (string) $delivery->event;
        $payload = json_decode((string) $delivery->payload, true);
        $payload = is_array($payload) ? $payload : [];
        $url = (string) $webhook->url;

        $address = $this->guard->public_address_for($url);
        if ($address === null) {
            // Retrying cannot help: the URL is refused, not unreachable.
            WebhookDelivery::update($delivery->id, [
                'status' => 'failed',
                'attempts' => $attempt,
                'response_code' => 0,
                'response_body' => 'Not sent: the URL must use http or https and resolve to a public address.',
            ]);
            do_action('escalated_webhook_failed', $event, $payload, 'Refused: the URL does not resolve to a public address.', $webhook);

            return;
        }

        $occurred_at = ! empty($delivery->occurred_at) ? strtotime($delivery->occurred_at.' UTC') : time();
        $body = wp_json_encode([
            'event' => $event,
            'payload' => $payload,
            // Unix time shifted by the site's timezone, kept as-is for existing receivers.
            'timestamp' => $occurred_at + (int) round((float) get_option('gmt_offset') * HOUR_IN_SECONDS),
            // ISO-8601 UTC, matching the Laravel reference's `timestamp`.
            'occurred_at' => gmdate('c', $occurred_at),
        ]);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Escalated-Event' => $event,
            'X-Escalated-Delivery' => (string) $delivery->id,
        ];
        if (! empty($webhook->secret)) {
            $headers['X-Escalated-Signature'] = hash_hmac('sha256', $body, (string) $webhook->secret);
        }

        /**
         * Filter the webhook request arguments before sending.
         *
         * @param  array  $args  The wp_remote_post arguments.
         * @param  string  $event  The event name.
         * @param  array  $payload  The event payload.
         * @param  object  $webhook  The webhook row.
         */
        $args = apply_filters('escalated_webhook_args', [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'sslverify' => true,
        ], $event, $payload, $webhook);

        // A redirect would carry the request to an address the guard refused.
        $args['redirection'] = 0;
        $args['reject_unsafe_urls'] = true;

        $pin = $this->pin_connection($url, $address);
        add_action('http_api_curl', $pin, 10, 1);
        try {
            $response = wp_safe_remote_post($url, $args);
        } finally {
            remove_action('http_api_curl', $pin, 10);
        }

        if (is_wp_error($response)) {
            $this->record_failure($delivery, $attempt, 0, $response->get_error_message());
            do_action('escalated_webhook_failed', $event, $payload, $response->get_error_message(), $webhook);

            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $response_body = substr((string) wp_remote_retrieve_body($response), 0, 2000);

        if ($code >= 200 && $code < 300) {
            WebhookDelivery::update($delivery->id, [
                'status' => 'delivered',
                'attempts' => $attempt,
                'response_code' => $code,
                'response_body' => $response_body,
                'delivered_at' => gmdate('Y-m-d H:i:s'),
            ]);
            do_action('escalated_webhook_sent', $event, $payload, $code, $webhook);

            return;
        }

        $this->record_failure($delivery, $attempt, $code, $response_body);
        do_action('escalated_webhook_failed', $event, $payload, sprintf('HTTP %d', $code), $webhook);
    }

    /**
     * Fold the single webhook_url / webhook_secret setting of earlier
     * versions into a subscription to every event, then clear the setting.
     * Runs on upgrade and before each dispatch, so a URL set in code keeps
     * working too.
     */
    public static function migrate_legacy_setting(): ?int
    {
        $url = trim((string) Setting::get('webhook_url', ''));
        if ($url === '') {
            return null;
        }

        $id = Webhook::create([
            'url' => $url,
            'events' => EventVocabulary::WEBHOOK_EVENTS,
            'secret' => (string) Setting::get('webhook_secret', ''),
            'active' => 1,
        ]);

        if ($id) {
            Setting::set('webhook_url', '');
            Setting::set('webhook_secret', '');
        }

        return $id ?: null;
    }

    private function record_failure(object $delivery, int $attempt, int $code, string $message): void
    {
        $retry = $attempt < WebhookDelivery::MAX_ATTEMPTS;

        WebhookDelivery::update($delivery->id, [
            'status' => $retry ? 'pending' : 'failed',
            'attempts' => $attempt,
            'response_code' => $code,
            'response_body' => substr($message, 0, 2000),
            // 120 s before the second attempt, 240 s before the third.
            'next_attempt_at' => $retry ? gmdate('Y-m-d H:i:s', time() + (int) pow(2, $attempt + 1) * 30) : null,
        ]);
    }

    /**
     * Ask for the queue to be worked soon. Action Scheduler runs it as an
     * async action when it is installed; otherwise a one-off WP-Cron event
     * does. The every-minute cron sweep picks up retries and anything missed.
     */
    private function schedule_send(): void
    {
        if (function_exists('as_enqueue_async_action')) {
            if (! function_exists('as_has_scheduled_action') || ! as_has_scheduled_action(self::KICK_HOOK, [], 'escalated')) {
                as_enqueue_async_action(self::KICK_HOOK, [], 'escalated');
            }

            return;
        }

        if (! wp_next_scheduled(self::KICK_HOOK)) {
            wp_schedule_single_event(time(), self::KICK_HOOK);
        }
    }

    /**
     * Connect to the address the guard checked, so a DNS answer that changes
     * between the check and the request cannot substitute a private one.
     */
    private function pin_connection(string $url, string $address): callable
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $port = wp_parse_url($url, PHP_URL_PORT)
            ?: (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);

        return static function ($handle) use ($host, $port, $address): void {
            if (! defined('CURLOPT_RESOLVE') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
                return;
            }
            $ip = strpos($address, ':') !== false ? '['.$address.']' : $address;
            curl_setopt($handle, CURLOPT_RESOLVE, ["{$host}:{$port}:{$ip}"]);
        };
    }
}
