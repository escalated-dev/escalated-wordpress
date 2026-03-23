<?php

namespace Escalated;

class Escalated {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        load_plugin_textdomain( 'escalated', false, dirname( ESCALATED_PLUGIN_BASENAME ) . '/languages' );

        // Register custom cron intervals.
        add_filter( 'cron_schedules', [ Activator::class, 'add_cron_schedules' ] );

        if ( is_admin() ) {
            ( new Admin\Admin_Menu() )->register();
        }

        ( new Frontend\Shortcodes() )->register();
        ( new Frontend\Ajax_Handler() )->register();
        ( new Api\Api_Bootstrap() )->register();
        ( new Mail\Inbound_Controller() )->register();
        ( new Cron\Sla_Check() )->register();
        ( new Cron\Escalation_Check() )->register();
        ( new Cron\Automation_Check() )->register();
        ( new Cron\Auto_Close() )->register();
        ( new Cron\Activity_Purge() )->register();

        Cli\AutomationCommand::register();
    }

    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'escalated_' . $name;
    }
}
