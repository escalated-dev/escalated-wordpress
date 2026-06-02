<?php

namespace Escalated\Api;

use Escalated\Models\Setting;
use Escalated\Services\Newsletter\NewsletterConfig;
use WP_REST_Request;
use WP_REST_Server;

class Newsletter_Settings_Controller extends Base_Controller
{
    use NewsletterHttp;

    private const KEYS = [
        'default_from' => 'string',
        'default_reply_to' => 'string',
        'default_theme' => 'string',
        'rate_limit_per_minute' => 'number',
        'batch_size' => 'number',
        'tracking_enabled' => 'boolean',
    ];

    public function register_routes(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }

        $ns = $this->namespace;
        $manage = [$this, 'newsletters_manage_check'];

        register_rest_route($ns, '/admin/newsletters/settings', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'show'], 'permission_callback' => $manage],
            ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'update'], 'permission_callback' => $manage],
        ]);
    }

    public function show(WP_REST_Request $request)
    {
        unset($request);
        $settings = [];
        foreach (array_keys(self::KEYS) as $key) {
            $settings[$key] = $this->read_key($key);
        }

        return $this->inertia('Escalated/Admin/Newsletters/Settings', [
            'settings' => $settings,
            'themes' => ['default', 'branded'],
        ]);
    }

    public function update(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        if (empty($body['default_theme'])) {
            return $this->error('escalated_validation', __('Default theme is required.', 'escalated'), 400);
        }
        $rate = isset($body['rate_limit_per_minute']) ? (int) $body['rate_limit_per_minute'] : 0;
        if ($rate < 1 || $rate > 10000) {
            return $this->error('escalated_validation', __('Invalid rate limit.', 'escalated'), 400);
        }
        $batch = isset($body['batch_size']) ? (int) $body['batch_size'] : 0;
        if ($batch < 1 || $batch > 1000) {
            return $this->error('escalated_validation', __('Invalid batch size.', 'escalated'), 400);
        }

        Setting::set('newsletter.default_from', sanitize_email((string) ($body['default_from'] ?? '')));
        Setting::set('newsletter.default_reply_to', sanitize_email((string) ($body['default_reply_to'] ?? '')));
        Setting::set('newsletter.default_theme', sanitize_text_field((string) $body['default_theme']));
        Setting::set('newsletter.rate_limit_per_minute', (string) $rate);
        Setting::set('newsletter.batch_size', (string) $batch);
        $tracking = filter_var($body['tracking_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        Setting::set('newsletter.tracking_enabled', $tracking ? '1' : '0');

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/settings'));
    }

    private function read_key(string $key): mixed
    {
        $stored = Setting::get('newsletter.'.$key);
        if ($stored !== null) {
            return self::KEYS[$key] === 'boolean'
                ? filter_var($stored, FILTER_VALIDATE_BOOLEAN)
                : (self::KEYS[$key] === 'number' ? (int) $stored : $stored);
        }

        return match ($key) {
            'default_from' => NewsletterConfig::default_from(),
            'default_reply_to' => NewsletterConfig::default_reply_to(),
            'default_theme' => NewsletterConfig::default_theme(),
            'rate_limit_per_minute' => NewsletterConfig::rate_limit_per_minute(),
            'batch_size' => NewsletterConfig::batch_size(),
            'tracking_enabled' => NewsletterConfig::tracking_enabled(),
            default => null,
        };
    }
}
