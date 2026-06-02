<?php

namespace Escalated\Services\Newsletter;

use Escalated\Models\Setting;

/**
 * Newsletter feature flag and runtime config (options + settings table).
 */
class NewsletterConfig
{
    public const OPTION_ENABLED = 'escalated_newsletters_enabled';

    public static function is_enabled(): bool
    {
        return filter_var(get_option(self::OPTION_ENABLED, '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function default_from(): ?string
    {
        $v = Setting::get('newsletter.default_from');

        return $v !== null && $v !== '' ? (string) $v : null;
    }

    public static function default_reply_to(): ?string
    {
        $v = Setting::get('newsletter.default_reply_to');

        return $v !== null && $v !== '' ? (string) $v : null;
    }

    public static function default_theme(): string
    {
        return (string) (Setting::get('newsletter.default_theme') ?: get_option('escalated_newsletter_default_theme', 'default'));
    }

    public static function rate_limit_per_minute(): int
    {
        $v = Setting::get('newsletter.rate_limit_per_minute');

        return $v !== null ? max(1, (int) $v) : 60;
    }

    public static function batch_size(): int
    {
        $v = Setting::get('newsletter.batch_size');

        return $v !== null ? max(1, (int) $v) : 50;
    }

    public static function tracking_enabled(): bool
    {
        $v = Setting::get('newsletter.tracking_enabled');
        if ($v === null) {
            return filter_var(get_option('escalated_newsletter_tracking_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function claim_timeout_minutes(): int
    {
        return 10;
    }

    public static function auto_pause_threshold(): int
    {
        return 100;
    }

    public static function auto_pause_bounce_rate(): float
    {
        return 0.05;
    }

    public static function mail_configured(): bool
    {
        return (bool) apply_filters(
            'escalated_newsletter_mail_configured',
            ! empty(get_option('admin_email'))
        );
    }

    /**
     * @return string[]
     */
    public static function discover_themes(): array
    {
        $dir = apply_filters(
            'escalated_newsletter_themes_dir',
            ESCALATED_PLUGIN_DIR.'templates/newsletter_themes'
        );
        $themes = [];
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.php') ?: [] as $path) {
                $themes[] = basename($path, '.php');
            }
        }
        $themes = array_values(array_unique($themes));

        return $themes ?: ['default', 'branded'];
    }
}
