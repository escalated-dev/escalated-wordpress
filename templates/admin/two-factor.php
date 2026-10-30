<?php
/**
 * Admin template: two-factor enrolment (only while two-factor is on).
 *
 * The WordPress-native counterpart of the shared
 * Escalated/Admin/Settings/TwoFactor page.
 *
 * @var bool $enabled Whether the user has a confirmed enrolment.
 * @var bool $pending Whether a setup waits for its first code.
 * @var bool $required Whether every staff user must enrol.
 * @var bool $can_require Whether the user may change that.
 * @var array|null $setup {qr_uri, recovery_codes}, shown once after setup.
 * @var string $message Flash message key.
 * @var string $error Flash error key.
 */
if (! defined('ABSPATH')) {
    exit;
}
$form = static function (string $action, string $label, string $class = 'button', string $extra = '') {
    ?>
    <form method="post" style="display: inline-block; margin-right: 8px;">
        <input type="hidden" name="escalated_two_factor_action" value="<?php echo esc_attr($action); ?>">
        <?php wp_nonce_field('escalated_two_factor_'.$action, '_escalated_nonce'); ?>
        <?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below?>
        <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
};
?>
<div class="wrap">
    <h1><?php esc_html_e('Two-Factor Authentication', 'escalated'); ?></h1>

    <?php if ($message) { ?>
        <div class="notice notice-success"><p>
            <?php
            $messages = [
                'setup' => __('Scan the code, then confirm it below.', 'escalated'),
                'enabled' => __('Two-factor authentication enabled.', 'escalated'),
                'disabled' => __('Two-factor authentication disabled.', 'escalated'),
                'saved' => __('Saved.', 'escalated'),
            ];
        echo esc_html($messages[$message] ?? __('Saved.', 'escalated'));
        ?>
        </p></div>
    <?php } ?>
    <?php if ($error) { ?>
        <div class="notice notice-error"><p>
            <?php
        $errors = [
            'code' => __('Invalid verification code.', 'escalated'),
            'no_pending' => __('No pending two-factor setup found.', 'escalated'),
            'already_enabled' => __('Two-factor authentication is already enabled. Disable it first to set it up again.', 'escalated'),
        ];
        echo esc_html($errors[$error] ?? __('Something went wrong.', 'escalated'));
        ?>
        </p></div>
    <?php } ?>

    <?php if ($required && ! $enabled) { ?>
        <div class="notice notice-warning"><p><?php esc_html_e('Two-factor authentication is required. Set it up to continue.', 'escalated'); ?></p></div>
    <?php } ?>

    <?php if ($setup !== null) { ?>
        <h2><?php esc_html_e('Add this account to your authenticator app', 'escalated'); ?></h2>
        <p><code class="escalated-otpauth-uri"><?php echo esc_html($setup['qr_uri']); ?></code></p>
        <h3><?php esc_html_e('Recovery codes', 'escalated'); ?></h3>
        <p><?php esc_html_e('Each code signs you in once if you lose your device. They are not shown again.', 'escalated'); ?></p>
        <ul class="escalated-recovery-codes">
            <?php foreach ($setup['recovery_codes'] as $code) { ?>
                <li><code><?php echo esc_html($code); ?></code></li>
            <?php } ?>
        </ul>
    <?php } ?>

    <?php if ($enabled) { ?>
        <p><strong><?php esc_html_e('Two-factor authentication is on for your account.', 'escalated'); ?></strong></p>
        <?php $form('disable', __('Disable', 'escalated'), 'button button-link-delete'); ?>
    <?php } elseif ($pending) { ?>
        <form method="post">
            <input type="hidden" name="escalated_two_factor_action" value="confirm">
            <?php wp_nonce_field('escalated_two_factor_confirm', '_escalated_nonce'); ?>
            <label for="escalated-2fa-code"><?php esc_html_e('Code from your app', 'escalated'); ?></label>
            <input type="text" id="escalated-2fa-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
            <?php submit_button(__('Confirm', 'escalated'), 'primary', 'submit', false); ?>
        </form>
        <p><?php $form('setup', __('Start over', 'escalated')); ?></p>
    <?php } else { ?>
        <?php $form('setup', __('Set up two-factor authentication', 'escalated'), 'button button-primary'); ?>
    <?php } ?>

    <?php if ($can_require) { ?>
        <h2><?php esc_html_e('Require for all staff', 'escalated'); ?></h2>
        <?php $form('require', $required ? __('Stop requiring it', 'escalated') : __('Require it', 'escalated'), 'button', '<input type="hidden" name="two_factor_required" value="'.($required ? '0' : '1').'">'); ?>
    <?php } ?>
</div>
