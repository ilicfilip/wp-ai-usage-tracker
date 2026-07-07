<?php
/**
 * PHPUnit bootstrap for the WP integration test suite.
 *
 * Loads the WordPress test library (WP_UnitTestCase) and force-activates this
 * plugin inside the test WP install so its real hooks, schema and helpers are
 * available to the tests.
 *
 * @package WP_AIUT
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills path if defined (WP >= 5.9 test suite).
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php — run bin/install-wp-tests.sh first." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

// Give access to tests_add_filter().
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _wp_aiut_manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-ai-rate-limiter.php';
}
tests_add_filter( 'muplugins_loaded', '_wp_aiut_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

// Install the plugin schema ONCE, before any per-test transaction starts.
//
// WP_UnitTestCase wraps every test in a DB transaction that is rolled back in
// tearDown. Running dbDelta() (DDL) inside a test triggers MySQL's implicit
// commit and behaves unpredictably across the suite, so we create the custom
// tables here, outside any transaction. Per-test isolation for row data is then
// provided for free by the framework's transaction rollback.
\WP_AIUT\Data\Schema::install();

// Shared abstract base test case (not auto-collected: no -test.php suffix).
require __DIR__ . '/class-aiut-testcase.php';

// Shared test doubles, loaded here so their availability does not depend on the
// order PHPUnit happens to load the individual *-test.php files in.
require __DIR__ . '/class-aiut-test-doubles.php';
