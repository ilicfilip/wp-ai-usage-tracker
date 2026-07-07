<?php
/**
 * Cost calculator: tokens to estimated cost in integer micros.
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Accounting;

defined( 'ABSPATH' ) || exit;

/**
 * Converts token counts into an estimated cost expressed in integer micros
 * (1e-6 USD), avoiding float drift in the database (spec §5).
 *
 * Pricing is a table of per-1,000,000-token prices (in USD) keyed by
 * "provider/model". It ships with sane 2026 placeholder defaults, is persisted
 * in the 'aiut_pricing' option (overridable by the admin), and is filterable
 * via 'wp_aiut_pricing'. Never hardcode prices on the hot path —
 * providers change them.
 */
class Cost_Calculator {

	/**
	 * Option key holding the admin-editable pricing table.
	 */
	const PRICING_OPTION = 'aiut_pricing';

	/**
	 * Filter applied to the resolved pricing table before use.
	 */
	const PRICING_FILTER = 'wp_aiut_pricing';

	/**
	 * Default pricing table: per 1,000,000 tokens, in USD.
	 *
	 * Keyed by "provider/model". Each entry carries 'input', 'output' and an
	 * optional 'thinking' price (defaults to the output price when absent).
	 *
	 * IMPORTANT: these are ESTIMATES, not verified live prices. Provider pricing
	 * changes over time and varies by tier/region; the dashboard labels figures
	 * as "estimated" for this reason. The admin can override any row in Settings
	 * (stored in the 'aiut_pricing' option) or via the 'wp_aiut_pricing'
	 * filter. Model keys use the base family slug (e.g. "claude-opus-4"); the
	 * lookup in rates_for() prefix-matches versioned slugs ("claude-opus-4-8") to
	 * the closest family entry, so new minor versions resolve without edits.
	 *
	 * @return array<string, array<string, float>>
	 */
	public static function default_pricing() {
		return [
			// Anthropic. Base-family keys; prefix-matching covers minor versions
			// such as "claude-opus-4-8".
			'anthropic/claude-opus-4'   => [
				'input'    => 15.0,
				'output'   => 75.0,
				'thinking' => 75.0,
			],
			'anthropic/claude-sonnet-4' => [
				'input'    => 3.0,
				'output'   => 15.0,
				'thinking' => 15.0,
			],
			'anthropic/claude-haiku-4'  => [
				'input'    => 0.80,
				'output'   => 4.0,
				'thinking' => 4.0,
			],
			// OpenAI.
			'openai/gpt-5'              => [
				'input'    => 10.0,
				'output'   => 30.0,
				'thinking' => 30.0,
			],
			'openai/gpt-5-mini'         => [
				'input'    => 0.50,
				'output'   => 2.0,
				'thinking' => 2.0,
			],
			'openai/gpt-4o'             => [
				'input'    => 2.50,
				'output'   => 10.0,
				'thinking' => 10.0,
			],
			// Google.
			'google/gemini-2.5-pro'     => [
				'input'    => 1.25,
				'output'   => 10.0,
				'thinking' => 10.0,
			],
			'google/gemini-2.5-flash'   => [
				'input'    => 0.30,
				'output'   => 2.50,
				'thinking' => 2.50,
			],
			// Generic fallback used when a specific model is unknown.
			'__default__/__default__'   => [
				'input'    => 3.0,
				'output'   => 15.0,
				'thinking' => 15.0,
			],
		];
	}

