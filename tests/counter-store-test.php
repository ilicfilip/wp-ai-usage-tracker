<?php
/**
 * Tests for the atomic Counter_Store.
 *
 * @package WP_AIUT
 */

use WP_AIUT\Accounting\Counter_Store;

/**
 * @covers \WP_AIUT\Accounting\Counter_Store
 */
class Counter_Store_Test extends AIUT_TestCase {

	/**
	 * A first increment inserts the row at the delta values.
	 */
	public function test_increment_inserts_row() {
		$ok = Counter_Store::increment(
			'plugin',
			'acme',
			'day',
			'2026-05-29',
			[
				'requests'        => 1,
				'input_tokens'    => 100,
				'output_tokens'   => 50,
				'est_cost_micros' => 1234,
			]
		);
		$this->assertTrue( $ok );

		$row = Counter_Store::read_one( 'plugin', 'acme', 'day', '2026-05-29' );
		$this->assertIsArray( $row );
		$this->assertSame( 1, (int) $row['requests'] );
		$this->assertSame( 100, (int) $row['input_tokens'] );
		$this->assertSame( 50, (int) $row['output_tokens'] );
		$this->assertSame( 1234, (int) $row['est_cost_micros'] );
	}

	/**
	 * Re-incrementing the same scope/period adds onto the existing row (upsert).
	 */
	public function test_increment_accumulates_on_duplicate_key() {
		$deltas = [
			'requests'        => 1,
			'input_tokens'    => 10,
			'est_cost_micros' => 100,
		];
		Counter_Store::increment( 'plugin', 'acme', 'day', '2026-05-29', $deltas );
		Counter_Store::increment( 'plugin', 'acme', 'day', '2026-05-29', $deltas );
		Counter_Store::increment( 'plugin', 'acme', 'day', '2026-05-29', $deltas );

		$row = Counter_Store::read_one( 'plugin', 'acme', 'day', '2026-05-29' );
		$this->assertSame( 3, (int) $row['requests'] );
		$this->assertSame( 30, (int) $row['input_tokens'] );
		$this->assertSame( 300, (int) $row['est_cost_micros'] );
	}

	/**
	 * Different period keys are independent rows (no destructive reset).
	 */
	public function test_separate_periods_are_independent() {
		Counter_Store::increment( 'plugin', 'acme', 'day', '2026-05-29', [ 'requests' => 5 ] );
		Counter_Store::increment( 'plugin', 'acme', 'day', '2026-05-30', [ 'requests' => 7 ] );

		$this->assertSame( 5, (int) Counter_Store::read_one( 'plugin', 'acme', 'day', '2026-05-29' )['requests'] );
		$this->assertSame( 7, (int) Counter_Store::read_one( 'plugin', 'acme', 'day', '2026-05-30' )['requests'] );
	}

	/**
	 * Unknown delta keys are ignored; missing ones default to zero.
	 */
	public function test_unknown_delta_keys_ignored() {
		Counter_Store::increment(
			'plugin',
			'acme',
			'day',
			'2026-05-29',
			[
				'requests'  => 2,
				'bogus_key' => 9999,
			]
		);

		$row = Counter_Store::read_one( 'plugin', 'acme', 'day', '2026-05-29' );
		$this->assertSame( 2, (int) $row['requests'] );
		$this->assertSame( 0, (int) $row['input_tokens'] );
		$this->assertArrayNotHasKey( 'bogus_key', $row );
	}

	/**
	 * Negative deltas are clamped to zero.
	 */
	public function test_negative_deltas_clamped() {
		Counter_Store::increment( 'plugin', 'acme', 'day', '2026-05-29', [ 'requests' => -10 ] );
		$row = Counter_Store::read_one( 'plugin', 'acme', 'day', '2026-05-29' );
		$this->assertSame( 0, (int) $row['requests'] );
	}

	/**
	 * read() returns rows for the scope type ordered by cost desc.
	 */
	public function test_read_orders_by_cost_desc() {
		Counter_Store::increment( 'plugin', 'cheap', 'day', '2026-05-29', [ 'est_cost_micros' => 100 ] );
		Counter_Store::increment( 'plugin', 'pricey', 'day', '2026-05-29', [ 'est_cost_micros' => 9000 ] );
		Counter_Store::increment( 'plugin', 'mid', 'day', '2026-05-29', [ 'est_cost_micros' => 500 ] );

		$rows = Counter_Store::read( 'plugin', 'day', '2026-05-29' );
		$this->assertCount( 3, $rows );
		$this->assertSame( 'pricey', $rows[0]['scope_key'] );
		$this->assertSame( 'mid', $rows[1]['scope_key'] );
		$this->assertSame( 'cheap', $rows[2]['scope_key'] );
	}

	/**
	 * read_one() returns null when nothing has been recorded.
	 */
	public function test_read_one_absent_is_null() {
		$this->assertNull( Counter_Store::read_one( 'plugin', 'ghost', 'day', '2026-05-29' ) );
	}
}
