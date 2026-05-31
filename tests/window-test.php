<?php
/**
 * Tests for the timezone-aware period Window helper.
 *
 * @package WP_AI_Rate_Limiter
 */

use WP_AI_Rate_Limiter\Periods\Window;

/**
 * @covers \WP_AI_Rate_Limiter\Periods\Window
 */
class Window_Test extends AIUT_TestCase {

	/**
	 * Force wp_timezone() to a specific zone for the duration of a test.
	 *
	 * Using a pre_option filter is robust against however the test environment
	 * seeds the stored timezone_string/gmt_offset options. The filter is removed
	 * automatically when the per-test transaction tears down, but we remove it
	 * explicitly too for clarity.
	 *
	 * @param string $tz Timezone identifier.
	 * @return callable The filter callback (pass to remove_filter()).
	 */
	private function force_timezone( $tz ) {
		$cb = static function () use ( $tz ) {
			return $tz;
		};
		add_filter( 'pre_option_timezone_string', $cb );
		return $cb;
	}

	/**
	 * Day key is Y-m-d, month key is Y-m, computed in the site timezone.
	 */
	public function test_period_keys_in_site_tz() {
		$cb = $this->force_timezone( 'Europe/Berlin' );

		// Guard: the environment really is reporting Berlin now.
		$this->assertSame( 'Europe/Berlin', wp_timezone()->getName() );

		// 2026-05-29 23:30 UTC == 2026-05-30 01:30 Berlin (CEST, +2).
		$ts = gmmktime( 23, 30, 0, 5, 29, 2026 );

		$this->assertSame( '2026-05-30', Window::period_key( Window::KIND_DAY, $ts ) );
		$this->assertSame( '2026-05', Window::period_key( Window::KIND_MONTH, $ts ) );

		remove_filter( 'pre_option_timezone_string', $cb );
	}

	/**
	 * The same instant lands in the previous day under a negative offset zone.
	 */
	public function test_period_key_negative_offset() {
		$cb = $this->force_timezone( 'America/New_York' );

		// 2026-05-29 02:00 UTC == 2026-05-28 22:00 New York (EDT, -4).
		$ts = gmmktime( 2, 0, 0, 5, 29, 2026 );
		$this->assertSame( '2026-05-28', Window::period_key( Window::KIND_DAY, $ts ) );

		remove_filter( 'pre_option_timezone_string', $cb );
	}

	/**
	 * Unknown kind defaults to the day format.
	 */
	public function test_unknown_kind_defaults_to_day() {
		$cb  = $this->force_timezone( 'UTC' );
		$ts  = gmmktime( 12, 0, 0, 1, 15, 2026 );
		$this->assertSame( '2026-01-15', Window::period_key( 'week', $ts ) );
		remove_filter( 'pre_option_timezone_string', $cb );
	}

	/**
	 * Day range is a half-open [from, to) interval one day wide.
	 */
	public function test_day_range_half_open() {
		$cb    = $this->force_timezone( 'UTC' );
		$range = Window::range( Window::KIND_DAY, '2026-05-29' );
		$this->assertIsArray( $range );
		$this->assertSame( '2026-05-29 00:00:00', $range['from']->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-05-30 00:00:00', $range['to']->format( 'Y-m-d H:i:s' ) );
		remove_filter( 'pre_option_timezone_string', $cb );
	}

	/**
	 * Month range spans the calendar month, ending at the next month's first day.
	 */
	public function test_month_range() {
		$cb    = $this->force_timezone( 'UTC' );
		$range = Window::range( Window::KIND_MONTH, '2026-02' );
		$this->assertIsArray( $range );
		$this->assertSame( '2026-02-01 00:00:00', $range['from']->format( 'Y-m-d H:i:s' ) );
		// 2026 is not a leap year => Feb has 28 days.
		$this->assertSame( '2026-03-01 00:00:00', $range['to']->format( 'Y-m-d H:i:s' ) );
		remove_filter( 'pre_option_timezone_string', $cb );
	}

	/**
	 * Malformed keys return null rather than a bogus range.
	 */
	public function test_malformed_keys_return_null() {
		$this->assertNull( Window::range( Window::KIND_DAY, '2026-5-1' ) );
		$this->assertNull( Window::range( Window::KIND_DAY, 'not-a-date' ) );
		$this->assertNull( Window::range( Window::KIND_MONTH, '2026' ) );
		$this->assertNull( Window::range( Window::KIND_MONTH, '2026-05-01' ) );
	}

	/**
	 * current_period_key delegates to period_key for "now".
	 */
	public function test_current_period_key_matches_format() {
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', Window::current_period_key( Window::KIND_DAY ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}$/', Window::current_period_key( Window::KIND_MONTH ) );
	}
}
