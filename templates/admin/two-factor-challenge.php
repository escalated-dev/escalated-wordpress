<?php
/**
 * Admin template: the two-factor challenge (only while two-factor is on).
 *
 * The WordPress-native counterpart of the shared
 * Escalated/Auth/TwoFactorChallenge page: after the WordPress login, before
 * the Escalated screens.
 *
 * @var string $action The URL the form posts to.
 * @var string $error Error key: code, recovery_code or throttled.
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap escalated-two-factor-challenge">
    <h1><?php esc_html_e('Two-Factor Authentication', 'escalated'); ?></h1>

    <?php if ($error) { ?>
        <div class="notice notice-error"><p>
            <?php
            $errors = [
                'code' => __('That code is not valid.', 'escalated'),
                'recovery_code' => __('That recovery code is not valid.', 'escalated'),
                'throttled' => __('Too many attempts. Wait a minute and try again.', 'escalated'),
            ];
        echo esc_html($errors[$error] ?? $errors['code']);
        ?>
        </p></div>
    <?php } ?>

    <form method="post" action="<?php echo esc_url($action); ?>">
        <input type="hidden" name="escalated_two_factor_action" value="verify">
        <?php wp_nonce_field('escalated_two_factor_verify', '_escalated_nonce'); ?>
        <p>
            <label for="escalated-2fa-challenge-code"><?php esc_html_e('Code from your authenticator app', 'escalated'); ?></label><br>
            <input type="text" id="escalated-2fa-challenge-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
        </p>
        <p>
            <label for="escalated-2fa-recovery"><?php esc_html_e('Or a recovery code', 'escalated'); ?></label><br>
            <input type="text" id="escalated-2fa-recovery" name="recovery_code" autocomplete="off">
        </p>
        <?php submit_button(__('Verify', 'escalated')); ?>
    </form>
</div>
