<?php

namespace Escalated;

class Escalated
{
    private static ?self $instance = null;

    /**
     * Memoized connection for Escalated's tables. Null means "not yet
     * resolved"; see db().
     */
    private static ?\wpdb $db = null;

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
        (new Cron\Newsletter_Dispatch)->register();
        (new Frontend\Newsletter_Public_Routes)->register();

        Cli\AutomationCommand::register();
    }

    /**
     * The wpdb instance Escalated's own tables live on.
     *
     * Every query in this plugin used the global $wpdb with no way to change
     * it, which made the plugin unusable on any site that partitions its data:
     * a schema shared with a legacy system, a separate reporting store, or
     * simply a site that would rather keep support tables out of the WordPress
     * database.
     *
     * Returns the global $wpdb unless the site defines its own connection, so
     * an unconfigured site is unchanged -- same instance, same prefix, same
     * queries.
     *
     * WordPress has no connection registry, so a second database means a second
     * wpdb. It is built once and reused: wpdb connects in its constructor, and
     * building one per query would open a connection per query.
     *
     * Your users table is deliberately not moved. It belongs to WordPress, and
     * Escalated stores user ids as plain unconstrained columns precisely so the
     * two can live on different databases -- reach for the global $wpdb, not
     * this, whenever you are querying WordPress core tables.
     */
    public static function db(): \wpdb
    {
        global $wpdb;

        if (self::$db !== null) {
            return self::$db;
        }

        if (! defined('ESCALATED_DB_NAME')) {
            return self::$db = $wpdb;
        }

        $connection = new \wpdb(
            defined('ESCALATED_DB_USER') ? ESCALATED_DB_USER : DB_USER,
            defined('ESCALATED_DB_PASSWORD') ? ESCALATED_DB_PASSWORD : DB_PASSWORD,
            ESCALATED_DB_NAME,
            defined('ESCALATED_DB_HOST') ? ESCALATED_DB_HOST : DB_HOST,
        );

        // Table names are prefixed from the instance, so a dedicated database
        // can use its own prefix without inheriting the site's.
        $connection->set_prefix(
            defined('ESCALATED_DB_PREFIX') ? ESCALATED_DB_PREFIX : $wpdb->prefix
        );

        return self::$db = $connection;
    }

    /**
     * Forget the resolved connection. Only useful in tests, which swap the
     * global $wpdb between cases.
     */
    public static function flush_db(): void
    {
        self::$db = null;
    }

    public static function table(string $name): string
    {
        return self::db()->prefix.'escalated_'.$name;
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
