<?php

namespace Escalated\Services;

use Escalated\Models\Setting;

class KnowledgeBaseService
{
    /**
     * Check if the knowledge base is enabled.
     */
    public static function is_enabled(): bool
    {
        return Setting::get_bool('knowledge_base_enabled', true);
    }

    /**
     * Check if the knowledge base is publicly accessible (no auth required).
     */
    public static function is_public(): bool
    {
        return Setting::get_bool('knowledge_base_public', true);
    }

    /**
     * Check if article feedback (helpful/not helpful) is enabled.
     */
    public static function is_feedback_enabled(): bool
    {
        return Setting::get_bool('knowledge_base_feedback_enabled', true);
    }

    /**
     * Get all KB settings as an array.
     *
     * @return array<string, bool>
     */
    public static function get_settings(): array
    {
        return [
            'knowledge_base_enabled' => self::is_enabled(),
            'knowledge_base_public' => self::is_public(),
            'knowledge_base_feedback_enabled' => self::is_feedback_enabled(),
        ];
    }

    /**
     * Update KB settings.
     *
     * @param  array  $settings  Settings to update.
     */
    public static function update_settings(array $settings): void
    {
        $allowed = [
            'knowledge_base_enabled',
            'knowledge_base_public',
            'knowledge_base_feedback_enabled',
        ];

        foreach ($allowed as $key) {
            if (isset($settings[$key])) {
                Setting::set($key, $settings[$key] ? '1' : '0');
            }
        }
    }

    /**
     * Guard check: should KB routes be accessible?
     *
     * Returns true if the KB is enabled. For public access, also checks
     * knowledge_base_public or if user is authenticated.
     *
     * @param  bool  $require_auth  Whether authentication is required.
     */
    public static function can_access(bool $require_auth = false): bool
    {
        if (! self::is_enabled()) {
            return false;
        }

        if ($require_auth) {
            return is_user_logged_in();
        }

        // If KB is not public, require authentication.
        if (! self::is_public()) {
            return is_user_logged_in();
        }

        return true;
    }

    /**
     * Permission callback for KB REST API routes.
     *
     * @return bool|\WP_Error
     */
    public static function permission_check()
    {
        if (! self::is_enabled()) {
            return new \WP_Error(
                'escalated_kb_disabled',
                __('Knowledge base is disabled.', 'escalated'),
                ['status' => 403]
            );
        }

        if (! self::is_public() && ! is_user_logged_in()) {
            return new \WP_Error(
                'escalated_kb_private',
                __('Knowledge base requires authentication.', 'escalated'),
                ['status' => 401]
            );
        }

        return true;
    }
}
