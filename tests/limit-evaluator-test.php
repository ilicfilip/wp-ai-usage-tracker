<?php
/**
 * Tests for the Limit_Evaluator breach detection + confidence gating.
 *
 * @package WP_AI_Rate_Limiter
 */

use WP_AI_Rate_Limiter\Limits\Limit_Repository;
use WP_AI_Rate_Limiter\Limits\Limit_Evaluator;
use WP_AI_Rate_Limiter\Accounting\Counter_Store;
use WP_AI_Rate_Limiter\Periods\Window;

/**
 * @covers \WP_AI_Rate_Limiter\Limits\Limit_Evaluator
 */
class Limit_Evaluator_Test extends AIUT_TestCase {

	/**
	 * @var Limit_Repository
	 */
	private $repo;

	/**
	 * @var Limit_Evaluator
	 */
	private $evaluator;

	public function set_up() {
		parent::set_up();
		$this->repo      = new Limit_Repository();
		$this->evaluator = new Limit_Evaluator( $this->repo );
	}

	/**
	 * Seed counters for the current month for a plugin scope.
	 *
	 * @param string $scope_key Plugin slug.
	 * @param array<string,int> $deltas Counter deltas.
	 */
	private function seed_month_usage( $scope_key, array $deltas ) {
		Counter_Store::increment(
			'plugin',
			$scope_key,
			'month',
			Window::current_period_key( 'month' ),
			$deltas
		);
	}

	/**
	 * Base hard cost limit for plugin "acme".
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function hard_limit( array $overrides = [] ) {
		return array_merge(
			[
				'scope_type'     => 'plugin',
				'scope_key'      => 'acme',
				'limit_type'     => 'cost',
				'period_kind'    => 'month',
				'threshold'      => 1000,
				'enforcement'    => 'hard',
				'min_confidence' => 'medium',
				'enabled'        => 1,
			],
			$overrides
		);
	}

	/**
	 * Usage at or above the threshold is a breach.
	 */
	public function test_breach_when_usage_meets_threshold() {
		$this->repo->save( $this->hard_limit( [ 'threshold' => 1000 ] ) );
		$this->seed_month_usage( 'acme', [ 'est_cost_micros' => 1000 ] );

		$breach = $this->evaluator->first_hard_breach( [ 'plugin' => 'acme' ], 'high' );
		$this->assertIsArray( $breach );
		$this->assertSame( 1000, $breach['current'] );
	}

	/**
	 * Usage below the threshold is not a breach.
	 */
	public function test_no_breach_when_under_threshold() {
		$this->repo->save( $this->hard_limit( [ 'threshold' => 1000 ] ) );
		$this->seed_month_usage( 'acme', [ 'est_cost_micros' => 999 ] );

		$this->assertNull( $this->evaluator->first_hard_breach( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * A zero threshold is treated as "unset" and never breaches.
	 */
	public function test_zero_threshold_never_breaches() {
		$this->repo->save( $this->hard_limit( [ 'threshold' => 0 ] ) );
		$this->seed_month_usage( 'acme', [ 'est_cost_micros' => 5000 ] );

		$this->assertNull( $this->evaluator->first_hard_breach( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * Soft limits are ignored by hard-breach detection.
	 */
	public function test_soft_limit_ignored() {
		$this->repo->save( $this->hard_limit( [ 'enforcement' => 'soft' ] ) );
		$this->seed_month_usage( 'acme', [ 'est_cost_micros' => 99999 ] );

		$this->assertNull( $this->evaluator->first_hard_breach( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * A min_confidence=high limit does not apply to a medium-confidence request.
	 */
	public function test_confidence_gating_blocks_lower_confidence() {
		$this->repo->save( $this->hard_limit( [ 'min_confidence' => 'high' ] ) );
		$this->seed_month_usage( 'acme', [ 'est_cost_micros' => 5000 ] );

		// medium < high => not enforced.
		$this->assertNull( $this->evaluator->first_hard_breach( [ 'plugin' => 'acme' ], 'medium' ) );
		// high satisfies high => breach.
		$this->assertIsArray( $this->evaluator->first_hard_breach( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * A medium-confidence limit applies to high-confidence requests too.
	 */
	public function test_confidence_medium_limit_applies_to_high_request() {
		$this->repo->save( $this->hard_limit( [ 'min_confidence' => 'medium' ] ) );
		$this->seed_month_usage( 'acme', [ 'est_cost_micros' => 5000 ] );
		$this->assertIsArray( $this->evaluator->first_hard_breach( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * A wildcard limit applies to any key of that scope type.
	 */
	public function test_wildcard_limit_applies() {
		$this->repo->save( $this->hard_limit( [ 'scope_key' => '*', 'threshold' => 1000 ] ) );
		$this->seed_month_usage( 'whatever', [ 'est_cost_micros' => 2000 ] );

		$breach = $this->evaluator->first_hard_breach( [ 'plugin' => 'whatever' ], 'high' );
		$this->assertIsArray( $breach );
	}

	/**
	 * current_usage sums the right counter column per limit_type.
	 */
	public function test_current_usage_per_limit_type() {
		$this->seed_month_usage(
			'acme',
			[
				'requests'        => 4,
				'input_tokens'    => 100,
				'output_tokens'   => 50,
				'thinking_tokens' => 10,
				'est_cost_micros' => 777,
			]
		);

		$period = [ 'period_kind' => 'month' ];

		$this->assertSame( 4, $this->evaluator->current_usage( 'plugin', 'acme', $period + [ 'limit_type' => 'requests' ] ) );
		$this->assertSame( 160, $this->evaluator->current_usage( 'plugin', 'acme', $period + [ 'limit_type' => 'tokens' ] ) );
		$this->assertSame( 777, $this->evaluator->current_usage( 'plugin', 'acme', $period + [ 'limit_type' => 'cost' ] ) );
	}

	/**
	 * confidence_allows ranks low < medium < high.
	 */
	public function test_confidence_allows_ranking() {
		$this->assertTrue( $this->evaluator->confidence_allows( 'high', 'medium' ) );
		$this->assertTrue( $this->evaluator->confidence_allows( 'medium', 'medium' ) );
		$this->assertFalse( $this->evaluator->confidence_allows( 'low', 'medium' ) );
		$this->assertFalse( $this->evaluator->confidence_allows( 'medium', 'high' ) );
	}
}
