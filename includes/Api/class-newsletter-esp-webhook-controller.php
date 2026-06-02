<?php

namespace Escalated\Api;

use Escalated\Services\Newsletter\NewsletterConfig;
use Escalated\Services\Newsletter\NewsletterTracker;
use Escalated\Services\Newsletter\BounceSuppressionStore;
use WP_REST_Request;
use WP_REST_Server;

class Newsletter_Esp_Webhook_Controller extends Base_Controller
{
    public function register_routes(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }

        $ns = $this->namespace;
        $gate = function () {
            if (! NewsletterConfig::is_enabled()) {
                return new \WP_Error('escalated_not_found', __('Not found.', 'escalated'), ['status' => 404]);
            }

            return true;
        };

        foreach (['postmark', 'mailgun', 'ses', 'sendgrid'] as $provider) {
            register_rest_route($ns, '/webhooks/newsletter/'.$provider, [[
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, $provider],
                'permission_callback' => $gate,
            ]]);
        }
    }

    public function postmark(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        $tracker = $this->tracker();
        $type = (string) ($body['RecordType'] ?? '');
        $token = $this->token_from_message_id((string) ($body['MessageID'] ?? ''));
        match ($type) {
            'Open' => $tracker->record_open($token),
            'Click' => $tracker->record_click($token, (string) ($body['OriginalLink'] ?? '')),
            'Bounce' => $tracker->record_bounce(
                $token,
                $this->is_hard_postmark((string) ($body['Type'] ?? '')) ? 'hard' : 'soft',
                (string) ($body['Description'] ?? '')
            ),
            'SpamComplaint' => $tracker->record_complaint($token),
            default => null,
        };

        return $this->success(['ok' => true]);
    }

    public function mailgun(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        $tracker = $this->tracker();
        $event = (string) ($body['event-data']['event'] ?? '');
        $message_id = (string) ($body['event-data']['message']['headers']['message-id'] ?? '');
        $token = $this->token_from_message_id($message_id);
        match ($event) {
            'opened' => $tracker->record_open($token),
            'clicked' => $tracker->record_click($token, (string) ($body['event-data']['url'] ?? '')),
            'failed' => $tracker->record_bounce(
                $token,
                ($body['event-data']['severity'] ?? '') === 'permanent' ? 'hard' : 'soft',
                (string) ($body['event-data']['delivery-status']['description'] ?? '')
            ),
            'complained' => $tracker->record_complaint($token),
            default => null,
        };

        return $this->success(['ok' => true]);
    }

    public function ses(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        $message = $body['Message'] ?? null;
        if (is_string($message)) {
            $message = json_decode($message, true);
        }
        if (! is_array($message)) {
            return $this->success(['ok' => true]);
        }
        $tracker = $this->tracker();
        $token = $this->token_from_message_id((string) ($message['mail']['messageId'] ?? ''));
        $event_type = $message['eventType'] ?? null;
        match ($event_type) {
            'Open' => $tracker->record_open($token),
            'Click' => $tracker->record_click($token, (string) ($message['click']['link'] ?? '')),
            'Bounce' => $tracker->record_bounce(
                $token,
                ($message['bounce']['bounceType'] ?? '') === 'Permanent' ? 'hard' : 'soft',
                (string) ($message['bounce']['bounceSubType'] ?? '')
            ),
            'Complaint' => $tracker->record_complaint($token),
            default => null,
        };

        return $this->success(['ok' => true]);
    }

    public function sendgrid(WP_REST_Request $request)
    {
        $events = $this->parse_body($request);
        if (! is_array($events)) {
            $events = [];
        }
        if (isset($events['event'])) {
            $events = [$events];
        }
        $tracker = $this->tracker();
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $message_id = (string) ($event['smtp-id'] ?? $event['sg_message_id'] ?? '');
            $token = $this->token_from_message_id($message_id);
            match ($event['event'] ?? null) {
                'open' => $tracker->record_open($token),
                'click' => $tracker->record_click($token, (string) ($event['url'] ?? '')),
                'bounce' => $tracker->record_bounce(
                    $token,
                    ($event['type'] ?? '') === 'blocked' ? 'hard' : 'soft',
                    (string) ($event['reason'] ?? '')
                ),
                'dropped' => $tracker->record_bounce($token, 'hard', (string) ($event['reason'] ?? '')),
                'spamreport' => $tracker->record_complaint($token),
                default => null,
            };
        }

        return $this->success(['ok' => true]);
    }

    private function tracker(): NewsletterTracker
    {
        return new NewsletterTracker(new BounceSuppressionStore);
    }

    private function is_hard_postmark(string $type): bool
    {
        return in_array($type, ['HardBounce', 'BadEmailAddress', 'BlockedRecipient'], true);
    }

    private function token_from_message_id(string $message_id): string
    {
        if (preg_match('/n-\d+-([A-Za-z0-9]+)@/', $message_id, $m)) {
            return $m[1];
        }
        $local = explode('@', $message_id)[0] ?? '';
        if (preg_match('/^n-\d+-([A-Za-z0-9]+)$/', $local, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function parse_body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }
        $body = $request->get_body_params();

        return is_array($body) ? $body : [];
    }
}
