<?php
/**
 * Capture gatekeeper — hooks the pre-request prevent filter (observe-only).
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Capture;

use WP_AIUT\Attribution\Caller_Resolver;
use WP_AIUT\Enforcement\Enforcer;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks 'wp_ai_client_prevent_prompt' to observe — and, in Phase 2, optionally
 * enforce — every prompt.
 *
 * On each prompt we resolve the caller + user, build a fingerprint, and record
 * a "pending intent" in a request-scoped registry. The Result_Capturer later
 * matches a completed request's real token usage back to one of these pending
 * intents and finalises it through the Usage_Recorder.
 *
 * ENFORCEMENT: after recording the intent, we ask the Enforcer whether a hard
 * limit is already breached for this request's scopes. If so we return true to
 * block (core then returns a graceful WP_Error). When no hard limits are
 * configured the Enforcer short-circuits and behaviour is identical to the
 * original observe-only design. The Enforcer fails open on any error.
 */
class Gatekeeper {

	/**
	 * Caller/user attribution resolver.
	 *
	 * @var Caller_Resolver
	 */
	private $resolver;

	/**
	 * Result capturer that finalises pending intents.
	 *
	 * @var Result_Capturer|null
	 */
	private $result_capturer = null;

	/**
	 * Enforcer that decides whether to block a prompt (Phase 2).
	 *
	 * @var Enforcer|null
	 */
	private $enforcer = null;

	/**
	 * Request-scoped registry of pending intents, keyed by fingerprint.
	 *
	 * Shared across instances within a request because capture spans the
	 * pre-request filter and the post-request result hooks.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $pending = [];

	/**
	 * Monotonic counter making each fingerprint unique within a request.
	 *
	 * @var int
	 */
	private static $sequence = 0;

	/**
	 * Maximum age, in seconds, of a pending intent still eligible to be matched
	 * to an incoming result.
	 *
	 * The intent→result correlation is by recency (the result event does not
	 * echo the builder identity), so a stale intent that never received its
	 * result — e.g. a prompt that errored or was blocked after the intent was
	 * recorded — must not linger as the "newest un-finalised" match forever and
	 * mis-pair the *next* real result. Any intent older than this is ignored by
	 * match_pending() and swept by the estimate pass instead. Generous relative
	 * to a typical multi-second AI request; filterable for slow providers.
	 *
	 * @var float
	 */
	const MATCH_MAX_AGE_SECONDS = 300.0;

	/**
	 * Constructor: wire collaborators, guarding optional ones.
	 *
	 * @param Caller_Resolver|null $resolver        Optional resolver (created if null).
	 * @param Result_Capturer|null $result_capturer Optional result capturer.
	 * @param Enforcer|null        $enforcer        Optional enforcer (created if null).
	 */
	public function __construct( $resolver = null, $result_capturer = null, $enforcer = null ) {
		if ( $resolver instanceof Caller_Resolver ) {
			$this->resolver = $resolver;
		} else {
			$this->resolver = new Caller_Resolver();
		}

		if ( $result_capturer instanceof Result_Capturer ) {
			$this->result_capturer = $result_capturer;
		} elseif ( class_exists( '\\WP_AIUT\\Capture\\Result_Capturer' ) ) {
			$this->result_capturer = new Result_Capturer( $this );
		}

		if ( $enforcer instanceof Enforcer ) {
			$this->enforcer = $enforcer;
		} elseif ( class_exists( '\\WP_AIUT\\Enforcement\\Enforcer' ) ) {
			$this->enforcer = new Enforcer();
		}
	}

	/**
	 * Register hooks: the prevent filter, the self-ID listener, and result paths.
	 *
	 * @return void
	 */
	public function register() {
		// Listen for cooperating plugins' self-identification.
		$this->resolver->register();

		// Observe every prompt pre-request. Priority 10, 2 args (verified API).
		add_filter( 'wp_ai_client_prevent_prompt', [ $this, 'observe_prompt' ], 10, 2 );

		// Wire the result-capture paths (A: AI-plugin hook, B: transporter, C: estimate).
		if ( $this->result_capturer instanceof Result_Capturer ) {
			$this->result_capturer->register();
		}
	}

