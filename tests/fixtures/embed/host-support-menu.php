<?php
/**
 * A host's own menu of Escalated's admin screens, rendered from
 * escalated_admin_navigation(). docs/embedding-admin.md section 2 shows this
 * exact template; Test_Embed_Admin renders it.
 */
$items = function_exists('escalated_admin_navigation') ? escalated_admin_navigation() : [];
?>
<?php if ($items) { ?>
<nav class="host-support-menu" aria-label="<?php esc_attr_e('Support', 'escalated'); ?>">
    <ul>
        <?php foreach ($items as $item) { ?>
            <li>
                <a href="<?php echo esc_url($item['url']); ?>"<?php echo $item['active'] ? ' aria-current="page"' : ''; ?>>
                    <?php echo esc_html($item['label']); ?>
                    <?php if ($item['badge'] !== null) { ?>
                        <span class="badge"><?php echo esc_html((string) $item['badge']); ?></span>
                    <?php } ?>
                </a>
            </li>
        <?php } ?>
    </ul>
</nav>
<?php } ?>
