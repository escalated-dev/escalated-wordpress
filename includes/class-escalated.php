<?php

namespace Escalated;

class Escalated
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function boot(): void
    {
        $this->load_translations();

        // Register custom cron intervals.
        add_filter('cron_schedules', [Activator::class, 'add_cron_schedules']);

        if (is_admin()) {
            (new Admin\Admin_Menu)->register();
        }

        (new Frontend\Shortcodes)->register();
        (new Frontend\Ajax_Handler)->register();
        (new Api\Api_Bootstrap)->register();
        (new Mail\Inbound_Controller)->register();
        (new Mail\Email_Threading)->register();
        (new Cron\Sla_Check)->register();
        (new Cron\Escalation_Check)->register();
        (new Cron\Automation_Check)->register();
        (new Cron\Auto_Close)->register();
        (new Cron\Activity_Purge)->register();
        (new Services\BroadcastService)->register();
        (new Services\WorkflowListener)->register();
        (new Services\Custom_Action_Listener)->register();
        (new Cron\Snooze_Check)->register();
        (new Cron\Chat_Cleanup)->register();
        (new Cron\Deferred_Workflow_Jobs_Check)->register();

        Cli\AutomationCommand::register();
    }

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix.'escalated_'.$name;
    }

    /**
     * Load plugin translations.
     *
     * Loads translations in two layers, with WordPress's gettext system
     * merging them so the later-loaded layer can override earlier entries:
     *
     *   1. Central translations from the `escalated-dev/locale` Composer
     *      package (vendor/escalated-dev/locale/languages/escalated-{locale}.mo).
     *      Source of truth, shared across all Escalated host plugins.
     *   2. Local overrides from this plugin's `languages/overrides/` dir
     *      (escalated-{locale}.mo), giving site operators a way to tweak
     *      strings without forking the central package.
     *
     * If the central package is not installed (e.g. fresh checkout before
     * `composer install`), we fall back to the legacy `languages/` dir so
     * existing installs continue to work.
     */
    private function load_translations(): void
    {
        $domain = 'escalated';
        $locale = determine_locale();
        $mofile = $domain.'-'.$locale.'.mo';

        // 1. Central translations shipped by escalated-dev/locale.
        $central = ESCALATED_PLUGIN_DIR.'vendor/escalated-dev/locale/languages/'.$mofile;
        if (file_exists($central)) {
            load_textdomain($domain, $central);
        } else {
            // Fallback to the legacy in-plugin languages/ dir (pre-central rollout).
            load_plugin_textdomain($domain, false, dirname(ESCALATED_PLUGIN_BASENAME).'/languages');
        }

        // 2. Local overrides — entries here win over the central package.
        $overrides = ESCALATED_PLUGIN_DIR.'languages/overrides/'.$mofile;
        if (file_exists($overrides)) {
            load_textdomain($domain, $overrides);
        }
    }
}
