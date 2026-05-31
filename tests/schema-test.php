<?php
/**
 * Tests for the Schema installer.
 *
 * @package WP_AI_Rate_Limiter
 */

use WP_AI_Rate_Limiter\Data\Schema;

/**
 * @covers \WP_AI_Rate_Limiter\Data\Schema
 */
class Schema_Test extends AIUT_TestCase {

	/**
	 * Assert a table physically exists.
	 *
	 * @param string $table Fully qualified table name.
	 */
	private function assert_table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found, "Expected table {$table} to exist." );
	}

	/**
	 * All three tables are created by install().
	 */
	public function test_install_creates_all_tables() {
		$this->assert_table_exists( Schema::events_table() );
		$this->assert_table_exists( Schema::counters_table() );
		$this->assert_table_exists( Schema::limits_table() );
	}

	/**
	 * The schema version option is recorded.
	 */
	public function test_install_records_db_version() {
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::DB_VERSION_OPTION ) );
		$this->assertSame( '2', Schema::DB_VERSION );
	}

	/**
	 * Table helpers append "aiut_" to the WP prefix (no double underscore in the
	 * suffix itself; the single trailing prefix underscore is expected).
	 */
	public function test_table_names_use_prefix_helper() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'aiut_events', Schema::events_table() );
		$this->assertSame( $wpdb->prefix . 'aiut_counters', Schema::counters_table() );
		$this->assertSame( $wpdb->prefix . 'aiut_limits', Schema::limits_table() );
	}

	/**
	 * install() is idempotent — calling it twice does not error or duplicate.
	 */
	public function test_install_is_idempotent() {
		Schema::install();
		Schema::install();
		$this->assert_table_exists( Schema::counters_table() );
	}

	/**
	 * The counters table enforces the UNIQUE scope/period key (atomic upsert key).
	 */
	public function test_counters_unique_key_present() {
		global $wpdb;
		$table = Schema::counters_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'scope_period'", ARRAY_A );
		$this->assertNotEmpty( $indexes, 'Expected a UNIQUE scope_period index on the counters table.' );
		$this->assertSame( '0', $indexes[0]['Non_unique'], 'scope_period index must be UNIQUE.' );
	}
}
