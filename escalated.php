<?php
/**
 * Plugin Name: Escalated
 * Plugin URI:  https://github.com/escalated-dev/escalated-wordpress
 * Description: A full-featured helpdesk and ticketing system with multi-role support, SLA tracking, escalation rules, inbound email, macros, and REST API.
 * Version:     1.0.0
 * Author:      Escalated
 * Author URI:  https://escalated.dev
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: escalated
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ESCALATED_VERSION', '1.0.0' );
define( 'ESCALATED_PLUGIN_FILE', __FILE__ );
define( 'ESCALATED_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESCALATED_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ESCALATED_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once ESCALATED_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, [ \Escalated\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Escalated\Deactivator::class, 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    \Escalated\Escalated::instance()->boot();
} );
