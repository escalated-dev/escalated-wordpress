<?php
/**
 * Admin template: Single Sign-On (only while ESCALATED_SSO is on).
 *
 * The WordPress-native counterpart of the shared
 * Escalated/Admin/Settings/SsoSettings page.
 *
 * @var array $settings The sso_* settings, secrets masked.
 * @var array $endpoints The URLs to give the identity provider.
 * @var bool $can_manage Whether the user may save.
 * @var string $message Flash message key.
 * @var list<string> $errors Fields that failed validation.
 */
if (! defined('ABSPATH')) {
    exit;
}
$text_fields = [
    'sso_entity_id' => __('IdP entity ID (SAML)', 'escalated'),
    'sso_login_url' => __('IdP sign-in URL', 'escalated'),
    'sso_url' => __('Legacy SSO URL (JWT sign-in page)', 'escalated'),
    'sso_logout_url' => __('IdP sign-out URL', 'escalated'),
    'sso_metadata_url' => __('IdP metadata URL', 'escalated'),
    'sso_attr_email' => __('Email attribute / claim', 'escalated'),
    'sso_attr_name' => __('Name attribute / claim', 'escalated'),
    'sso_attr_role' => __('Role attribute / claim', 'escalated'),
    'sso_jwt_secret' => __('JWT secret or RS* public key', 'escalated'),
    'sso_jwt_algorithm' => __('JWT algorithm', 'escalated'),
    'sso_oauth_authorize_url' => __('OAuth authorize URL', 'escalated'),
    'sso_oauth_token_url' => __('OAuth token URL', 'escalated'),
    'sso_oauth_userinfo_url' => __('OAuth userinfo URL', 'escalated'),
    'sso_oauth_client_id' => __('OAuth client ID', 'escalated'),
    'sso_oauth_client_secret' => __('OAuth client secret', 'escalated'),
    'sso_oauth_scopes' => __('OAuth scopes', 'escalated'),
];
?>
<div class="wrap">
    <h1><?php esc_html_e('Single Sign-On', 'escalated'); ?></h1>

    <?php if ($message === 'saved') { ?>
        <div class="notice notice-success"><p><?php esc_html_e('SSO settings updated.', 'escalated'); ?></p></div>
    <?php } ?>
    <?php if ($errors) { ?>
        <div class="notice notice-error"><p>
            <?php
            /* translators: %s: field names */
            echo esc_html(sprintf(__('Check these fields: %s', 'escalated'), implode(', ', $errors)));
        ?>
        </p></div>
    <?php } ?>

    <h2><?php esc_html_e('Give these to your identity provider', 'escalated'); ?></h2>
    <table class="form-table escalated-sso-endpoints">
        <?php foreach ($endpoints as $name => $url) { ?>
            <tr><th scope="row"><?php echo esc_html($name); ?></th><td><code><?php echo esc_html($url); ?></code></td></tr>
        <?php } ?>
    </table>

    <form method="post">
        <input type="hidden" name="escalated_sso_action" value="save">
        <?php wp_nonce_field('escalated_sso_save', '_escalated_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="sso_provider"><?php esc_html_e('Provider', 'escalated'); ?></label></th>
                <td>
                    <select id="sso_provider" name="sso_provider"<?php disabled(! $can_manage); ?>>
                        <?php foreach (['none' => __('None', 'escalated'), 'saml' => 'SAML 2.0', 'oauth' => 'OAuth 2 / OIDC', 'jwt' => 'JWT'] as $value => $label) { ?>
                            <option value="<?php echo esc_attr($value); ?>"<?php selected($settings['sso_provider'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php } ?>
                    </select>
                </td>
            </tr>
            <?php foreach ($text_fields as $key => $label) { ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                    <td><input type="text" class="regular-text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($settings[$key] ?? ''); ?>"<?php disabled(! $can_manage); ?>></td>
                </tr>
            <?php } ?>
            <tr>
                <th scope="row"><label for="sso_certificate"><?php esc_html_e('IdP certificate (SAML)', 'escalated'); ?></label></th>
                <td><textarea id="sso_certificate" name="sso_certificate" rows="6" class="large-text code"<?php disabled(! $can_manage); ?>><?php echo esc_textarea($settings['sso_certificate'] ?? ''); ?></textarea></td>
            </tr>
        </table>
        <?php if ($can_manage) {
            submit_button(__('Save', 'escalated'));
        } ?>
    </form>
</div>
