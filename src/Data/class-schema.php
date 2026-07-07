<?php
/**
 * Database schema installer for AI Usage Tracker.
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and maintains the plugin tables via dbDelta().
 *
 * Tables:
 *   - {prefix}aiut_events   : cold, append-only per-request detail (Phase 1).
 *   - {prefix}aiut_counters : hot, pre-aggregated per scope/period counters (Phase 1).
 *   - {prefix}aiut_limits   : configured usage limits (Phase 2).
 *
 * install() is idempotent: dbDelta() only applies diffs, and we store a
 * db-version option so repeated calls are cheap and safe.
 */
class Schema {

	/**
	 * Option key holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'aiut_db_version';

	/**
	 * Current schema version. Bump when the table structure changes.
	 *
	 * Version 2 adds the Phase 2 limits table.
	 */
	const DB_VERSION = '2';

	/**
	 * Fully prefixed name of the events table.
	 *
	 * @return string
	 */
	public static function events_table() {
		return wp_aiut_table( 'events' );
	}

	/**
	 * Fully prefixed name of the counters table.
	 *
	 * @return string
	 */
	public static function counters_table() {
		return wp_aiut_table( 'counters' );
	}

	/**
	 * Fully prefixed name of the limits table (Phase 2).
	 *
	 * @return string
	 */
	public static function limits_table() {
		return wp_aiut_table( 'limits' );
	}

	/**
	 * Install or update the database schema.
	 *
	 * Safe to call on every activation; dbDelta() reconciles the live schema
	 * with the declared one. Records the schema version in an option afterward.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$events          = self::events_table();
		$counters        = self::counters_table();
		$limits          = self::limits_table();

		// Cold, append-only per-request event log.
		$events_sql = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			plugin_slug varchar(191) NOT NULL DEFAULT '__unknown__',
			plugin_confidence varchar(20) NOT NULL DEFAULT 'low',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_role varchar(100) NOT NULL DEFAULT '',
			provider varchar(100) NOT NULL DEFAULT '',
			model varchar(191) NOT NULL DEFAULT '',
			input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			thinking_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			est_cost_micros bigint(20) unsigned NOT NULL DEFAULT 0,
			estimated tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY plugin_slug_created_at (plugin_slug, created_at),
			KEY user_id_created_at (user_id, created_at)
		) {$charset_collate};";

		// Hot, pre-aggregated counters. UNIQUE key powers atomic upserts.
		$counters_sql = "CREATE TABLE {$counters} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scope_type varchar(20) NOT NULL DEFAULT 'global',
			scope_key varchar(191) NOT NULL DEFAULT '',
			period_kind varchar(10) NOT NULL DEFAULT 'day',
			period_key varchar(20) NOT NULL DEFAULT '',
			requests bigint(20) unsigned NOT NULL DEFAULT 0,
			input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			thinking_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			est_cost_micros bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY scope_period (scope_type, scope_key, period_kind, period_key)
		) {$charset_collate};";

		// Configured usage limits (Phase 2). One row per
		// scope_type+scope_key+limit_type+period_kind.
		$limits_sql = "CREATE TABLE {$limits} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scope_type varchar(20) NOT NULL DEFAULT 'global',
			scope_key varchar(191) NOT NULL DEFAULT '*',
			limit_type varchar(20) NOT NULL DEFAULT 'cost',
			period_kind varchar(10) NOT NULL DEFAULT 'month',
			threshold bigint(20) unsigned NOT NULL DEFAULT 0,
			enforcement varchar(10) NOT NULL DEFAULT 'soft',
			min_confidence varchar(10) NOT NULL DEFAULT 'medium',
			alert_80 tinyint(1) NOT NULL DEFAULT 1,
			alert_100 tinyint(1) NOT NULL DEFAULT 1,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY scope_limit (scope_type, scope_key, limit_type, period_kind),
			KEY enabled_enforcement (enabled, enforcement)
		) {$charset_collate};";

		dbDelta( $events_sql );
		dbDelta( $counters_sql );
		dbDelta( $limits_sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