	/**
	 * Resolve the active pricing table.
	 *
	 * Merges shipped defaults with any admin overrides stored in the option, then
	 * applies the 'wp_aiut_pricing' filter. Defaults guarantee the
	 * generic '__default__/__default__' fallback always exists.
	 *
	 * @return array<string, array<string, float>>
	 */
	public static function get_pricing() {
		$defaults = self::default_pricing();
		$stored   = get_option( self::PRICING_OPTION, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$pricing = array_merge( $defaults, self::sanitize_pricing( $stored ) );

		/**
		 * Filter the active pricing table (per 1,000,000 tokens, USD).
		 *
		 * @param array<string, array<string, float>> $pricing Keyed by "provider/model".
		 */
		$pricing = apply_filters( self::PRICING_FILTER, $pricing );

		if ( ! is_array( $pricing ) || empty( $pricing ) ) {
			$pricing = $defaults;
		}

		return $pricing;
	}

	/**
	 * Persist an admin-supplied pricing table to the option.
	 *
	 * Values are sanitized to non-negative floats. Only the stored override is
	 * written; defaults are always re-merged at read time by get_pricing().
	 *
	 * @param array<string, array<string, mixed>> $pricing Pricing table to store.
	 * @return array<string, array<string, float>> The sanitized table that was stored.
	 */
	public static function update_pricing( $pricing ) {
		$clean = self::sanitize_pricing( $pricing );

		update_option( self::PRICING_OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Compute the estimated cost in integer micros for a usage event.
	 *
	 * Cost (USD) = tokens / 1e6 * price_per_million. Micros = cost * 1e6, so the
	 * per-token-bucket micro cost is simply tokens * price_per_million. The three
	 * buckets are summed and rounded to the nearest integer micro.
	 *
	 * @param string $provider        Provider slug (e.g. 'anthropic').
	 * @param string $model           Model slug (e.g. 'claude-sonnet-4').
	 * @param int    $input_tokens    Prompt/input tokens.
	 * @param int    $output_tokens   Completion/output tokens.
	 * @param int    $thinking_tokens Reasoning/thinking tokens (optional).
	 * @return int Estimated cost in micros (1e-6 USD), never negative.
	 */
	public static function cost_micros( $provider, $model, $input_tokens, $output_tokens, $thinking_tokens = 0 ) {
		$rates = self::rates_for( $provider, $model );

		$input_tokens    = max( 0, (int) $input_tokens );
		$output_tokens   = max( 0, (int) $output_tokens );
		$thinking_tokens = max( 0, (int) $thinking_tokens );

		$input_price    = isset( $rates['input'] ) ? (float) $rates['input'] : 0.0;
		$output_price   = isset( $rates['output'] ) ? (float) $rates['output'] : 0.0;
		$thinking_price = isset( $rates['thinking'] ) ? (float) $rates['thinking'] : $output_price;

		// price is USD per 1e6 tokens; micros = USD * 1e6 => tokens * price.
		$micros = ( $input_tokens * $input_price )
			+ ( $output_tokens * $output_price )
			+ ( $thinking_tokens * $thinking_price );

		return max( 0, (int) round( $micros ) );
	}

	/**
	 * Resolve the rate row for a provider/model, falling back gracefully.
	 *
	 * Lookup order:
	 *   1. Exact "provider/model".
	 *   2. Longest-prefix match within the same provider — so a versioned model
	 *      slug like "claude-opus-4-8" matches a "claude-opus-4" pricing entry,
	 *      and future minor versions keep resolving without table edits.
	 *   3. "provider/__default__".
	 *   4. "__default__/__default__".
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model slug.
	 * @return array<string, float>
	 */
	private static function rates_for( $provider, $model ) {
		$pricing  = self::get_pricing();
		$provider = (string) $provider;
		$model    = (string) $model;

		// 1. Exact match.
		$exact = $provider . '/' . $model;
		if ( isset( $pricing[ $exact ] ) ) {
			return $pricing[ $exact ];
		}

		// 2. Longest-prefix match within the provider (handles version suffixes).
		$best_key = '';
		$prefix   = $provider . '/';
		foreach ( $pricing as $key => $row ) {
			if ( 0 !== strpos( $key, $prefix ) ) {
				continue;
			}

			$key_model = substr( $key, strlen( $prefix ) );
			if ( '__default__' === $key_model || '' === $key_model ) {
				continue;
			}

			// The configured model must be a prefix of the real one
			// (e.g. "claude-opus-4" is a prefix of "claude-opus-4-8").
			if ( 0 === strpos( $model, $key_model ) && strlen( $key_model ) > strlen( $best_key ) ) {
				$best_key = $key_model;
			}
		}

		if ( '' !== $best_key && isset( $pricing[ $prefix . $best_key ] ) ) {
			return $pricing[ $prefix . $best_key ];
		}

		// 3. Provider-level default.
		$provider_default = $provider . '/__default__';
		if ( isset( $pricing[ $provider_default ] ) ) {
			return $pricing[ $provider_default ];
		}

		// 4. Global default.
		if ( isset( $pricing['__default__/__default__'] ) ) {
			return $pricing['__default__/__default__'];
		}

		return [
			'input'    => 0.0,
			'output'   => 0.0,
			'thinking' => 0.0,
		];
	}

	/**
	 * Sanitize a pricing table into the canonical shape of non-negative floats.
	 *
	 * Keys are sanitized to a "provider/model" text shape; each row keeps only
	 * the input/output/thinking float prices.
	 *
	 * @param array<string, mixed> $pricing Raw pricing table.
	 * @return array<string, array<string, float>>
	 */
	private static function sanitize_pricing( $pricing ) {
		$clean = [];

		if ( ! is_array( $pricing ) ) {
			return $clean;
		}

		foreach ( $pricing as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$entry = [
				'input'  => isset( $row['input'] ) ? max( 0.0, (float) $row['input'] ) : 0.0,
				'output' => isset( $row['output'] ) ? max( 0.0, (float) $row['output'] ) : 0.0,
			];

			if ( isset( $row['thinking'] ) ) {
				$entry['thinking'] = max( 0.0, (float) $row['thinking'] );
			}

			$clean[ $key ] = $entry;
		}

		return $clean;
	}
}
