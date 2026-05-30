<?php
/**
 * Read-side repository for the dashboard.
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Data;

use WP_AI_Rate_Limiter\Periods\Window;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard read queries (spec §6/§7).
 *
 * Reads from the hot {prefix}_aiut_counters table where a pre-aggregated answer
 * exists (ranked tables, totals), and from the cold {prefix}_aiut_events table
 * only where per-row detail is required (daily timeseries buckets, per-provider
 * and per-model breakdown). Every query is parameterised via $wpdb->prepare().
 */
class Usage_Repository {

	/**
	 * Allowed counter scope types.
	 *
	 * @var string[]
	 */
	const SCOPE_TYPES = [ 'plugin', 'user', 'role', 'model', 'global' ];

	/**
	 * Counter rows for a scope type in a period, ranked by estimated cost.
	 *
	 * Powers the per-plugin and per-user/role league tables.
	 *
	 * @param string $scope_type  'plugin'|'user'|'role'|'model'|'global'.
	 * @param string $period_kind 'day'|'month'.
	 * @param string $period_key  Period key, e.g. '2026-05-29' or '2026-05'.
	 * @return array<int, array<string, mixed>> Rows ordered by est_cost_micros desc.
	 */
	public static function ranked_by_scope( $scope_type, $period_kind, $period_key ) {
		global $wpdb;

		$table = Schema::counters_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scope_type, scope_key, period_kind, period_key,
					requests, input_tokens, output_tokens, thinking_tokens, est_cost_micros, updated_at
				FROM {$table}
				WHERE scope_type = %s AND period_kind = %s AND period_key = %s
				ORDER BY est_cost_micros DESC, requests DESC",
				$scope_type,
				$period_kind,
				$period_key
			),
			ARRAY_A
		);

		return is_array( $rows ) ? self::cast_counter_rows( $rows ) : [];
	}

	/**
	 * Daily timeseries buckets for the over-time chart.
	 *
	 * Buckets events by calendar day in the site timezone over the half-open
	 * range [from, to). The 'metric' selects which value each bucket carries:
	 * 'cost' => est_cost_micros, 'tokens' => input+output+thinking tokens.
	 * When $scope_type/$scope_key are given, the series is filtered to that
	 * dimension (plugin slug, user id, or role) read from the event row.
	 *
	 * @param string      $metric     'cost'|'tokens'.
	 * @param string      $from       Start datetime, 'Y-m-d H:i:s' (inclusive).
	 * @param string      $to         End datetime, 'Y-m-d H:i:s' (exclusive).
	 * @param string|null $scope_type Optional 'plugin'|'user'|'role' filter.
	 * @param string|null $scope_key  Optional scope value for the filter.
	 * @return array<int, array{day:string,value:int,requests:int}>
	 */
	public static function timeseries( $metric, $from, $to, $scope_type = null, $scope_key = null ) {
		global $wpdb;

		$table = Schema::events_table();

		$value_expr = ( 'tokens' === $metric )
			? '( input_tokens + output_tokens + thinking_tokens )'
			: 'est_cost_micros';

		// Resolve an optional scope filter to an event column.
		$where         = 'created_at >= %s AND created_at < %s';
		$prepare_args  = [ $from, $to ];
		$filter_column = self::event_filter_column( $scope_type );

		if ( null !== $filter_column && null !== $scope_key && '' !== $scope_key ) {
			$where         .= " AND {$filter_column} = %s";
			$prepare_args[] = ( 'user_id' === $filter_column ) ? (int) $scope_key : (string) $scope_key;
		}

		// $value_expr and $filter_column are from internal whitelists, not input.
		$sql = "SELECT DATE(created_at) AS day,
				SUM({$value_expr}) AS value,
				COUNT(*) AS requests
			FROM {$table}
			WHERE {$where}
			GROUP BY DATE(created_at)
			ORDER BY day ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_args ), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'day'      => (string) $r['day'],
				'value'    => (int) $r['value'],
				'requests' => (int) $r['requests'],
			];
		}

		return $out;
	}

	/**
	 * Top-line totals plus per-provider and per-model breakdown for a period.
	 *
	 * Totals come from the authoritative 'global' counter row; the breakdown is
	 * computed from the event log over the same period range.
	 *
	 * @param string $period_kind 'day'|'month'.
	 * @param string $period_key  Period key.
	 * @return array<string, mixed> {
	 *     @type array $totals      Summed requests/tokens/cost.
	 *     @type array $by_provider Breakdown rows per provider.
	 *     @type array $by_model    Breakdown rows per provider/model.
	 * }
	 */
	public static function totals( $period_kind, $period_key ) {
		global $wpdb;

		$counters = Schema::counters_table();

		// Authoritative totals from the global counter.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
		$global = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT requests, input_tokens, output_tokens, thinking_tokens, est_cost_micros
				FROM {$counters}
				WHERE scope_type = 'global' AND period_kind = %s AND period_key = %s
				LIMIT 1",
				$period_kind,
				$period_key
			),
			ARRAY_A
		);

		$totals = [
			'requests'        => isset( $global['requests'] ) ? (int) $global['requests'] : 0,
			'input_tokens'    => isset( $global['input_tokens'] ) ? (int) $global['input_tokens'] : 0,
			'output_tokens'   => isset( $global['output_tokens'] ) ? (int) $global['output_tokens'] : 0,
			'thinking_tokens' => isset( $global['thinking_tokens'] ) ? (int) $global['thinking_tokens'] : 0,
			'est_cost_micros' => isset( $global['est_cost_micros'] ) ? (int) $global['est_cost_micros'] : 0,
		];

		$range = ( class_exists( '\\WP_AI_Rate_Limiter\\Periods\\Window' ) )
			? Window::range( $period_kind, $period_key )
			: null;

		$by_provider = [];
		$by_model    = [];

		if ( null !== $range ) {
			$from = $range['from']->format( 'Y-m-d H:i:s' );
			$to   = $range['to']->format( 'Y-m-d H:i:s' );

			$by_provider = self::breakdown( [ 'provider' ], $from, $to );
			$by_model    = self::breakdown( [ 'provider', 'model' ], $from, $to );
		}

		return [
			'totals'      => $totals,
			'by_provider' => $by_provider,
			'by_model'    => $by_model,
		];
	}

	/**
	 * Grouped breakdown of the event log over a datetime range.
	 *
	 * @param string[] $group_by    Event columns to group by (whitelisted).
	 * @param string   $from        Inclusive start, 'Y-m-d H:i:s'.
	 * @param string   $to          Exclusive end, 'Y-m-d H:i:s'.
	 * @return array<int, array<string, mixed>>
	 */
	private static function breakdown( array $group_by, $from, $to ) {
		global $wpdb;

		$table   = Schema::events_table();
		$allowed = [ 'provider', 'model' ];
		$cols    = array_values( array_intersect( $group_by, $allowed ) );

		if ( empty( $cols ) ) {
			return [];
		}

		$select_cols = implode( ', ', $cols );
		$group_cols  = implode( ', ', $cols );

		// $select_cols/$group_cols are derived from a fixed whitelist, not input.
		$sql = "SELECT {$select_cols},
				COUNT(*) AS requests,
				SUM(input_tokens) AS input_tokens,
				SUM(output_tokens) AS output_tokens,
				SUM(thinking_tokens) AS thinking_tokens,
				SUM(est_cost_micros) AS est_cost_micros
			FROM {$table}
			WHERE created_at >= %s AND created_at < %s
			GROUP BY {$group_cols}
			ORDER BY est_cost_micros DESC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $from, $to ), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$entry = [
				'requests'        => (int) $r['requests'],
				'input_tokens'    => (int) $r['input_tokens'],
				'output_tokens'   => (int) $r['output_tokens'],
				'thinking_tokens' => (int) $r['thinking_tokens'],
				'est_cost_micros' => (int) $r['est_cost_micros'],
			];

			foreach ( $cols as $col ) {
				$entry[ $col ] = (string) $r[ $col ];
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Map a counter scope type to the corresponding event-table column.
	 *
	 * @param string|null $scope_type Scope type.
	 * @return string|null Column name, or null when no per-event filter applies.
	 */
	private static function event_filter_column( $scope_type ) {
		switch ( $scope_type ) {
			case 'plugin':
				return 'plugin_slug';
			case 'user':
				return 'user_id';
			case 'role':
				return 'user_role';
			default:
				return null;
		}
	}

	/**
	 * Cast counter row string values into integers for clean JSON output.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw rows from $wpdb.
	 * @return array<int, array<string, mixed>>
	 */
	private static function cast_counter_rows( array $rows ) {
		$int_cols = [ 'requests', 'input_tokens', 'output_tokens', 'thinking_tokens', 'est_cost_micros' ];

		foreach ( $rows as &$row ) {
			foreach ( $int_cols as $col ) {
				if ( isset( $row[ $col ] ) ) {
					$row[ $col ] = (int) $row[ $col ];
				}
			}
		}
		unset( $row );

		return $rows;
	}
}
