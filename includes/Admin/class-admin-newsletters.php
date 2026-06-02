<?php

namespace Escalated\Admin;

use Escalated\Services\Newsletter\NewsletterConfig;

/**
 * WordPress admin shell for newsletters (Inertia props via REST).
 *
 * Shared Vue pages: Escalated/Admin/Newsletters/* — REST under /wp-json/escalated/v1/admin/newsletters
 */
class Admin_Newsletters
{
    public function render(): void
    {
        if (! current_user_can('escalated_newsletters_manage')) {
            wp_die(esc_html__('Permission denied.', 'escalated'));
        }

        $enabled = NewsletterConfig::is_enabled();
        include ESCALATED_PLUGIN_DIR.'templates/admin/newsletters.php';
    }
}
