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
        // Top-level menu. WordPress auto-creates a submenu entry with
        // the same slug pointing at the same callback. Adding an
        // explicit add_submenu_page with that same slug ('escalated')
        // re-registers the callback on the page-load hook, which makes
        // render_list fire twice and the entire ticket list render
        // twice on a single page view (see screenshots/results/
        // ticket-list.png in the bug-report screenshot pre-fix).
        // Pass the desired submenu label as the menu_title argument
        // and rely on the auto-created submenu — WordPress reuses the
        // menu_title for that entry.
        add_menu_page(
            __('Escalated', 'escalated'),
            __('Tickets', 'escalated'),
            'escalated_ticket_view',
            'escalated',
            [new Admin_Tickets, 'render_list'],
            self::menu_icon_data_uri(),
            30
        );
        add_submenu_page('escalated', __('Departments', 'escalated'), __('Departments', 'escalated'), 'escalated_department_view', 'escalated-departments', [new Admin_Departments, 'render']);
        add_submenu_page('escalated', __('SLA Policies', 'escalated'), __('SLA Policies', 'escalated'), 'escalated_sla_view', 'escalated-sla-policies', [new Admin_Sla_Policies, 'render']);
        add_submenu_page('escalated', __('Business Hours', 'escalated'), __('Business Hours', 'escalated'), 'escalated_sla_view', 'escalated-business-hours', [new Admin_Business_Hours, 'render']);
        add_submenu_page('escalated', __('Automations', 'escalated'), __('Automations', 'escalated'), 'escalated_automation_view', 'escalated-automations', [new Admin_Automations, 'render']);
        add_submenu_page('escalated', __('Workflows', 'escalated'), __('Workflows', 'escalated'), 'escalated_automation_manage', 'escalated-workflows', [new Admin_Workflows, 'render']);
        add_submenu_page('escalated', __('Escalation Rules', 'escalated'), __('Escalation Rules', 'escalated'), 'escalated_escalation_view', 'escalated-escalation-rules', [new Admin_Escalation_Rules, 'render']);
        add_submenu_page('escalated', __('Tags', 'escalated'), __('Tags', 'escalated'), 'escalated_tag_view', 'escalated-tags', [new Admin_Tags, 'render']);
        add_submenu_page('escalated', __('Skills', 'escalated'), __('Skills', 'escalated'), 'escalated_skill_manage', 'escalated-skills', [new Admin_Skills, 'render']);
        add_submenu_page('escalated', __('Canned Responses', 'escalated'), __('Canned Responses', 'escalated'), 'escalated_macro_manage', 'escalated-canned-responses', [new Admin_Canned_Responses, 'render']);
        add_submenu_page('escalated', __('Macros', 'escalated'), __('Macros', 'escalated'), 'escalated_macro_view', 'escalated-macros', [new Admin_Macros, 'render']);
        add_submenu_page('escalated', __('Reports', 'escalated'), __('Reports', 'escalated'), 'escalated_report_view', 'escalated-reports', [new Admin_Reports, 'render']);
        add_submenu_page('escalated', __('Users', 'escalated'), __('Users', 'escalated'), 'escalated_user_manage', 'escalated-users', [new Admin_Users, 'render']);
        add_submenu_page('escalated', __('Newsletters', 'escalated'), __('Newsletters', 'escalated'), 'escalated_newsletters_manage', 'escalated-newsletters', [new Admin_Newsletters, 'render']);
        add_submenu_page('escalated', __('Webhooks', 'escalated'), __('Webhooks', 'escalated'), 'escalated_webhook_manage', 'escalated-webhooks', [new Admin_Webhooks, 'render']);
        add_submenu_page('escalated', __('API Tokens', 'escalated'), __('API Tokens', 'escalated'), 'escalated_api_token_view', 'escalated-api-tokens', [new Admin_Api_Tokens, 'render']);
        add_submenu_page('escalated', __('Settings', 'escalated'), __('Settings', 'escalated'), 'escalated_settings_view', 'escalated-settings', [new Admin_Settings, 'render']);

        // Roles: only while roles & permissions is switched on
        // (docs/access-control.md). Its handlers answer 404 while it is off.
        $roles = new Admin_Roles;
        if (\Escalated\Support\AccessFeatures::roles_permissions()) {
            add_submenu_page('escalated', __('Roles', 'escalated'), __('Roles', 'escalated'), 'escalated_role_view', 'escalated-roles', [$roles, 'render']);
        }

        // Single sign-on settings, only while SSO is switched on.
        $sso = new Admin_Sso;
        if (\Escalated\Support\AccessFeatures::sso()) {
            add_submenu_page('escalated', __('Single Sign-On', 'escalated'), __('Single Sign-On', 'escalated'), 'escalated_settings_view', 'escalated-sso', [$sso, 'render']);
        }

        // Two-factor: enrolment for every staff user, and the challenge
        // (outside the menu), only while two-factor is switched on.
        $two_factor = new Admin_Two_Factor;
        if (\Escalated\Support\AccessFeatures::two_factor()) {
            add_submenu_page('escalated', __('Two-Factor', 'escalated'), __('Two-Factor', 'escalated'), 'read', \Escalated\Support\TwoFactorGate::ENROL_PAGE, [$two_factor, 'render']);
            add_submenu_page('', __('Two-Factor Authentication', 'escalated'), '', 'read', \Escalated\Support\TwoFactorGate::CHALLENGE_PAGE, [$two_factor, 'render_challenge']);
        }
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
            // The access switches, and the user's permissions: ['*'] for
            // staff while roles & permissions is off (docs/access-control.md).
            'features' => \Escalated\Support\AccessFeatures::shared(),
            'permissions' => \Escalated\Support\AccessEnforcer::permissions(get_current_user_id()),
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