	/**
	 * Callback for 'wp_ai_client_prevent_prompt'.
	 *
	 * Resolves attribution, records a pending intent, then asks the Enforcer
	 * whether a hard limit is already breached. Returns true to block when it is;
	 * otherwise returns $prevent unchanged. The bookkeeping and the enforcement
	 * decision are each wrapped so a bug fails open and never breaks the request
	 * (spec §3 / Architecture §1).
	 *
	 * @param bool  $prevent Whether a prior filter already wants to block.
	 * @param mixed $builder WP_AI_Client_Prompt_Builder (typed loosely on purpose).
	 * @return bool True to block, otherwise the incoming $prevent.
	 */
	public function observe_prompt( $prevent, $builder = null ) {
		$caller      = null;
		$user        = null;
		$fingerprint = null;

		try {
			$caller = $this->resolver->resolve();
			$user   = $this->resolver->resolve_user();

			$fingerprint = $this->build_fingerprint( $caller['source_slug'], $builder );

			self::$pending[ $fingerprint ] = [
				'fingerprint'  => $fingerprint,
				'source_slug'  => $caller['source_slug'],
				'source_type'  => $caller['source_type'],
				'confidence'   => $caller['confidence'],
				'user_id'      => $user['user_id'],
				'user_role'    => $user['user_role'],
				'prompt_chars' => $this->estimate_prompt_chars( $builder ),
				'created_at'   => microtime( true ),
				'finalized'    => false,
			];
		} catch ( \Throwable $e ) {
			// Never let observation interfere with the request.
			unset( $e );
		}

		// If a prior filter already blocks, or we couldn't resolve, respect that.
		// A prior block means no request runs, so no result will arrive for this
		// intent — discard it so it cannot mis-pair with a later real result.
		if ( $prevent || null === $caller || null === $user ) {
			if ( $prevent && null !== $fingerprint ) {
				$this->mark_finalized( $fingerprint );
			}

			return $prevent;
		}

		// Enforcement (Phase 2). The Enforcer short-circuits when no hard limits
		// are configured, so this is a no-op for observe-only installs.
		if ( $this->enforcer instanceof Enforcer ) {
			$scopes = $this->build_scopes( $caller, $user );

			if ( $this->enforcer->should_block( $scopes, $caller['confidence'] ) ) {
				// We are blocking: the AI call will not run, so no result event
				// will ever finalise this intent. Discard it now so it does not
				// remain the "newest un-finalised" intent and steal the next
				// genuine result (which would corrupt attribution/accounting).
				if ( null !== $fingerprint ) {
					$this->mark_finalized( $fingerprint );
				}

				return true;
			}
		}

		return $prevent;
	}

	/**
	 * Build the scope_type => scope_key map for an enforcement decision.
	 *
	 * Mirrors the scope keys written by the Usage_Recorder so limits match the
	 * same buckets usage is counted into. The model scope is omitted here (the
	 * model is not known pre-request); model limits are still tracked and can be
	 * enforced retrospectively once usage accrues under '*'.
	 *
	 * @param array<string,string> $caller Resolved caller (source_slug).
	 * @param array<string,mixed>  $user   Resolved user (user_id, user_role).
	 * @return array<string,string> scope_type => scope_key.
	 */
	private function build_scopes( array $caller, array $user ) {
		return [
			'plugin' => (string) $caller['source_slug'],
			'user'   => (string) $user['user_id'],
			'role'   => (string) $user['user_role'],
			'global' => '__all__',
		];
	}

	/**
	 * Build a fingerprint for a pending intent.
	 *
	 * Combines the caller slug, a stable hash of the builder (serialized when
	 * possible, else spl_object_hash), and a monotonic per-request counter so
	 * even identical prompts from the same caller get distinct keys.
	 *
	 * @param string $slug    Resolved caller slug.
	 * @param mixed  $builder The prompt builder, or null.
	 * @return string Fingerprint string.
	 */
	private function build_fingerprint( $slug, $builder ) {
		$builder_hash = $this->hash_builder( $builder );

		++self::$sequence;

		return $slug . ':' . $builder_hash . ':' . self::$sequence;
	}

