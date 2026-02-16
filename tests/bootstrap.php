<?php
/**
 * PHPUnit bootstrap file for Escalated plugin tests.
 *
 * @package Escalated
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find $_tests_dir/includes/functions.php\n";
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function() {
    require dirname( __DIR__ ) . '/escalated.php';
});

require $_tests_dir . '/includes/bootstrap.php';

// Activate plugin tables, roles, and defaults once before tests run.
\Escalated\Activator::activate();
