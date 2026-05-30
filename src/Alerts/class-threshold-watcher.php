<?php
/**
 * Detects limit-threshold crossings and triggers alerts (Phase 2).
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Alerts;

use WP_AI_Rate_Limiter\Limits\Limit_Repository;
use WP_AI_Rate_Limiter\Limits\Limit_Evaluator;
use WP_AI_Rate_Limiter\Periods\Window;

defined( 'ABSPATH' ) || exit;

/**
 * After each usage event, checks whether any matching limit has crossed its
 * 80% or 100% alert threshold this period, and notifies once per crossing.
 *
 * Dedup is per (limit, period, threshold): a transient remembers which
 * thresholds have already fired so an alert is sent at most once per period.
 * Runs on the 'wp_ai_rate_limiter_usage_recorded' action.
 */
class Threshold_Watcher {

	/**
	 * Limit repository.
	 *
	 * @var Limit_Repository
	 */
	private $limits;

	/**
	 * Limit evaluator (for current-usage reads).
	 *
	 * @var Limit_Evaluator
	 */
	private $evaluator;

	/**
	 * Notifier.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Constructor.
	 *
	 * @param Limit_Repository|null $limits    Optional repository.
	 * @param Limit_Evaluator|null  $evaluator Optional evaluator.
	 * @param Notifier|null         $notifier  Optional notifier.
	 */
	public function __construct( $limits = null, $evaluator = null, $notifier = null ) {
		$this->limits    = $limits instanceof Limit_Repository ? $limits : new Limit_Repository();
		$this->evaluator = $evaluator instanceof Limit_Evaluator ? $evaluator : new Limit_Evaluator( $this->limits );
		$this->notifier  = $notifier instanceof Notifier ? $notifier : new Notifier();
	}

	/**
	 * Register the post-record hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ai_rate_limiter_usage_recorded', [ $this, 'on_recorded' ], 10, 2 );
	}

	/**
	 * Handle a recorded usage event: check each scope's matching limits.
	 *
	 * @param array<string,mixed>          $data   The recorded event row.
	 * @param array<int,array<int,string>> $scopes Scope tuples that were incremented.
	 * @return void
	 */
	public function on_recorded( $data, $scopes ) {
		try {
			if ( ! is_array( $scopes ) ) {
				return;
			}

			foreach ( $scopes as $scope ) {
				if ( ! is_array( $scope ) || count( $scope ) < 2 ) {
					continue;
				}

				$this->check_scope( (string) $scope[0], (string) $scope[1] );
			}
		} catch ( \Throwable $e ) {
			// Alerting must never disrupt recording.
			unset( $e );
		}
	}

	/**
	 * Check all enabled limits for one scope and alert on crossings.
	 *
	 * @param string $scope_type Scope type.
	 * @param string $scope_key  Scope key.
	 * @return void
	 */
	private function check_scope( $scope_type, $scope_key ) {
		$limits = $this->limits->enabled_for_scope( $scope_type, $scope_key );

		foreach ( $limits as $limit ) {
			$threshold = (int) $limit['threshold'];

			if ( $threshold <= 0 ) {
				continue;
			}

			$current = $this->evaluator->current_usage( $scope_type, $scope_key, $limit );
			$pct     = ( $current / $threshold ) * 100;

			// Check 100 before 80 so a single event crossing both fires the
			// higher (more urgent) alert.
			if ( $pct >= 100 && $limit['alert_100'] ) {
				$this->maybe_fire( $limit, $scope_key, $current, 100 );
			} elseif ( $pct >= 80 && $limit['alert_80'] ) {
				$this->maybe_fire( $limit, $scope_key, $current, 80 );
			}
		}
	}

	/**
	 * Fire an alert unless it already fired this period.
	 *
	 * @param array<string,mixed> $limit     The limit row.
	 * @param string              $scope_key Concrete scope key (may differ from a '*' limit).
	 * @param int                 $current   Current usage.
	 * @param int                 $percent   Threshold crossed.
	 * @return void
	 */
	private function maybe_fire( array $limit, $scope_key, $current, $percent ) {
		$period_key = Window::current_period_key( $limit['period_kind'] );
		$dedup      = 'aiut_alert_' . md5(
			$limit['id'] . '|' . $scope_key . '|' . $period_key . '|' . $percent
		);

		if ( get_transient( $dedup ) ) {
			return;
		}

		// Remember for the rest of the (longest plausible) period: 32 days.
		set_transient( $dedup, 1, 32 * DAY_IN_SECONDS );

		// Annotate the limit with the concrete scope key for the message.
		$limit['scope_key'] = $scope_key;

		$this->notifier->notify( $limit, (int) $current, (int) $percent );
	}
}
