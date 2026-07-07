<?php
/**
 * End-to-end tracking pipeline tests.
 *
 * These exercise the whole "a request happened -> it shows up on the dashboard"
 * path that the unit tests deliberately don't: a recorded usage row must land in
 * the cold events table, fan out to every hot counter (5 scopes x 2 period
 * kinds), compute the right integer-micros cost, and read back through the exact
 * Usage_Repository queries the REST layer / dashboard use.
 *
 * If tracking silently breaks — a counter scope dropped, cost drifting, a
 * repository read shape changing — one of these fails.
 *
 * @package WP_AIUT
 */

use WP_AIUT\Accounting\Usage_Recorder;
use WP_AIUT\Accounting\Counter_Store;
use WP_AIUT\Data\Usage_Repository;
use WP_AIUT\Periods\Window;

/**
 * @group tracking
 */
class Tracking_Pipeline_Test extends AIUT_TestCase {

	/**
	 * A realistic captured row: a self-identified plugin, one Opus call with
	 * input/output/thinking tokens, real (non-estimated) usage.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function sample_row( array $overrides = [] ) {
		return array_merge(
			[
				'plugin_slug'       => 'acme-ai',
				'plugin_confidence' => 'high',
				'user_id'           => 7,
				'user_role'         => 'editor',
				'provider'          => 'anthropic',
				'model'             => 'claude-opus-4-8',
				'input_tokens'      => 1000,
				'output_tokens'     => 500,
				'thinking_tokens'   => 200,
				'estimated'         => 0,
			],
			$overrides
		);
	}

	/**
	 * The day/month period keys for "now", as the recorder uses them.
	 *
	 * @return array{day:string,month:string}
	 */
	private function period_keys() {
		return [
			'day'   => Window::current_period_key( Window::KIND_DAY ),
			'month' => Window::current_period_key( Window::KIND_MONTH ),
		];
	}

	/**
	 * Recording a usage row appends exactly one event to the cold table with the
	 * sanitised values and the computed integer-micros cost.
	 */
	public function test_record_appends_one_event_with_computed_cost() {
		global $wpdb;

		$this->assertTrue( Usage_Recorder::record( $this->sample_row() ) );

		$events = $wpdb->prefix . 'aiut_events';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$events}", ARRAY_A );

		$this->assertCount( 1, $rows, 'Exactly one event row expected.' );

		$row = $rows[0];
		$this->assertSame( 'acme-ai', $row['plugin_slug'] );
		$this->assertSame( 'high', $row['plugin_confidence'] );
		$this->assertSame( 7, (int) $row['user_id'] );
		$this->assertSame( 'editor', $row['user_role'] );
		$this->assertSame( 'anthropic', $row['provider'] );
		$this->assertSame( 'claude-opus-4-8', $row['model'] );
		$this->assertSame( 1000, (int) $row['input_tokens'] );
		$this->assertSame( 500, (int) $row['output_tokens'] );
		$this->assertSame( 200, (int) $row['thinking_tokens'] );
		$this->assertSame( 0, (int) $row['estimated'] );

