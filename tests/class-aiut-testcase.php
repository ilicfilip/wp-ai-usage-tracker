<?php
/**
 * Shared base test case: installs the plugin schema and resets tables.
 *
 * @package WP_AI_Rate_Limiter
 */

use WP_AI_Rate_Limiter\Limits\Limit_Repository;
use WP_AI_Rate_Limiter\Accounting\Counter_Store;
use WP_AI_Rate_Limiter\Periods\Window;

/**
 * Base class for the plugin's integration tests.
 *
 * The schema is installed once in tests/bootstrap.php (outside any per-test
 * transaction). Row-level isolation between tests is provided by the
 * WP_UnitTestCase transaction that wraps each test and is rolled back in
 * tearDown — so every test sees empty plugin tables and a clean options table
 * without any explicit truncation here.
 */
abstract class AIUT_TestCase extends WP_UnitTestCase {

	/**
	 * Limit repository, ready for every test that touches limits.
	 *
	 * @var Limit_Repository
	 */
	protected $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = new Limit_Repository();
	}

	/**
	 * Seed current-month counters for a plugin scope.
	 *
	 * @param string             $scope_key Plugin slug.
	 * @param array<string,int>  $deltas    Counter deltas (e.g. ['est_cost_micros' => 5000]).
	 * @return void
	 */
	protected function seed_plugin_month_usage( $scope_key, array $deltas ) {
		Counter_Store::increment(
			'plugin',
			$scope_key,
			'month',
			Window::current_period_key( 'month' ),
			$deltas
		);
	}

	/**
	 * A limit payload with sensible defaults (plugin "acme", monthly hard cost
	 * cap), overridable per test.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	protected function base_limit( array $overrides = [] ) {
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
}
