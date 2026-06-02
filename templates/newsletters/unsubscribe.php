<?php
/**
 * Newsletter unsubscribe page (public).
 *
 * @var string $token
 * @var string $email
 * @var bool   $confirmed
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php esc_html_e('Unsubscribe', 'escalated'); ?></title>
</head>
<body>
<?php if ($confirmed) : ?>
    <p><?php esc_html_e('You have been unsubscribed.', 'escalated'); ?></p>
<?php else : ?>
    <p><?php esc_html_e('Unsubscribe from future marketing emails.', 'escalated'); ?></p>
    <?php if ($email) : ?>
        <p><?php echo esc_html($email); ?></p>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url($action); ?>">
        <button type="submit"><?php esc_html_e('Confirm unsubscribe', 'escalated'); ?></button>
    </form>
<?php endif; ?>
</body>
</html>
