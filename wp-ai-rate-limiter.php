<?php
/**
 * Plugin Name:       AI Usage Tracker
 * Plugin URI:        https://github.com/ilicfilip/wp-ai-usage-tracker
 * Description:       Tracks WordPress 7.0 AI Client usage per plugin and per user (tokens + estimated cost) on a dashboard, and optionally enforces configurable usage limits. Observe-only until a hard limit is configured.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Filip Ilic
 * Author URI:        https://emilia.capital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-aiut
 *
 * @package WP_AIUT
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
define( 'WP_AIUT_VERSION', '0.1.0' );

/**
 * Absolute path to the main plugin file.
 */
define( 'WP_AIUT_PLUGIN_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with trailing slash.
 */
define( 'WP_AIUT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with trailing slash.
 */
define( 'WP_AIUT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Build the prefixed database table name for an AI Usage Tracker table.
 *
 * Tables are named `{$wpdb->prefix}aiut_{$name}`, for example `wp_aiut_events`
 * ($wpdb->prefix already ends in an underscore). Centralised here so every
 * consumer agrees on the name.
 *
 * @param string $name Bare table name, e.g. 'events' or 'counters'.
 * @return string Fully prefixed table name.
 */
function wp_aiut_table( $name ) {
	global $wpdb;

	return $wpdb->prefix . 'aiut_' . $name;
}

/**
 * Autoloader for the \WP_AIUT\ namespace, using the WordPress
 * file-naming convention (class-{name}.php, lowercase, hyphen-separated).
 *
 * Sub-namespaces map to directories and the final class name maps to a
 * class-prefixed file, so \WP_AIUT\Capture\Gatekeeper resolves to
 * src/Capture/class-gatekeeper.php and \WP_AIUT\Data\Schema resolves
 * to src/Data/class-schema.php.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function wp_aiut_autoload( $class ) {
	$prefix = 'WP_AIUT\\';
	$len    = strlen( $prefix );

	// Bail early for classes outside our namespace.
	if ( 0 !== strncmp( $prefix, $class, $len ) ) {
		return;
	}

	// Strip the namespace prefix, leaving e.g. "Data\Schema" or "Plugin".
	$relative = substr( $class, $len );
	$parts    = explode( '\\', $relative );

	// The last segment is the class name; everything before it is the sub-path.
	$class_name = array_pop( $parts );
	$file_name  = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

	$sub_path = empty( $parts ) ? '' : implode( '/', $parts ) . '/';
	$path     = WP_AIUT_PLUGIN_DIR . 'src/' . $sub_path . $file_name;

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( 'wp_aiut_autoload' );

/**
 * Determine whether the host environment satisfies the plugin requirements.
 *
 * Requires WordPress >= 7.0 and the AI Client entry point. Used by both the
 * activation guard and the runtime boot guard.
 *
 * @return bool True when the environment is supported.
 */
function wp_aiut_environment_ok() {
	global $wp_version;

	$wp_ok  = version_compare( $wp_version, '7.0', '>=' );
	$api_ok = function_exists( 'wp_ai_client_prompt' );

	return $wp_ok && $api_ok;
}

/**
 * Activation handler: verify requirements, then install the schema.
 *
 * If the environment is unsupported we self-deactivate and queue an admin
 * notice rather than triggering a fatal error.
 *
 * @return void
 */
function wp_aiut_activate() {
	if ( ! wp_aiut_environment_ok() ) {
		// Refuse to activate: deactivate ourselves and flag a notice.
		deactivate_plugins( plugin_basename( WP_AIUT_PLUGIN_FILE ) );
		set_transient( 'wp_aiut_activation_error', 1, 60 );
		return;
	}

	if ( class_exists( '\\WP_AIUT\\Data\\Schema' ) ) {
		\WP_AIUT\Data\Schema::install();
	}
}
register_activation_hook( __FILE__, 'wp_aiut_activate' );

/**
 * Render the admin notice shown when activation was refused.
 *
 * @return void
 */
function wp_aiut_activation_notice() {
	if ( ! get_transient( 'wp_aiut_activation_error' ) ) {
		return;
	}

	delete_transient( 'wp_aiut_activation_error' );

	$message = __(
		'AI Usage Tracker requires WordPress 7.0 or later with the AI Client API enabled (wp_ai_client_prompt). The plugin was not activated.',
		'wp-aiut'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}
add_action( 'admin_notices', 'wp_aiut_activation_notice' );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * Guards on the environment so a downgrade or disabled AI Client does not fatal
 * an already-active install — it simply stays dormant.
 *
 * @return void
 */
function wp_aiut_boot() {
	if ( ! wp_aiut_environment_ok() ) {
		return;
	}

	if ( class_exists( '\\WP_AIUT\\Plugin' ) ) {
		\WP_AIUT\Plugin::boot();
	}
}
add_action( 'plugins_loaded', 'wp_aiut_boot' );
