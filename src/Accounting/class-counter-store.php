<?php
/**
 * Atomic counter store for pre-aggregated usage (the hot path).
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Accounting;

use WP_AI_Rate_Limiter\Data\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and atomically updates the {prefix}_aiut_counters table (spec §5).
 *
 * Increments use a single INSERT ... ON DUPLICATE KEY UPDATE statement so the
 * counter moves atomically without holding row locks across requests. The
 * UNIQUE(scope_type, scope_key, period_kind, period_key) key is what makes the
 * upsert collapse onto one row per scope/period.
 */
class Counter_Store {

	/**
	 * The numeric delta columns this store maintains.
	 *
	 * @var string[]
	 */
	const COLUMNS = [
		'requests',
		'input_tokens',
		'output_tokens',
		'thinking_tokens',
		'est_cost_micros',
	];

	/**
	 * Atomically increment the counters for one scope/period bucket.
	 *
	 * Performs an upsert: inserts a fresh row at the delta values, or adds the
	 * deltas to the existing row. Unknown delta keys are ignored; missing ones
	 * default to 0.
	 *
	 * @param string             $scope_type  'plugin'|'user'|'role'|'model'|'global'.
	 * @param string             $scope_key   Scope identity (slug, user id, role, model, '').
	 * @param string             $period_kind 'day'|'month'.
	 * @param string             $period_key  Period key, e.g. '2026-05-29' or '2026-05'.
	 * @param array<string, int> $deltas      Column => amount to add.
	 * @return bool True on success, false on query failure.
	 */
	public static function increment( $scope_type, $scope_key, $period_kind, $period_key, array $deltas ) {
		global $wpdb;

		$table = Schema::counters_table();

		// Normalise deltas to the known columns as non-negative integers.
		$values = [];
		foreach ( self::COLUMNS as $column ) {
			$values[ $column ] = isset( $deltas[ $column ] ) ? max( 0, (int) $deltas[ $column ] ) : 0;
		}

		$now = current_time( 'mysql' );

		// Build the upsert. The only interpolated token is the table name, which
		// comes from Schema::counters_table() (a $wpdb->prefix-derived constant,
		// never user input); all values are bound via prepare().
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted prefix-derived identifier.
			"INSERT INTO {$table}
				(scope_type, scope_key, period_kind, period_key,
				 requests, input_tokens, output_tokens, thinking_tokens, est_cost_micros, updated_at)
			VALUES (%s, %s, %s, %s, %d, %d, %d, %d, %d, %s)
			ON DUPLICATE KEY UPDATE
				requests = requests + VALUES(requests),
				input_tokens = input_tokens + VALUES(input_tokens),
				output_tokens = output_tokens + VALUES(output_tokens),
				thinking_tokens = thinking_tokens + VALUES(thinking_tokens),
				est_cost_micros = est_cost_micros + VALUES(est_cost_micros),
				updated_at = VALUES(updated_at)",
			$scope_type,
			$scope_key,
			$period_kind,
			$period_key,
			$values['requests'],
			$values['input_tokens'],
			$values['output_tokens'],
			$values['thinking_tokens'],
			$values['est_cost_micros'],
			$now
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is fully prepared above; custom-table upsert is not cacheable.
		$result = $wpdb->query( $sql );

		return false !== $result;
	}

	/**
	 * Read all counter rows for a scope type in a given period.
	 *
	 * @param string $scope_type  'plugin'|'user'|'role'|'model'|'global'.
	 * @param string $period_kind 'day'|'month'.
	 * @param string $period_key  Period key.
	 * @return array<int, array<string, mixed>> Rows ordered by est_cost_micros desc.
	 */
	public static function read( $scope_type, $period_kind, $period_key ) {
		global $wpdb;

		$table = Schema::counters_table();

		// Table name is a trusted, $wpdb->prefix-derived identifier; all values
		// are bound via prepare(). Custom-table read, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scope_type, scope_key, period_kind, period_key,
					requests, input_tokens, output_tokens, thinking_tokens, est_cost_micros, updated_at
				FROM {$table}
				WHERE scope_type = %s AND period_kind = %s AND period_key = %s
				ORDER BY est_cost_micros DESC",
				$scope_type,
				$period_kind,
				$period_key
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Read a single counter row for an exact scope/period bucket.
	 *
	 * @param string $scope_type  'plugin'|'user'|'role'|'model'|'global'.
	 * @param string $scope_key   Scope identity.
	 * @param string $period_kind 'day'|'month'.
	 * @param string $period_key  Period key.
	 * @return array<string, mixed>|null The row, or null if absent.
	 */
	public static function read_one( $scope_type, $scope_key, $period_kind, $period_key ) {
		global $wpdb;

		$table = Schema::counters_table();

		// Table name is a trusted, $wpdb->prefix-derived identifier; all values
		// are bound via prepare(). Custom-table read, not cacheable.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT scope_type, scope_key, period_kind, period_key,
					requests, input_tokens, output_tokens, thinking_tokens, est_cost_micros, updated_at
				FROM {$table}
				WHERE scope_type = %s AND scope_key = %s AND period_kind = %s AND period_key = %s
				LIMIT 1",
				$scope_type,
				$scope_key,
				$period_kind,
				$period_key
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $row : null;
	}
}
