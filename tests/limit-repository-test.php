<?php
/**
 * Tests for the Limit_Repository CRUD + cached hard-limit fast-path.
 *
 * @package WP_AI_Rate_Limiter
 */

use WP_AI_Rate_Limiter\Limits\Limit_Repository;

/**
 * @covers \WP_AI_Rate_Limiter\Limits\Limit_Repository
 */
class Limit_Repository_Test extends AIUT_TestCase {

	/**
	 * Repository under test.
	 *
	 * @var Limit_Repository
	 */
	private $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = new Limit_Repository();
	}

	/**
	 * A base limit payload with overridable fields.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function limit( array $overrides = [] ) {
		return array_merge(
			[
				'scope_type'     => 'plugin',
				'scope_key'      => 'acme',
				'limit_type'     => 'cost',
				'period_kind'    => 'month',
				'threshold'      => 1000000,
				'enforcement'    => 'hard',
				'min_confidence' => 'medium',
				'enabled'        => 1,
			],
			$overrides
		);
	}

	/**
	 * save() inserts and returns the new id; find() round-trips it.
	 */
	public function test_save_insert_and_find() {
		$id = $this->repo->save( $this->limit() );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$row = $this->repo->find( $id );
		$this->assertSame( 'acme', $row['scope_key'] );
		$this->assertSame( 1000000, $row['threshold'] );
		$this->assertSame( 'hard', $row['enforcement'] );
		$this->assertSame( 1, $row['enabled'] );
	}

	/**
	 * save() with an id updates the existing row.
	 */
	public function test_save_update() {
		$id = $this->repo->save( $this->limit() );
		$this->repo->save( $this->limit( [ 'id' => $id, 'threshold' => 5 ] ) );

		$row = $this->repo->find( $id );
		$this->assertSame( 5, $row['threshold'] );
	}

	/**
	 * delete() removes the row.
	 */
	public function test_delete() {
		$id = $this->repo->save( $this->limit() );
		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );
	}

	/**
	 * Invalid enums are coerced to safe defaults on save.
	 */
	public function test_sanitize_coerces_invalid_enums() {
		$id  = $this->repo->save(
			$this->limit(
				[
					'scope_type'  => 'wat',
					'limit_type'  => 'bogus',
					'enforcement' => 'nope',
					'period_kind' => 'year',
				]
			)
		);
		$row = $this->repo->find( $id );

		$this->assertSame( 'global', $row['scope_type'] );
		$this->assertSame( 'cost', $row['limit_type'] );
		$this->assertSame( 'soft', $row['enforcement'] );
		$this->assertSame( 'month', $row['period_kind'] );
	}

	/**
	 * An empty scope_key becomes the '*' wildcard.
	 */
	public function test_empty_scope_key_becomes_wildcard() {
		$id  = $this->repo->save( $this->limit( [ 'scope_key' => '' ] ) );
		$row = $this->repo->find( $id );
		$this->assertSame( '*', $row['scope_key'] );
	}

	/**
	 * The cached hard-limit flag flips true when an enabled hard limit exists.
	 */
	public function test_hard_flag_true_after_hard_limit() {
		$this->assertFalse( $this->repo->has_enabled_hard_limits() );
		$this->repo->save( $this->limit() );
		$this->assertTrue( $this->repo->has_enabled_hard_limits() );
	}

	/**
	 * A soft-only or disabled limit does not set the hard flag.
	 */
	public function test_hard_flag_false_for_soft_or_disabled() {
		$this->repo->save( $this->limit( [ 'enforcement' => 'soft' ] ) );
		$this->assertFalse( $this->repo->has_enabled_hard_limits() );

		$this->repo->save( $this->limit( [ 'scope_key' => 'other', 'enabled' => 0 ] ) );
		$this->assertFalse( $this->repo->has_enabled_hard_limits() );
	}

	/**
	 * Deleting the last hard limit refreshes the flag back to false.
	 */
	public function test_hard_flag_refreshes_on_delete() {
		$id = $this->repo->save( $this->limit() );
		$this->assertTrue( $this->repo->has_enabled_hard_limits() );
		$this->repo->delete( $id );
		$this->assertFalse( $this->repo->has_enabled_hard_limits() );
	}

	/**
	 * enabled_for_scope returns the specific key + '*' wildcard, excluding
	 * disabled and enforcement=off rows.
	 */
	public function test_enabled_for_scope_returns_specific_and_wildcard() {
		$this->repo->save( $this->limit( [ 'scope_key' => 'acme' ] ) );
		$this->repo->save( $this->limit( [ 'scope_key' => '*', 'limit_type' => 'requests' ] ) );
		$this->repo->save( $this->limit( [ 'scope_key' => 'other', 'limit_type' => 'tokens' ] ) );
		$this->repo->save( $this->limit( [ 'scope_key' => 'acme', 'limit_type' => 'tokens', 'enforcement' => 'off' ] ) );
		$this->repo->save( $this->limit( [ 'scope_key' => 'acme', 'limit_type' => 'requests', 'enabled' => 0 ] ) );

		$rows = $this->repo->enabled_for_scope( 'plugin', 'acme' );
		$keys = wp_list_pluck( $rows, 'scope_key' );

		// acme (cost) + * (requests) only; 'other', off, and disabled excluded.
		$this->assertContains( 'acme', $keys );
		$this->assertContains( '*', $keys );
		$this->assertNotContains( 'other', $keys );
		$this->assertCount( 2, $rows );
	}
}
