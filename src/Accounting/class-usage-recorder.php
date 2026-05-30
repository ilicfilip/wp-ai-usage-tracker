<?php
/**
 * Usage recorder: persist one event and fan out to scope counters.
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Accounting;

use WP_AI_Rate_Limiter\Data\Schema;
use WP_AI_Rate_Limiter\Periods\Window;

defined( 'ABSPATH' ) || exit;

/**
 * Finalizes a captured request into durable storage (spec §3 "Recording").
 *
 * The record() method does two things for one usage event:
 *   1. Appends exactly one row to the cold {prefix}aiut_events log.
 *   2. Atomically increments the hot {prefix}aiut_counters for every active
 *      scope dimension (plugin, user, role, model, global) across both the
 *      'day' and 'month' periods.
 *
 * Counters are authoritative and are never derived from events — they survive
 * event pruning (spec §5 retention).
 */
class Usage_Recorder {

	/**
	 * Record one usage event and fan out the scope counters.
	 *
	 * Expected $row keys (missing keys default sensibly):
	 *   plugin_slug, plugin_confidence, user_id, user_role, provider, model,
	 *   input_tokens, output_tokens, thinking_tokens, estimated (bool).
	 *
	 * @param array<string, mixed> $row Captured usage data.
	 * @return bool True if the event row was inserted, false otherwise.
	 */
	public static function record( array $row ) {
		global $wpdb;

		$data = self::normalize( $row );

		// Estimate cost in integer micros (guarded — collaborator owned elsewhere).
		$cost_micros = 0;
		if ( class_exists( '\\WP_AI_Rate_Limiter\\Accounting\\Cost_Calculator' ) ) {
			$cost_micros = (int) Cost_Calculator::cost_micros(
				$data['provider'],
				$data['model'],
				$data['input_tokens'],
				$data['output_tokens'],
				$data['thinking_tokens']
			);
		}

		$created_at = current_time( 'mysql' );

		// 1) Append the cold event row.
		$inserted = $wpdb->insert(
			Schema::events_table(),
			[
				'created_at'        => $created_at,
				'plugin_slug'       => $data['plugin_slug'],
				'plugin_confidence' => $data['plugin_confidence'],
				'user_id'           => $data['user_id'],
				'user_role'         => $data['user_role'],
				'provider'          => $data['provider'],
				'model'             => $data['model'],
				'input_tokens'      => $data['input_tokens'],
				'output_tokens'     => $data['output_tokens'],
				'thinking_tokens'   => $data['thinking_tokens'],
				'est_cost_micros'   => $cost_micros,
				'estimated'         => $data['estimated'],
			],
			[
				'%s', // created_at.
				'%s', // plugin_slug.
				'%s', // plugin_confidence.
				'%d', // user_id.
				'%s', // user_role.
				'%s', // provider.
				'%s', // model.
				'%d', // input_tokens.
				'%d', // output_tokens.
				'%d', // thinking_tokens.
				'%d', // est_cost_micros.
				'%d', // estimated.
			]
		);

		// 2) Fan out to the hot counters across both period kinds.
		if ( class_exists( '\\WP_AI_Rate_Limiter\\Accounting\\Counter_Store' )
			&& class_exists( '\\WP_AI_Rate_Limiter\\Periods\\Window' ) ) {

			$deltas = [
				'requests'        => 1,
				'input_tokens'    => $data['input_tokens'],
				'output_tokens'   => $data['output_tokens'],
				'thinking_tokens' => $data['thinking_tokens'],
				'est_cost_micros' => $cost_micros,
			];

			$scopes = [
				[ 'plugin', $data['plugin_slug'] ],
				[ 'user', (string) $data['user_id'] ],
				[ 'role', $data['user_role'] ],
				[ 'model', self::model_scope_key( $data['provider'], $data['model'] ) ],
				[ 'global', '__all__' ],
			];

			$period_kinds = [ Window::KIND_DAY, Window::KIND_MONTH ];

			foreach ( $period_kinds as $kind ) {
				$period_key = Window::current_period_key( $kind );

				foreach ( $scopes as $scope ) {
					Counter_Store::increment( $scope[0], $scope[1], $kind, $period_key, $deltas );
				}
			}

			/**
			 * Fires after a usage event has been recorded and counters updated.
			 *
			 * The Threshold_Watcher uses this to detect 80%/100% limit crossings.
			 *
			 * @param array<string,mixed>          $data   The recorded (sanitised) event row.
			 * @param array<int,array<int,mixed>>  $scopes The scope tuples that were incremented.
			 */
			do_action( 'wp_ai_rate_limiter_usage_recorded', $data, $scopes );
		}

		return false !== $inserted;
	}

	/**
	 * Build the model scope key as "provider/model".
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model slug.
	 * @return string
	 */
	private static function model_scope_key( $provider, $model ) {
		$provider = '' === $provider ? '__unknown__' : $provider;
		$model    = '' === $model ? '__unknown__' : $model;

		return $provider . '/' . $model;
	}

	/**
	 * Normalize and sanitize an incoming row into the canonical event shape.
	 *
	 * @param array<string, mixed> $row Raw captured data.
	 * @return array<string, mixed> Sanitized values ready for storage.
	 */
	private static function normalize( array $row ) {
		$slug = isset( $row['plugin_slug'] ) ? sanitize_text_field( (string) $row['plugin_slug'] ) : '';
		if ( '' === $slug ) {
			$slug = '__unknown__';
		}

		$confidence = isset( $row['plugin_confidence'] ) ? sanitize_key( (string) $row['plugin_confidence'] ) : 'low';
		if ( ! in_array( $confidence, [ 'high', 'medium', 'low' ], true ) ) {
			$confidence = 'low';
		}

		$role = isset( $row['user_role'] ) ? sanitize_text_field( (string) $row['user_role'] ) : '';

		return [
			'plugin_slug'       => $slug,
			'plugin_confidence' => $confidence,
			'user_id'           => isset( $row['user_id'] ) ? max( 0, (int) $row['user_id'] ) : 0,
			'user_role'         => $role,
			'provider'          => isset( $row['provider'] ) ? sanitize_text_field( (string) $row['provider'] ) : '',
			'model'             => isset( $row['model'] ) ? sanitize_text_field( (string) $row['model'] ) : '',
			'input_tokens'      => isset( $row['input_tokens'] ) ? max( 0, (int) $row['input_tokens'] ) : 0,
			'output_tokens'     => isset( $row['output_tokens'] ) ? max( 0, (int) $row['output_tokens'] ) : 0,
			'thinking_tokens'   => isset( $row['thinking_tokens'] ) ? max( 0, (int) $row['thinking_tokens'] ) : 0,
			'estimated'         => ( isset( $row['estimated'] ) && $row['estimated'] ) ? 1 : 0,
		];
	}
}
