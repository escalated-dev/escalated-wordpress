<?php

namespace Escalated\Admin;

class Admin_Menu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menus(): void
    {
        add_menu_page(
            __('Escalated', 'escalated'),
            __('Escalated', 'escalated'),
            'escalated_ticket_view',
            'escalated',
            [new Admin_Tickets, 'render_list'],
            self::menu_icon_data_uri(),
            30
        );

        add_submenu_page('escalated', __('Tickets', 'escalated'), __('Tickets', 'escalated'), 'escalated_ticket_view', 'escalated', [new Admin_Tickets, 'render_list']);
        add_submenu_page('escalated', __('Departments', 'escalated'), __('Departments', 'escalated'), 'escalated_department_view', 'escalated-departments', [new Admin_Departments, 'render']);
        add_submenu_page('escalated', __('SLA Policies', 'escalated'), __('SLA Policies', 'escalated'), 'escalated_sla_view', 'escalated-sla-policies', [new Admin_Sla_Policies, 'render']);
        add_submenu_page('escalated', __('Automations', 'escalated'), __('Automations', 'escalated'), 'escalated_automation_view', 'escalated-automations', [new Admin_Automations, 'render']);
        add_submenu_page('escalated', __('Escalation Rules', 'escalated'), __('Escalation Rules', 'escalated'), 'escalated_escalation_view', 'escalated-escalation-rules', [new Admin_Escalation_Rules, 'render']);
        add_submenu_page('escalated', __('Tags', 'escalated'), __('Tags', 'escalated'), 'escalated_tag_view', 'escalated-tags', [new Admin_Tags, 'render']);
        add_submenu_page('escalated', __('Canned Responses', 'escalated'), __('Canned Responses', 'escalated'), 'escalated_macro_manage', 'escalated-canned-responses', [new Admin_Canned_Responses, 'render']);
        add_submenu_page('escalated', __('Macros', 'escalated'), __('Macros', 'escalated'), 'escalated_macro_view', 'escalated-macros', [new Admin_Macros, 'render']);
        add_submenu_page('escalated', __('Reports', 'escalated'), __('Reports', 'escalated'), 'escalated_report_view', 'escalated-reports', [new Admin_Reports, 'render']);
        add_submenu_page('escalated', __('API Tokens', 'escalated'), __('API Tokens', 'escalated'), 'escalated_api_token_view', 'escalated-api-tokens', [new Admin_Api_Tokens, 'render']);
        add_submenu_page('escalated', __('Settings', 'escalated'), __('Settings', 'escalated'), 'escalated_settings_view', 'escalated-settings', [new Admin_Settings, 'render']);
    }

    /**
     * Inline-SVG data URI for the wp-admin sidebar menu icon. Using a
     * data URI rather than a file URL keeps the icon working on
     * installs that lock down the uploads dir from script access. The
     * SVG uses `currentColor` so wp-admin's own CSS can swap it
     * between active/inactive sidebar states without two assets.
     */
    private static function menu_icon_data_uri(): string
    {
        $svg = file_get_contents(ESCALATED_PLUGIN_DIR.'assets/images/menu-icon.svg');
        if ($svg === false) {
            return 'dashicons-tickets-alt';
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function enqueue_assets(string $hook): void
    {
        // Only enqueue on Escalated pages
        if (strpos($hook, 'escalated') === false) {
            return;
        }
        wp_enqueue_style('escalated-admin', ESCALATED_PLUGIN_URL.'assets/css/admin.css', [], ESCALATED_VERSION);
        wp_enqueue_script('escalated-admin', ESCALATED_PLUGIN_URL.'assets/js/admin.js', ['jquery'], ESCALATED_VERSION, true);

        // Determine panel theme class
        $panel_theme = \Escalated\Models\Setting::get('panel_theme', 'auto');
        $theme_class = '';
        if ($panel_theme === 'dark') {
            $theme_class = 'escalated-dark';
        } elseif ($panel_theme === 'light') {
            $theme_class = 'escalated-light';
        }
        // 'auto' relies on prefers-color-scheme media query already in CSS

        wp_localize_script('escalated-admin', 'escalatedAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('escalated_admin'),
            'restUrl' => rest_url('escalated/v1/'),
            'themeClass' => $theme_class,
        ]);

        // Inject inline script to apply the theme class to the wrapper
        if ($theme_class) {
            wp_add_inline_script('escalated-admin', sprintf(
                'document.addEventListener("DOMContentLoaded",function(){'.
                'var w=document.querySelector(".escalated-wrap")||document.querySelector("#wpbody-content>.wrap");'.
                'if(w){w.classList.add(%s);}'.
                '});',
                wp_json_encode($theme_class)
            ));
        }
    }
}
