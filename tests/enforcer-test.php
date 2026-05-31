<?php
/**
 * Tests for the Enforcer block decision (incl. fail-open guarantee).
 *
 * @package WP_AI_Rate_Limiter
 */

use WP_AI_Rate_Limiter\Enforcement\Enforcer;
use WP_AI_Rate_Limiter\Limits\Limit_Repository;
use WP_AI_Rate_Limiter\Limits\Limit_Evaluator;
use WP_AI_Rate_Limiter\Accounting\Counter_Store;
use WP_AI_Rate_Limiter\Periods\Window;

/**
 * @covers \WP_AI_Rate_Limiter\Enforcement\Enforcer
 */
class Enforcer_Test extends AIUT_TestCase {

	/**
	 * Seed current-month cost usage for plugin "acme".
	 *
	 * @param int $cost_micros Cost to record.
	 */
	private function seed_cost( $cost_micros ) {
		$this->seed_plugin_month_usage( 'acme', [ 'est_cost_micros' => $cost_micros ] );
	}

	/**
	 * Add a hard cost limit for plugin "acme".
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 */
	private function add_hard_limit( array $overrides = [] ) {
		$this->repo->save( $this->base_limit( $overrides ) );
	}

	/**
	 * With no hard limits at all, the Enforcer short-circuits to allow.
	 */
	public function test_no_hard_limits_never_blocks() {
		$enforcer = new Enforcer( $this->repo );
		$this->assertFalse( $enforcer->should_block( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * A breached hard limit blocks and fires the blocked action exactly once.
	 */
	public function test_breach_blocks_and_fires_action() {
		$this->add_hard_limit( [ 'threshold' => 1000 ] );
		$this->seed_cost( 2000 );

		$fired = 0;
		$cb    = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'wp_ai_rate_limiter_blocked', $cb );

		$enforcer = new Enforcer( $this->repo );
		$blocked  = $enforcer->should_block( [ 'plugin' => 'acme' ], 'high' );

		remove_action( 'wp_ai_rate_limiter_blocked', $cb );

		$this->assertTrue( $blocked );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Under the threshold => allow even though a hard limit exists.
	 */
	public function test_under_threshold_allows() {
		$this->add_hard_limit( [ 'threshold' => 1000 ] );
		$this->seed_cost( 500 );

		$enforcer = new Enforcer( $this->repo );
		$this->assertFalse( $enforcer->should_block( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * A breach under a high-confidence-only limit is NOT blocked for a
	 * medium-confidence (e.g. backtrace-attributed) request.
	 */
	public function test_low_confidence_request_not_blocked_by_high_only_limit() {
		$this->add_hard_limit( [ 'min_confidence' => 'high' ] );
		$this->seed_cost( 5000 );

		$enforcer = new Enforcer( $this->repo );
		$this->assertFalse( $enforcer->should_block( [ 'plugin' => 'acme' ], 'medium' ) );
		$this->assertTrue( $enforcer->should_block( [ 'plugin' => 'acme' ], 'high' ) );
	}

	/**
	 * Fail-open: if evaluation throws, the Enforcer allows the request.
	 *
	 * We force a throw by injecting an evaluator whose breach check explodes,
	 * while a real hard limit exists so the fast path does not short-circuit.
	 */
	public function test_fails_open_on_evaluator_error() {
		$this->add_hard_limit();

		$throwing = new class( $this->repo ) extends Limit_Evaluator {
			public function first_hard_breach( array $scopes, $confidence ) {
				throw new \RuntimeException( 'boom' );
			}
		};

		$enforcer = new Enforcer( $this->repo, $throwing );
		$this->assertFalse( $enforcer->should_block( [ 'plugin' => 'acme' ], 'high' ) );
	}
}
