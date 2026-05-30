<?php
/**
 * CRUD over the configured usage limits (Phase 2).
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Limits;

use WP_AI_Rate_Limiter\Data\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the {prefix}aiut_limits table.
 *
 * A limit pins a threshold to a scope (plugin/user/role/model/global) for a
 * given metric (requests/tokens/cost) and period (day/month), with an
 * enforcement mode. The Enforcer and Threshold_Watcher consume these rows.
 *
 * Enabled-limits lookups are the hot path (read on every prompt), so the
 * "is anything enforceable at all?" question is answered from a cached flag —
 * see has_enabled_hard_limits().
 */
class Limit_Repository {

	/**
	 * Option caching whether any enabled hard limit exists (fast path).
	 */
	const HARD_FLAG_OPTION = 'aiut_has_hard_limits';

	/**
	 * Allowed enumerations, used for validation/sanitisation.
	 */
	const SCOPE_TYPES  = [ 'plugin', 'user', 'role', 'model', 'global' ];
	const LIMIT_TYPES  = [ 'requests', 'tokens', 'cost' ];
	const PERIOD_KINDS = [ 'day', 'month' ];
	const ENFORCEMENTS = [ 'off', 'soft', 'hard' ];
	const CONFIDENCES  = [ 'high', 'medium' ];

	/**
	 * Return all configured limits, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all() {
		global $wpdb;

		$table = Schema::limits_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? array_map( [ $this, 'cast_row' ], $rows ) : [];
	}

	/**
	 * Fetch a single limit by id.
	 *
	 * @param int $id Limit id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Schema::limits_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $this->cast_row( $row ) : null;
	}

	/**
	 * Return enabled limits matching a scope_type and (key or wildcard).
	 *
	 * Both the exact scope_key and the '*' wildcard rows are returned, so the
	 * matcher can apply "all plugins" limits alongside specific ones.
	 *
	 * @param string $scope_type One of SCOPE_TYPES.
	 * @param string $scope_key  Concrete scope key.
	 * @return array<int,array<string,mixed>>
	 */
	public function enabled_for_scope( $scope_type, $scope_key ) {
		global $wpdb;

		$table = Schema::limits_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE enabled = 1
					AND enforcement <> 'off'
					AND scope_type = %s
					AND scope_key IN ( %s, '*' )",
				$scope_type,
				(string) $scope_key
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? array_map( [ $this, 'cast_row' ], $rows ) : [];
	}

	/**
	 * Insert or update a limit (upsert on the unique scope key).
	 *
	 * @param array<string,mixed> $data Raw limit data.
	 * @return int|false The row id on success, false on failure.
	 */
	public function save( array $data ) {
		global $wpdb;

		$table = Schema::limits_table();
		$now   = current_time( 'mysql' );
		$clean = $this->sanitize( $data );

		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( $id > 0 ) {
			$clean['updated_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update( $table, $clean, [ 'id' => $id ] );
			$this->refresh_hard_flag();
			return false === $ok ? false : $id;
		}

		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $table, $clean );

		if ( false === $ok ) {
			return false;
		}

		$this->refresh_hard_flag();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a limit by id.
	 *
	 * @param int $id Limit id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$table = Schema::limits_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->delete( $table, [ 'id' => (int) $id ] );
		$this->refresh_hard_flag();

		return false !== $ok;
	}

	/**
	 * Whether any enabled hard limit exists (cached fast-path flag).
	 *
	 * The Enforcer reads this on every prompt; when false it short-circuits and
	 * the plugin behaves exactly like observe-only Phase 1.
	 *
	 * @return bool
	 */
	public function has_enabled_hard_limits() {
		$flag = get_option( self::HARD_FLAG_OPTION, null );

		if ( null === $flag ) {
			return $this->refresh_hard_flag();
		}

		return (bool) $flag;
	}

	/**
	 * Recompute and cache the enabled-hard-limit flag.
	 *
	 * @return bool The freshly computed flag.
	 */
	public function refresh_hard_flag() {
		global $wpdb;

		$table = Schema::limits_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND enforcement = 'hard'"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$flag = $count > 0;
		update_option( self::HARD_FLAG_OPTION, $flag ? 1 : 0, true );

		return $flag;
	}

	/**
	 * Sanitise raw limit data to storable columns.
	 *
	 * @param array<string,mixed> $data Raw input.
	 * @return array<string,mixed> Clean column => value map.
	 */
	private function sanitize( array $data ) {
		$enum = static function ( $value, array $allowed, $default ) {
			$value = is_string( $value ) ? $value : '';
			return in_array( $value, $allowed, true ) ? $value : $default;
		};

		return [
			'scope_type'     => $enum( $data['scope_type'] ?? '', self::SCOPE_TYPES, 'global' ),
			'scope_key'      => '' === (string) ( $data['scope_key'] ?? '' )
				? '*'
				: substr( sanitize_text_field( (string) $data['scope_key'] ), 0, 191 ),
			'limit_type'     => $enum( $data['limit_type'] ?? '', self::LIMIT_TYPES, 'cost' ),
			'period_kind'    => $enum( $data['period_kind'] ?? '', self::PERIOD_KINDS, 'month' ),
			'threshold'      => max( 0, (int) ( $data['threshold'] ?? 0 ) ),
			'enforcement'    => $enum( $data['enforcement'] ?? '', self::ENFORCEMENTS, 'soft' ),
			'min_confidence' => $enum( $data['min_confidence'] ?? '', self::CONFIDENCES, 'medium' ),
			'alert_80'       => empty( $data['alert_80'] ) ? 0 : 1,
			'alert_100'      => empty( $data['alert_100'] ) ? 0 : 1,
			'enabled'        => empty( $data['enabled'] ) ? 0 : 1,
		];
	}

	/**
	 * Cast a raw DB row to typed values for consumers/JSON.
	 *
	 * @param array<string,mixed> $row Raw string-typed DB row.
	 * @return array<string,mixed>
	 */
	private function cast_row( array $row ) {
		$row['id']        = (int) $row['id'];
		$row['threshold'] = (int) $row['threshold'];
		$row['alert_80']  = (int) $row['alert_80'];
		$row['alert_100'] = (int) $row['alert_100'];
		$row['enabled']   = (int) $row['enabled'];

		return $row;
	}
}
