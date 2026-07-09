<?php
/**
 * Interop bridge for the WordPress/ai "Connector Approval" experiment.
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Interop;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the official WordPress/ai plugin's experimental
 * "Connector Approval" feature.
 *
 * That feature gates connector use behind per-plugin administrator approval and
 * blocks unapproved requests at the HTTP layer — *before* our capture path sees
 * a result, so those blocked requests are otherwise invisible to our dashboard.
 * This bridge surfaces them: when the experiment is active we read its two
 * options and expose the approval matrix and the pending-block queue so the
 * dashboard can show "N requests blocked upstream, excluded from tracked usage".
 *
 * This class only ever *reads* those options — it never writes them, and it has
 * no hard dependency on the ai plugin (hard invariant #2): when the experiment
 * is inactive it reports {active:false} and reads nothing.
 */
class Connector_Approval_Bridge {

	/**
	 * Global experiments toggle option in the ai plugin.
	 */
	const OPTION_FEATURES_ENABLED = 'wpai_features_enabled';

	/**
	 * Per-feature toggle option for the connector-approval experiment.
	 */
	const OPTION_FEATURE_ENABLED = 'wpai_feature_connector-approval_enabled';

	/**
	 * Option holding the approval matrix: {caller_basename => {connector_id => bool}}.
	 */
	const OPTION_APPROVALS = 'wpai_connector_approvals';

	/**
	 * Option holding the pending (denied) block queue.
	 */
	const OPTION_PENDING = 'wpai_connector_approval_pending';

	/**
	 * Whether the ai plugin's connector-approval experiment is enabled.
	 *
	 * Mirrors Abstract_Feature::is_enabled(): both the global experiments toggle
	 * and the per-feature toggle must be on. We cannot observe the ai plugin's
	 * runtime filter override, but the option state is authoritative for whether
	 * the feature is doing anything.
	 *
	 * @return bool
	 */
	public function is_active() {
		return (bool) get_option( self::OPTION_FEATURES_ENABLED, false )
			&& (bool) get_option( self::OPTION_FEATURE_ENABLED, false );
	}

	/**
	 * Return the interop state for the dashboard.
	 *
	 * When the experiment is inactive, returns {active:false} and reads nothing
	 * else. When active, returns the pending block queue and approval matrix
	 * normalised into flat lists for display.
	 *
	 * @return array<string,mixed>
	 */
	public function get_state() {
		if ( ! $this->is_active() ) {
			return [ 'active' => false ];
		}

		return [
			'active'    => true,
			'pending'   => $this->read_pending(),
			'approvals' => $this->read_approvals(),
		];
	}

	/**
	 * Read and normalise the pending-block queue.
	 *
	 * The ai plugin's raw option may store non-canonical caller basenames (a bare
	 * slug such as "ai" rather than "ai/ai.php"); we display them as stored and do
	 * not attempt to canonicalise (that logic lives in the ai plugin).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function read_pending() {
		$raw = get_option( self::OPTION_PENDING, [] );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$out[] = [
				'caller_type'     => isset( $entry['caller_type'] ) ? (string) $entry['caller_type'] : '',
				'caller_basename' => isset( $entry['caller_basename'] ) ? (string) $entry['caller_basename'] : '',
				'caller_name'     => isset( $entry['caller_name'] ) ? (string) $entry['caller_name'] : '',
				'connector_id'    => isset( $entry['connector_id'] ) ? (string) $entry['connector_id'] : '',
				'attempts'        => isset( $entry['attempts'] ) ? (int) $entry['attempts'] : 0,
				'first_seen'      => isset( $entry['first_seen'] ) ? (int) $entry['first_seen'] : 0,
				'last_seen'       => isset( $entry['last_seen'] ) ? (int) $entry['last_seen'] : 0,
			];
		}

		return $out;
	}

	/**
	 * Read and flatten the approval matrix into a list of approved pairs.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function read_approvals() {
		$raw = get_option( self::OPTION_APPROVALS, [] );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];

		foreach ( $raw as $caller => $connectors ) {
			if ( ! is_string( $caller ) || ! is_array( $connectors ) ) {
				continue;
			}

			foreach ( $connectors as $connector_id => $approved ) {
				if ( ! is_string( $connector_id ) || ! $approved ) {
					continue;
				}

				$out[] = [
					'caller_basename' => $caller,
					'connector_id'    => $connector_id,
				];
			}
		}

		return $out;
	}
}
