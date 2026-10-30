<?php

namespace Escalated\Admin;

use Escalated\Services\SecretMask;
use Escalated\Services\SsoService;
use Escalated\Support\AccessFeatures;

/**
 * Escalated > Single Sign-On (escalated-laravel's Admin\SsoSettingsController),
 * only while ESCALATED_SSO is on: the provider and its fields, secrets
 * masked, and the endpoints to give the identity provider. Viewing needs
 * escalated_settings_view, saving escalated_settings_manage. Answers 404
 * while SSO is off.
 */
class Admin_Sso
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function render(): void
    {
        self::require_enabled();
        if (! current_user_can('escalated_settings_view')) {
            wp_die(esc_html__('Permission denied.', 'escalated'), 403);
        }
        $sso = new SsoService;
        $settings = self::masked($sso->getConfig());
        $endpoints = $sso->endpoints();
        $can_manage = current_user_can('escalated_settings_manage');
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        $errors = array_filter(explode(',', isset($_GET['errors']) ? sanitize_text_field(wp_unslash($_GET['errors'])) : ''));

        include ESCALATED_PLUGIN_DIR.'templates/admin/sso.php';
    }

    public function handle_actions(): void
    {
        if (! isset($_POST['escalated_sso_action'])) {
            return;
        }
        self::require_enabled();
        if (! current_user_can('escalated_settings_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'), 403);
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['_escalated_nonce'] ?? ''));
        if (! wp_verify_nonce($nonce, 'escalated_sso_save')) {
            wp_die(esc_html__('Security check failed.', 'escalated'));
        }

        $errors = self::save(wp_unslash($_POST));
        $page = admin_url('admin.php?page=escalated-sso');
        wp_safe_redirect($errors === [] ? add_query_arg('message', 'saved', $page) : add_query_arg('errors', implode(',', $errors), $page));
        exit;
    }

    /** @return array<string, string> the settings with secrets masked */
    public static function masked(array $settings): array
    {
        foreach (SsoService::SECRET_KEYS as $key) {
            $settings[$key] = SecretMask::mask($settings[$key] ?? null);
        }

        return $settings;
    }

    /**
     * Validate and save, as Laravel validates. A masked secret posted back
     * keeps the stored one.
     *
     * @return list<string> the fields that failed (nothing saved then)
     */
    public static function save(array $input): array
    {
        $get = static fn (string $k) => trim((string) ($input[$k] ?? ''));
        $errors = [];
        $provider = $get('sso_provider');
        if (! in_array($provider, ['none', 'saml', 'oauth', 'jwt'], true)) {
            $errors[] = 'sso_provider';
        }
        $limits = [
            'sso_entity_id' => 500, 'sso_url' => 500, 'sso_login_url' => 500, 'sso_logout_url' => 500, 'sso_metadata_url' => 500,
            'sso_certificate' => 10000, 'sso_attr_email' => 100, 'sso_attr_name' => 100, 'sso_attr_role' => 100,
            'sso_jwt_secret' => 500, 'sso_oauth_authorize_url' => 500, 'sso_oauth_token_url' => 500, 'sso_oauth_userinfo_url' => 500,
            'sso_oauth_client_id' => 255, 'sso_oauth_client_secret' => 500, 'sso_oauth_scopes' => 255,
        ];
        foreach ($limits as $key => $max) {
            if (strlen($get($key)) > $max) {
                $errors[] = $key;
            }
        }
        foreach (['sso_url', 'sso_login_url', 'sso_logout_url', 'sso_metadata_url', 'sso_oauth_authorize_url', 'sso_oauth_token_url', 'sso_oauth_userinfo_url'] as $key) {
            if ($get($key) !== '' && ! wp_http_validate_url($get($key)) && ! filter_var($get($key), FILTER_VALIDATE_URL)) {
                $errors[] = $key;
            }
        }
        if ($get('sso_jwt_algorithm') !== '' && ! in_array($get('sso_jwt_algorithm'), ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512'], true)) {
            $errors[] = 'sso_jwt_algorithm';
        }
        $required = [
            'saml' => ['sso_entity_id', 'sso_login_url'],
            'jwt' => ['sso_url', 'sso_jwt_secret'],
            'oauth' => ['sso_oauth_authorize_url', 'sso_oauth_token_url', 'sso_oauth_userinfo_url', 'sso_oauth_client_id'],
        ];
        foreach ($required[$provider] ?? [] as $key) {
            if ($get($key) === '') {
                $errors[] = $key;
            }
        }
        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        $data = [];
        foreach (array_keys(SsoService::CONFIG_KEYS) as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            if (in_array($key, SsoService::SECRET_KEYS, true) && SecretMask::is_masked($get($key))) {
                continue;
            }
            $data[$key] = $key === 'sso_certificate' ? sanitize_textarea_field($get($key)) : sanitize_text_field($get($key));
        }
        (new SsoService)->saveConfig($data);

        return [];
    }

    public static function require_enabled(): void
    {
        if (! AccessFeatures::sso()) {
            wp_die(esc_html__('Not found.', 'escalated'), '', ['response' => 404]);
        }
    }
}