	/**
	 * Derive a stable hash for the builder object.
	 *
	 * Prefers a serialized representation (stable across calls with identical
	 * content) and falls back to spl_object_hash for unserializable builders.
	 *
	 * @param mixed $builder The prompt builder, or null.
	 * @return string 32-char md5 hash.
	 */
	private function hash_builder( $builder ) {
		if ( ! is_object( $builder ) ) {
			return md5( (string) wp_json_encode( $builder ) );
		}

		try {
			$serialized = serialize( $builder ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashed only, never unserialized.
			return md5( $serialized );
		} catch ( \Throwable $e ) {
			unset( $e );
			return md5( spl_object_hash( $builder ) );
		}
	}

	/**
	 * Best-effort character count of the builder's prompt for estimation.
	 *
	 * Used by the Result_Capturer's path C fallback when no real tokens arrive.
	 * Returns 0 when nothing legible can be extracted.
	 *
	 * @param mixed $builder The prompt builder, or null.
	 * @return int Character count.
	 */
	private function estimate_prompt_chars( $builder ) {
		if ( ! is_object( $builder ) ) {
			return 0;
		}

		// The builder is opaque; a serialized form is a reasonable proxy for
		// "how much prompt content is in flight" for a chars/4 heuristic.
		try {
			$serialized = serialize( $builder ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- length proxy only.
			return strlen( $serialized );
		} catch ( \Throwable $e ) {
			unset( $e );
			return 0;
		}
	}

	/**
	 * Access the full pending-intent registry (for the Result_Capturer).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function pending_intents() {
		return self::$pending;
	}

	/**
	 * Find the most recent un-finalised pending intent, optionally by slug.
	 *
	 * When the result path can identify a caller (e.g. via AI-plugin context)
	 * it passes the slug to narrow the match; otherwise the newest pending
	 * intent overall is returned (Architecture §3 fallback correlation).
	 *
	 * Intents older than the max-age window are ignored: because matching is by
	 * recency (the result event carries no builder identity), a stale intent that
	 * never received its result — an errored or otherwise abandoned call — must
	 * not survive to steal a later, unrelated result. Aged-out intents are left
	 * for the shutdown estimate sweep instead.
	 *
	 * @param string|null $slug Optional caller slug to prefer.
	 * @return array<string,mixed>|null The matched intent, or null.
	 */
	public function match_pending( $slug = null ) {
		$best     = null;
		$best_key = null;
		$min_age  = microtime( true ) - $this->match_max_age();

		foreach ( self::$pending as $key => $intent ) {
			if ( ! empty( $intent['finalized'] ) ) {
				continue;
			}

			if ( null !== $slug && $intent['source_slug'] !== $slug ) {
				continue;
			}

			// Skip intents that have aged out of the correlation window.
			if ( isset( $intent['created_at'] ) && $intent['created_at'] < $min_age ) {
				continue;
			}

			if ( null === $best || $intent['created_at'] >= $best['created_at'] ) {
				$best     = $intent;
				$best_key = $key;
			}
		}

		if ( null === $best ) {
			return null;
		}

		$best['_key'] = $best_key;
		return $best;
	}

	/**
	 * The intent→result correlation window, in seconds (filterable).
	 *
	 * @return float Maximum age of a matchable pending intent.
	 */
	private function match_max_age() {
		/**
		 * Filter the maximum age (seconds) of a pending intent still eligible to
		 * be matched to an incoming AI result. Raise it for unusually slow
		 * providers; lower it to tighten correlation on busy sites.
		 *
		 * @param float $seconds Default self::MATCH_MAX_AGE_SECONDS.
		 */
		$age = (float) apply_filters( 'wp_aiut_match_max_age', self::MATCH_MAX_AGE_SECONDS );

		return $age > 0 ? $age : self::MATCH_MAX_AGE_SECONDS;
	}

	/**
	 * Enrich the newest un-finalised pending intent with exact connector
	 * attribution discovered at the HTTP layer.
	 *
	 * The Http_Guard fires on 'pre_http_request' for the very request whose
	 * intent the prevent-prompt hook just recorded, and it knows — from a
	 * credential match in the outbound request — the exact connector and (via the
	 * backtrace) the caller. We upgrade that intent's attribution to 'exact'
	 * confidence and stamp the connector_id so the Result_Capturer persists the
	 * better attribution.
	 *
	 * Safe no-op when no un-finalised intent exists: a caller that bypasses
	 * wp_ai_client_prompt() and hits the provider API directly has no recorded
	 * intent — that path is enforcement-only, never tracked through intents.
	 *
	 * @param string              $connector_id Connector the request is using.
	 * @param array<string,mixed> $caller       Resolved caller
	 *                                           (source_slug, source_type[, confidence]).
	 * @return void
	 */
	public function enrich_latest_intent( $connector_id, array $caller ) {
		try {
			$best_key = null;
			$best     = null;
			$min_age  = microtime( true ) - $this->match_max_age();

			foreach ( self::$pending as $key => $intent ) {
				if ( ! empty( $intent['finalized'] ) ) {
					continue;
				}

				if ( isset( $intent['created_at'] ) && $intent['created_at'] < $min_age ) {
					continue;
				}

				if ( null === $best || $intent['created_at'] >= $best['created_at'] ) {
					$best     = $intent;
					$best_key = $key;
				}
			}

			if ( null === $best_key ) {
				return;
			}

			self::$pending[ $best_key ]['connector_id'] = (string) $connector_id;
			self::$pending[ $best_key ]['confidence']   = Caller_Resolver::CONFIDENCE_EXACT;

			// The credential match plus the HTTP-layer backtrace give a more
			// reliable caller than the pre-request resolution; adopt it when present.
			if ( isset( $caller['source_slug'] ) && '' !== (string) $caller['source_slug'] ) {
				self::$pending[ $best_key ]['source_slug'] = (string) $caller['source_slug'];
			}

			if ( isset( $caller['source_type'] ) && '' !== (string) $caller['source_type'] ) {
				self::$pending[ $best_key ]['source_type'] = (string) $caller['source_type'];
			}
		} catch ( \Throwable $e ) {
			// Enrichment is best-effort; never let it disturb the request.
			unset( $e );
		}
	}

	/**
	 * Mark a pending intent finalised so it is not matched again.
	 *
	 * @param string $fingerprint The intent's fingerprint key.
	 * @return void
	 */
	public function mark_finalized( $fingerprint ) {
		if ( isset( self::$pending[ $fingerprint ] ) ) {
			self::$pending[ $fingerprint ]['finalized'] = true;
		}
	}

	/**
	 * Reset request-scoped state. Intended for test isolation.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$pending  = [];
		self::$sequence = 0;
	}
}
