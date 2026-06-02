<?php
/**
 * Newsletters admin placeholder — embed shared Inertia UI via REST props.
 *
 * @var bool $enabled
 */
?>
<div class="wrap escalated-wrap">
    <h1><?php esc_html_e('Newsletters', 'escalated'); ?></h1>
    <?php if (! $enabled) : ?>
        <p><?php esc_html_e('Newsletters are disabled. Enable them in site options (escalated_newsletters_enabled).', 'escalated'); ?></p>
    <?php else : ?>
        <p><?php esc_html_e('Use the shared Escalated frontend or REST API:', 'escalated'); ?></p>
        <code><?php echo esc_html(rest_url('escalated/v1/admin/newsletters')); ?></code>
        <p><?php esc_html_e('Inertia component paths: Escalated/Admin/Newsletters/Index, Compose, Show, Edit, Lists/*, Templates/*, Settings.', 'escalated'); ?></p>
    <?php endif; ?>
</div>
