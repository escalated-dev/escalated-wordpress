<?php

/**
 * Plugin Name: Escalated
 * Plugin URI:  https://github.com/escalated-dev/escalated-wordpress
 * Description: A full-featured helpdesk and ticketing system with multi-role support, SLA tracking, escalation rules, inbound email, macros, and REST API.
 * Version:     1.3.0
 * Author:      Escalated
 * Author URI:  https://escalated.dev
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: escalated
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */
if (! defined('ABSPATH')) {
    exit;
}

define('ESCALATED_VERSION', '1.3.0');
define('ESCALATED_PLUGIN_FILE', __FILE__);
define('ESCALATED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ESCALATED_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ESCALATED_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once ESCALATED_PLUGIN_DIR.'vendor/autoload.php';

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Escalated\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $segments = explode('\\', $relative);
        $class_name = array_pop($segments);
        $subdir = ! empty($segments) ? implode('/', $segments).'/' : '';

        // Prefer PSR-4-style files (e.g. includes/Services/TicketService.php).
        $psr4_file = ESCALATED_PLUGIN_DIR.'includes/'.$subdir.$class_name.'.php';
        if (file_exists($psr4_file)) {
            require_once $psr4_file;

            return;
        }

        // Fallback for WordPress-style class files (e.g. includes/Api/class-ticket-controller.php).
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $class_name);
        $normalized = strtolower(str_replace('_', '-', (string) $normalized));
        $legacy_file = ESCALATED_PLUGIN_DIR.'includes/'.$subdir.'class-'.$normalized.'.php';

        if (file_exists($legacy_file)) {
            require_once $legacy_file;
        }
    }
);

register_activation_hook(__FILE__, [\Escalated\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\Escalated\Deactivator::class, 'deactivate']);

add_action('plugins_loaded', function () {
    \Escalated\Activator::maybe_upgrade();
    \Escalated\Escalated::instance()->boot();
});
