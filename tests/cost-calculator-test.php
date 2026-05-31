<?php
/**
 * Tests for the Cost_Calculator.
 *
 * @package WP_AI_Rate_Limiter
 */

use WP_AI_Rate_Limiter\Accounting\Cost_Calculator;

/**
 * @covers \WP_AI_Rate_Limiter\Accounting\Cost_Calculator
 */
class Cost_Calculator_Test extends AIUT_TestCase {

	/**
	 * Exact provider/model: micros = tokens * price-per-million.
	 */
	public function test_exact_match_cost_micros() {
		// anthropic/claude-sonnet-4: input 3.0, output 15.0 per 1e6 tokens.
		// 1000 input * 3.0 + 500 output * 15.0 = 3000 + 7500 = 10500 micros.
		$micros = Cost_Calculator::cost_micros( 'anthropic', 'claude-sonnet-4', 1000, 500 );
		$this->assertSame( 10500, $micros );
	}

	/**
	 * Thinking tokens default to the output price when no explicit thinking row.
	 */
	public function test_thinking_tokens_use_thinking_price() {
		// claude-opus-4: input 15, output 75, thinking 75.
		// 100*15 + 0 + 10*75 = 1500 + 750 = 2250.
		$micros = Cost_Calculator::cost_micros( 'anthropic', 'claude-opus-4', 100, 0, 10 );
		$this->assertSame( 2250, $micros );
	}

	/**
	 * Versioned model slug prefix-matches the base family entry.
	 */
	public function test_versioned_model_prefix_matches_family() {
		// "claude-opus-4-8" should resolve to the "claude-opus-4" row (15/75).
		$exact   = Cost_Calculator::cost_micros( 'anthropic', 'claude-opus-4', 1000, 0 );
		$matched = Cost_Calculator::cost_micros( 'anthropic', 'claude-opus-4-8', 1000, 0 );
		$this->assertSame( 15000, $matched );
		$this->assertSame( $exact, $matched );
	}

	/**
	 * Unknown provider/model falls back to the global default row (3/15).
	 */
	public function test_unknown_falls_back_to_global_default() {
		$micros = Cost_Calculator::cost_micros( 'acme', 'nonexistent-model', 1000, 1000 );
		// __default__/__default__: 1000*3 + 1000*15 = 18000.
		$this->assertSame( 18000, $micros );
	}

	/**
	 * Negative token counts are clamped to zero (never negative cost).
	 */
	public function test_negative_tokens_clamped() {
		$micros = Cost_Calculator::cost_micros( 'anthropic', 'claude-opus-4', -5000, -5000, -5000 );
		$this->assertSame( 0, $micros );
	}

	/**
	 * Zero tokens => zero cost.
	 */
	public function test_zero_tokens() {
		$this->assertSame( 0, Cost_Calculator::cost_micros( 'anthropic', 'claude-opus-4', 0, 0, 0 ) );
	}

	/**
	 * An admin pricing override stored in the option is honoured.
	 */
	public function test_pricing_option_override() {
		Cost_Calculator::update_pricing(
			[
				'anthropic/claude-opus-4' => [
					'input'  => 1.0,
					'output' => 2.0,
				],
			]
		);

		// 1000*1 + 1000*2 = 3000.
		$micros = Cost_Calculator::cost_micros( 'anthropic', 'claude-opus-4', 1000, 1000 );
		$this->assertSame( 3000, $micros );
	}

	/**
	 * The pricing filter can override the resolved table.
	 */
	public function test_pricing_filter_override() {
		$cb = static function ( $pricing ) {
			$pricing['anthropic/claude-opus-4'] = [
				'input'  => 0.0,
				'output' => 0.0,
			];
			return $pricing;
		};

		add_filter( 'wp_ai_rate_limiter_pricing', $cb );
		$micros = Cost_Calculator::cost_micros( 'anthropic', 'claude-opus-4', 9999, 9999 );
		remove_filter( 'wp_ai_rate_limiter_pricing', $cb );

		$this->assertSame( 0, $micros );
	}

	/**
	 * Stored pricing is sanitised to non-negative floats.
	 */
	public function test_update_pricing_sanitizes_negatives() {
		$clean = Cost_Calculator::update_pricing(
			[
				'foo/bar' => [
					'input'  => -10.0,
					'output' => 5.0,
				],
			]
		);

		$this->assertSame( 0.0, $clean['foo/bar']['input'] );
		$this->assertSame( 5.0, $clean['foo/bar']['output'] );
	}
}
