<?php
namespace Escalated\Admin;

class Admin_Menu {

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_menus(): void {
        add_menu_page(
            __( 'Escalated', 'escalated' ),
            __( 'Escalated', 'escalated' ),
            'escalated_view_tickets',
            'escalated',
            [ new Admin_Tickets(), 'render_list' ],
            'dashicons-tickets-alt',
            30
        );

        add_submenu_page( 'escalated', __( 'Tickets', 'escalated' ), __( 'Tickets', 'escalated' ), 'escalated_view_tickets', 'escalated', [ new Admin_Tickets(), 'render_list' ] );
        add_submenu_page( 'escalated', __( 'Departments', 'escalated' ), __( 'Departments', 'escalated' ), 'escalated_manage_departments', 'escalated-departments', [ new Admin_Departments(), 'render' ] );
        add_submenu_page( 'escalated', __( 'SLA Policies', 'escalated' ), __( 'SLA Policies', 'escalated' ), 'escalated_manage_sla', 'escalated-sla-policies', [ new Admin_Sla_Policies(), 'render' ] );
        add_submenu_page( 'escalated', __( 'Automations', 'escalated' ), __( 'Automations', 'escalated' ), 'escalated_automation_manage', 'escalated-automations', [ new Admin_Automations(), 'render' ] );
        add_submenu_page( 'escalated', __( 'Escalation Rules', 'escalated' ), __( 'Escalation Rules', 'escalated' ), 'escalated_manage_escalation_rules', 'escalated-escalation-rules', [ new Admin_Escalation_Rules(), 'render' ] );
        add_submenu_page( 'escalated', __( 'Tags', 'escalated' ), __( 'Tags', 'escalated' ), 'escalated_manage_tags', 'escalated-tags', [ new Admin_Tags(), 'render' ] );
        add_submenu_page( 'escalated', __( 'Canned Responses', 'escalated' ), __( 'Canned Responses', 'escalated' ), 'escalated_use_canned_responses', 'escalated-canned-responses', [ new Admin_Canned_Responses(), 'render' ] );
        add_submenu_page( 'escalated', __( 'Macros', 'escalated' ), __( 'Macros', 'escalated' ), 'escalated_use_macros', 'escalated-macros', [ new Admin_Macros(), 'render' ] );
        add_submenu_page( 'escalated', __( 'Reports', 'escalated' ), __( 'Reports', 'escalated' ), 'escalated_view_reports', 'escalated-reports', [ new Admin_Reports(), 'render' ] );
        add_submenu_page( 'escalated', __( 'API Tokens', 'escalated' ), __( 'API Tokens', 'escalated' ), 'escalated_manage_api_tokens', 'escalated-api-tokens', [ new Admin_Api_Tokens(), 'render' ] );
        add_submenu_page( 'escalated', __( 'Settings', 'escalated' ), __( 'Settings', 'escalated' ), 'escalated_manage_settings', 'escalated-settings', [ new Admin_Settings(), 'render' ] );
    }

    public function enqueue_assets( string $hook ): void {
        // Only enqueue on Escalated pages
        if ( strpos( $hook, 'escalated' ) === false ) {
            return;
        }
        wp_enqueue_style( 'escalated-admin', ESCALATED_PLUGIN_URL . 'assets/css/admin.css', [], ESCALATED_VERSION );
        wp_enqueue_script( 'escalated-admin', ESCALATED_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], ESCALATED_VERSION, true );

        // Determine panel theme class
        $panel_theme = \Escalated\Models\Setting::get( 'panel_theme', 'auto' );
        $theme_class = '';
        if ( $panel_theme === 'dark' ) {
            $theme_class = 'escalated-dark';
        } elseif ( $panel_theme === 'light' ) {
            $theme_class = 'escalated-light';
        }
        // 'auto' relies on prefers-color-scheme media query already in CSS

        wp_localize_script( 'escalated-admin', 'escalatedAdmin', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'escalated_admin' ),
            'restUrl'    => rest_url( 'escalated/v1/' ),
            'themeClass' => $theme_class,
        ] );

        // Inject inline script to apply the theme class to the wrapper
        if ( $theme_class ) {
            wp_add_inline_script( 'escalated-admin', sprintf(
                'document.addEventListener("DOMContentLoaded",function(){' .
                'var w=document.querySelector(".escalated-wrap")||document.querySelector("#wpbody-content>.wrap");' .
                'if(w){w.classList.add(%s);}' .
                '});',
                wp_json_encode( $theme_class )
            ) );
        }
    }
}
