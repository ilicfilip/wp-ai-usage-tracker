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

// Mirror the naming used by wp_aiut_table(): {prefix}aiut_{name}.
$aiut_events   = $wpdb->prefix . 'aiut_events';
$aiut_counters = $wpdb->prefix . 'aiut_counters';

// Table names are built from a trusted prefix and cannot be parameterised in DDL.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$aiut_events}" );
$wpdb->query( "DROP TABLE IF EXISTS {$aiut_counters}" );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove plugin options.
delete_option( 'aiut_db_version' );
delete_option( 'aiut_delete_on_uninstall' );
delete_option( 'aiut_pricing' );
delete_option( 'aiut_settings' );
