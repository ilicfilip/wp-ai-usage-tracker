<?php
/**
 * Evaluates configured limits against current usage (Phase 2).
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Limits;

use WP_AI_Rate_Limiter\Accounting\Counter_Store;
use WP_AI_Rate_Limiter\Periods\Window;

defined( 'ABSPATH' ) || exit;

/**
 * Given a request's scopes and attribution confidence, decides whether any
 * enabled hard limit is already breached (so the request should be blocked),
 * and computes usage percentages for alerting.
 *
 * Pure-ish: it reads counters via Counter_Store but holds no state. All access
 * is wrapped by callers so a fault fails open (allows).
 */
class Limit_Evaluator {

	/**
	 * Confidence ranking, higher = more trustworthy.
	 *
	 * @var array<string,int>
	 */
	private static $confidence_rank = [
		'low'    => 0,
		'medium' => 1,
		'high'   => 2,
	];

	/**
	 * Limit repository.
	 *
	 * @var Limit_Repository
	 */
	private $limits;

	/**
	 * Constructor.
	 *
	 * @param Limit_Repository $limits Limit repository.
	 */
	public function __construct( Limit_Repository $limits ) {
		$this->limits = $limits;
	}

	/**
	 * Find the first breached HARD limit for a request, if any.
	 *
	 * Iterates the request's scope dimensions, gathers enabled limits for each
	 * (specific key + wildcard), and returns the first hard limit whose current
	 * usage already meets or exceeds its threshold AND whose min_confidence is
	 * satisfied by this request's confidence.
	 *
	 * @param array<string,string> $scopes     scope_type => scope_key for this request.
	 * @param string               $confidence Attribution confidence ('high'|'medium'|'low').
	 * @return array<string,mixed>|null The breached limit (with 'current' usage) or null.
	 */
	public function first_hard_breach( array $scopes, $confidence ) {
		foreach ( $scopes as $scope_type => $scope_key ) {
			$limits = $this->limits->enabled_for_scope( $scope_type, (string) $scope_key );

			foreach ( $limits as $limit ) {
				if ( 'hard' !== $limit['enforcement'] ) {
					continue;
				}

				if ( ! $this->confidence_allows( $confidence, $limit['min_confidence'] ) ) {
					continue;
				}

				$current = $this->current_usage( $scope_type, (string) $scope_key, $limit );

				if ( $current >= (int) $limit['threshold'] && (int) $limit['threshold'] > 0 ) {
					$limit['current'] = $current;
					return $limit;
				}
			}
		}

		return null;
	}

	/**
	 * Current usage for a scope/limit in the limit's current period.
	 *
	 * @param string              $scope_type Scope type.
	 * @param string              $scope_key  Scope key.
	 * @param array<string,mixed> $limit      Limit row (provides limit_type, period_kind).
	 * @return int Usage in the limit's unit (requests, total tokens, or cost micros).
	 */
	public function current_usage( $scope_type, $scope_key, array $limit ) {
		$period_kind = $limit['period_kind'];
		$period_key  = Window::current_period_key( $period_kind );

		$row = Counter_Store::read_one( $scope_type, $scope_key, $period_kind, $period_key );

		if ( ! is_array( $row ) ) {
			return 0;
		}

		switch ( $limit['limit_type'] ) {
			case 'requests':
				return (int) ( $row['requests'] ?? 0 );
			case 'tokens':
				return (int) ( $row['input_tokens'] ?? 0 )
					+ (int) ( $row['output_tokens'] ?? 0 )
					+ (int) ( $row['thinking_tokens'] ?? 0 );
			case 'cost':
			default:
				return (int) ( $row['est_cost_micros'] ?? 0 );
		}
	}

	/**
	 * Whether a request's confidence satisfies a limit's minimum.
	 *
	 * @param string $confidence     Request confidence.
	 * @param string $min_confidence Limit's minimum required confidence.
	 * @return bool
	 */
	public function confidence_allows( $confidence, $min_confidence ) {
		$have = self::$confidence_rank[ $confidence ] ?? 0;
		$need = self::$confidence_rank[ $min_confidence ] ?? 1;

		return $have >= $need;
	}
}
