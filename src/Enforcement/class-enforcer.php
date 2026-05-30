<?php
/**
 * Enforcement decision for the pre-request prevent filter (Phase 2).
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Enforcement;

use WP_AI_Rate_Limiter\Limits\Limit_Repository;
use WP_AI_Rate_Limiter\Limits\Limit_Evaluator;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a prompt should be blocked because a hard limit is breached.
 *
 * Called by the Gatekeeper on 'wp_ai_client_prevent_prompt'. The decision is
 * retrospective (spec §3): it blocks the *next* request once accumulated usage
 * has already met a hard cap. Enforcement is confidence-gated (spec §4) so a
 * low-confidence attribution is never used to single out a plugin.
 *
 * Fails open: any error, or no enabled hard limits, => allow. This guarantees
 * a misconfiguration or bug can never take down a site's AI features.
 */
class Enforcer {

	/**
	 * Limit repository (provides the cached fast-path flag + scope lookups).
	 *
	 * @var Limit_Repository
	 */
	private $limits;

	/**
	 * Limit evaluator.
	 *
	 * @var Limit_Evaluator
	 */
	private $evaluator;

	/**
	 * Constructor.
	 *
	 * @param Limit_Repository|null $limits    Optional repository (created if null).
	 * @param Limit_Evaluator|null  $evaluator Optional evaluator (created if null).
	 */
	public function __construct( $limits = null, $evaluator = null ) {
		$this->limits = $limits instanceof Limit_Repository ? $limits : new Limit_Repository();

		if ( $evaluator instanceof Limit_Evaluator ) {
			$this->evaluator = $evaluator;
		} else {
			$this->evaluator = new Limit_Evaluator( $this->limits );
		}
	}

	/**
	 * Whether a prompt with these scopes/confidence should be blocked.
	 *
	 * @param array<string,string> $scopes     scope_type => scope_key for the request.
	 * @param string               $confidence Attribution confidence.
	 * @return bool True to block (return true from the prevent filter).
	 */
	public function should_block( array $scopes, $confidence ) {
		try {
			// Fast path: nothing to enforce => behave exactly like observe-only.
			if ( ! $this->limits->has_enabled_hard_limits() ) {
				return false;
			}

			$breach = $this->evaluator->first_hard_breach( $scopes, $confidence );

			if ( null === $breach ) {
				return false;
			}

			/**
			 * Fires when a prompt is blocked by a hard limit.
			 *
			 * Lets sites log the block or surface a friendlier message than core's
			 * generic "prevented by a filter" error.
			 *
			 * @param array<string,mixed>  $breach The breached limit row, incl. 'current' usage.
			 * @param array<string,string> $scopes The request's scope set.
			 * @param string               $confidence Attribution confidence.
			 */
			do_action( 'wp_ai_rate_limiter_blocked', $breach, $scopes, $confidence );

			return true;
		} catch ( \Throwable $e ) {
			// Fail open: enforcement must never break the host request.
			unset( $e );
			return false;
		}
	}
}