		// Opus family: input 15 / output 75 / thinking (defaults to output) 75,
		// priced per 1e6 tokens; micros = tokens * price.
		// 1000*15 + 500*75 + 200*75 = 15000 + 37500 + 15000 = 67500.
		$this->assertSame( 67500, (int) $row['est_cost_micros'] );
	}

	/**
	 * One recorded event fans out into all five counter scopes, for BOTH the day
	 * and month period, each carrying the same deltas. This is the core of the
	 * "tracking works" guarantee.
	 */
	public function test_record_fans_out_to_all_scopes_and_periods() {
		Usage_Recorder::record( $this->sample_row() );

		$keys = $this->period_keys();

		$expected_scopes = [
			[ 'plugin', 'acme-ai' ],
			[ 'user', '7' ],
			[ 'role', 'editor' ],
			[ 'model', 'anthropic/claude-opus-4-8' ],
			[ 'global', '__all__' ],
		];

		foreach ( [ 'day' => $keys['day'], 'month' => $keys['month'] ] as $kind => $period_key ) {
			foreach ( $expected_scopes as $scope ) {
				$counter = Counter_Store::read_one( $scope[0], $scope[1], $kind, $period_key );

				$this->assertNotNull(
					$counter,
					sprintf( 'Missing %s/%s counter for %s period.', $scope[0], $scope[1], $kind )
				);
				$this->assertSame( 1, (int) $counter['requests'] );
				$this->assertSame( 1000, (int) $counter['input_tokens'] );
				$this->assertSame( 500, (int) $counter['output_tokens'] );
				$this->assertSame( 200, (int) $counter['thinking_tokens'] );
				$this->assertSame( 67500, (int) $counter['est_cost_micros'] );
			}
		}
	}

	/**
	 * Two calls from the same plugin accumulate on the same counter row (the
	 * atomic upsert collapses onto the UNIQUE key), rather than creating a second
	 * row or overwriting.
	 */
	public function test_repeat_calls_accumulate_on_the_same_counter() {
		Usage_Recorder::record( $this->sample_row() );
		Usage_Recorder::record( $this->sample_row() );

		$keys    = $this->period_keys();
		$counter = Counter_Store::read_one( 'plugin', 'acme-ai', 'month', $keys['month'] );

		$this->assertNotNull( $counter );
		$this->assertSame( 2, (int) $counter['requests'] );
		$this->assertSame( 2000, (int) $counter['input_tokens'] );
		$this->assertSame( 1000, (int) $counter['output_tokens'] );
		$this->assertSame( 135000, (int) $counter['est_cost_micros'] );
	}

	/**
	 * Two different plugins produce two distinct plugin-scope rows, and the
	 * dashboard's ranked read returns them ordered by cost (desc).
	 */
	public function test_ranked_by_scope_returns_plugins_ordered_by_cost() {
		// Cheaper caller: Haiku, small.
		Usage_Recorder::record(
			$this->sample_row(
				[
					'plugin_slug'     => 'small-plugin',
					'model'           => 'claude-haiku-4',
					'input_tokens'    => 100,
					'output_tokens'   => 50,
					'thinking_tokens' => 0,
				]
			)
		);
		// Pricier caller: the default Opus sample.
		Usage_Recorder::record( $this->sample_row() );

		$keys = $this->period_keys();
		$rows = Usage_Repository::ranked_by_scope( 'plugin', 'month', $keys['month'] );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'acme-ai', $rows[0]['scope_key'], 'Priciest plugin ranks first.' );
		$this->assertSame( 'small-plugin', $rows[1]['scope_key'] );
		$this->assertGreaterThan(
			(int) $rows[1]['est_cost_micros'],
			(int) $rows[0]['est_cost_micros']
		);
	}

	/**
	 * The dashboard totals strip reads the authoritative global counter and the
	 * provider/model breakdowns from the events log.
	 */
	public function test_totals_reflect_recorded_usage() {
		Usage_Recorder::record( $this->sample_row() );
		Usage_Recorder::record(
			$this->sample_row(
				[
					'plugin_slug'   => 'other',
					'input_tokens'  => 200,
					'output_tokens' => 100,
					'thinking_tokens' => 0,
				]
			)
		);

		$keys   = $this->period_keys();
		$result = Usage_Repository::totals( 'month', $keys['month'] );

		$this->assertArrayHasKey( 'totals', $result );
		$totals = $result['totals'];

		$this->assertSame( 2, $totals['requests'] );
		$this->assertSame( 1200, $totals['input_tokens'] );   // 1000 + 200.
		$this->assertSame( 600, $totals['output_tokens'] );   // 500 + 100.
		$this->assertSame( 200, $totals['thinking_tokens'] ); // 200 + 0.

		// Both calls are anthropic, so the provider breakdown has a single entry.
		$this->assertNotEmpty( $result['by_provider'] );
	}

	/**
	 * Estimated captures (the chars/4 fallback path) are recorded and flagged so
	 * the dashboard can distinguish trusted from estimated numbers.
	 */
	public function test_estimated_flag_is_persisted() {
		global $wpdb;

		Usage_Recorder::record(
			$this->sample_row(
				[
					'estimated'       => 1,
					'output_tokens'   => 0,
					'thinking_tokens' => 0,
				]
			)
		);

		$events = $wpdb->prefix . 'aiut_events';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$estimated = (int) $wpdb->get_var( "SELECT estimated FROM {$events} LIMIT 1" );

		$this->assertSame( 1, $estimated );
	}

	/**
	 * Missing attribution falls back to the reserved __unknown__ bucket rather
	 * than dropping the event — unattributed spend must never be a blind spot.
	 */
	public function test_unattributed_usage_lands_in_unknown_bucket() {
		Usage_Recorder::record(
			$this->sample_row(
				[
					'plugin_slug'       => '',
					'plugin_confidence' => 'low',
				]
			)
		);

		$keys    = $this->period_keys();
		$counter = Counter_Store::read_one( 'plugin', '__unknown__', 'month', $keys['month'] );

		$this->assertNotNull( $counter, 'Unattributed usage must accrue under __unknown__.' );
		$this->assertSame( 1, (int) $counter['requests'] );
	}
}
