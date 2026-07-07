<?php
/**
 * Tests for the Gatekeeper's pending-intent correlation registry.
 *
 * Focuses on the recency-based intent→result matching and its hardening:
 * blocked prompts must discard their intent (no result will arrive), and stale
 * intents must age out of the match window so they cannot steal a later,
 * unrelated result.
 *
 * @package WP_AIUT
 */

use WP_AIUT\Capture\Gatekeeper;
use WP_AIUT\Attribution\Caller_Resolver;
use WP_AIUT\Enforcement\Enforcer;

/**
 * Resolver stub: deterministic attribution, no hooks, no backtrace.
 */
class AIUT_Stub_Resolver extends Caller_Resolver {

	/**
	 * Slug this stub attributes every call to.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * @param string $slug Slug to report from resolve().
	 */
	public function __construct( $slug = 'acme' ) {
		$this->slug = $slug;
	}

	/**
	 * No-op: the real register() wires self-ID hooks we don't want in a unit test.
	 */
	public function register() {}

	/**
	 * @return array<string,string> Fixed caller attribution.
	 */
	public function resolve() {
		return [
			'source_slug' => $this->slug,
			'source_type' => 'plugin',
			'confidence'  => 'high',
		];
	}

	/**
	 * @return array<string,mixed> Fixed user attribution.
	 */
	public function resolve_user() {
		return [
			'user_id'   => 1,
			'user_role' => 'administrator',
		];
	}
}

/**
 * Enforcer stub returning a fixed block decision without touching the DB.
 */
class AIUT_Stub_Enforcer extends Enforcer {

	/**
	 * Whether should_block() returns true.
	 *
	 * @var bool
	 */
	private $block;

	/**
	 * @param bool $block Fixed decision.
	 */
	public function __construct( $block ) {
		$this->block = (bool) $block;
	}

	/**
	 * @param array<string,string> $scopes     Unused.
	 * @param string               $confidence Unused.
	 * @return bool The fixed decision.
	 */
	public function should_block( array $scopes, $confidence ) {
		return $this->block;
	}
}

/**
 * @group capture
 */
class Gatekeeper_Test extends AIUT_TestCase {

	public function set_up() {
		parent::set_up();
		Gatekeeper::reset();
	}

	public function tear_down() {
		Gatekeeper::reset();
		parent::tear_down();
	}

	/**
	 * A gatekeeper wired with stub collaborators (no enforcer unless supplied).
	 *
	 * @param Enforcer|null $enforcer Optional enforcer stub.
	 * @return Gatekeeper
	 */
	private function make_gatekeeper( $enforcer = null ) {
		// Pass a resolver + a null result-capturer sentinel is not possible (it
		// self-constructs), but the capturer is inert unless its hooks fire, so
		// constructing it is harmless here.
		return new Gatekeeper( new AIUT_Stub_Resolver(), null, $enforcer );
	}

	/**
	 * Observing a prompt records a pending intent that match_pending() returns.
	 */
	public function test_observe_records_matchable_intent() {
		$gk = $this->make_gatekeeper();

		$gk->observe_prompt( false, new stdClass() );

		$match = $gk->match_pending( null );

		$this->assertNotNull( $match );
		$this->assertSame( 'acme', $match['source_slug'] );
		$this->assertFalse( (bool) $match['finalized'] );
	}

	/**
	 * The newest un-finalised intent wins when several are pending.
	 */
	public function test_match_prefers_most_recent() {
		$gk = $this->make_gatekeeper();

		$gk->observe_prompt( false, new stdClass() );
		$first = $gk->match_pending( null );
		$gk->mark_finalized( $first['fingerprint'] );

		$gk->observe_prompt( false, new stdClass() );

		$match = $gk->match_pending( null );

		$this->assertNotNull( $match );
		$this->assertNotSame( $first['fingerprint'], $match['fingerprint'] );
	}

	/**
	 * When the Enforcer blocks, the intent is discarded so a later, unrelated
	 * result cannot be mis-paired to the blocked call.
	 */
	public function test_blocked_prompt_discards_its_intent() {
		$gk = $this->make_gatekeeper( new AIUT_Stub_Enforcer( true ) );

		$blocked = $gk->observe_prompt( false, new stdClass() );

		// The prevent filter returns true (blocked).
		$this->assertTrue( $blocked );

		// No un-finalised intent should remain to steal a subsequent result.
		$this->assertNull( $gk->match_pending( null ) );
	}

	/**
	 * A prompt already blocked by a prior filter ($prevent = true) also discards
	 * its intent — that request will not run either.
	 */
	public function test_prior_block_discards_its_intent() {
		$gk = $this->make_gatekeeper();

		$result = $gk->observe_prompt( true, new stdClass() );

		$this->assertTrue( $result );
		$this->assertNull( $gk->match_pending( null ) );
	}

	/**
	 * An intent older than the correlation window is not matched — it is left for
	 * the shutdown estimate sweep rather than stealing a later real result.
	 */
	public function test_stale_intent_ages_out_of_match_window() {
		$gk = $this->make_gatekeeper();

		// Record an intent, then backdate it well past the max-age window by
		// filtering the window down to effectively zero for this assertion.
		$gk->observe_prompt( false, new stdClass() );

		add_filter(
			'wp_aiut_match_max_age',
			static function () {
				return 0.0001; // ~0.1 ms: the just-recorded intent is already older.
			}
		);

		// Give the intent a moment to exceed even the tiny window.
		usleep( 2000 );

		$match = $gk->match_pending( null );

		$this->assertNull( $match, 'Aged-out intent must not be matched.' );

		remove_all_filters( 'wp_aiut_match_max_age' );

		// With the default window restored, the same intent is matchable again
		// (it was never finalised — just temporarily outside the window).
		$this->assertNotNull( $gk->match_pending( null ) );
	}

	/**
	 * A blocked intent stays discarded even within the match window.
	 */
	public function test_discarded_intent_not_revived_by_window() {
		$gk = $this->make_gatekeeper( new AIUT_Stub_Enforcer( true ) );

		$gk->observe_prompt( false, new stdClass() );

		// Even immediately (well within the window) there is nothing to match.
		$this->assertNull( $gk->match_pending( null ) );
	}
}
