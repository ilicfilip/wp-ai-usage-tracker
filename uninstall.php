<?php
/**
 * Uninstall handler for AI Usage Tracker.
 *
 * Drops the plugin tables and deletes options ONLY when the admin has opted in
 * via the 'aiut_delete_on_uninstall' option. Default behaviour is to keep all
 * data so an accidental delete-and-reinstall does not lose usage history.
 *
 * @package WP_AIUT
 */

// Bail unless WordPress is genuinely uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect the opt-in: keep data unless explicitly told to delete it.
if ( ! get_option( 'aiut_delete_on_uninstall' ) ) {
	return;
}

global $wpdb;

// Mirror the naming used by wp_aiut_table(): {prefix}aiut_{name}. Uninstall runs
// in a bare context, so we rebuild the names inline rather than calling the helper.
// Keep this list in sync with Schema (events + counters + the Phase 2 limits table).
$aiut_events   = $wpdb->prefix . 'aiut_events';
$aiut_counters = $wpdb->prefix . 'aiut_counters';
$aiut_limits   = $wpdb->prefix . 'aiut_limits';

// Table names are built from a trusted prefix and cannot be parameterised in DDL.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$aiut_events}" );
$wpdb->query( "DROP TABLE IF EXISTS {$aiut_counters}" );
$wpdb->query( "DROP TABLE IF EXISTS {$aiut_limits}" );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove plugin options. Keep this in sync with every option the plugin writes:
// the hard-limit fast-path flag (Limit_Repository::HARD_FLAG_OPTION) is included
// so a fully opted-in uninstall leaves no orphaned autoloaded option behind.
delete_option( 'aiut_db_version' );
delete_option( 'aiut_delete_on_uninstall' );
delete_option( 'aiut_pricing' );
delete_option( 'aiut_settings' );
delete_option( 'aiut_has_hard_limits' );
