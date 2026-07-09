<?php
/**
 * HTTP-layer guard — exact connector attribution + optional enforcement.
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Capture;

use WP_AIUT\Attribution\Caller_Resolver;
use WP_AIUT\Enforcement\Enforcer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks 'pre_http_request' to attribute (and optionally block) outbound requests
 * that carry an AI-connector credential.
 *
 * The WordPress 7.0 AI Client sends its provider calls through
 * wp_safe_remote_request(), so every AI request passes this filter. The
 * Connector_Key_Index matches the exact connector by finding its credential in
 * the request; the Caller_Resolver maps the call stack to a plugin/theme. This
 * complements the Gatekeeper's pre-request 'wp_ai_client_prevent_prompt' hook in
 * two ways:
 *
 *   1. Attribution: the matched connector + credential give an *exact*
 *      attribution (the strongest confidence tier). We enrich the pending intent
 *      the Gatekeeper already recorded so the tracked event is attributed
 *      exactly, rather than by self-ID/backtrace inference.
 *   2. Coverage: it also sees plugins that read a connector credential and make
 *      their own HTTP call, bypassing wp_ai_client_prompt() entirely — traffic
 *      the prompt hook is structurally blind to.
 *
 * Enforcement here is the same decision the Gatekeeper makes, via the shared
 * Enforcer: it stays observe-only until a hard limit is configured and fails
 * open on any error (hard invariant #1). There is no double-block: for AI-Client
 * calls the prompt hook runs first, and a prompt blocked there makes no HTTP
 * request, so this guard never sees it — it only *adds* the direct-caller path.
 */
class Http_Guard {

	/**
	 * Caller/user attribution resolver (shared with the Gatekeeper).
	 *
	 * @var Caller_Resolver
	 */
	private $resolver;

	/**
	 * Enforcer that decides whether a hard limit is breached (shared).
	 *
	 * @var Enforcer|null
	 */
	private $enforcer;

	/**
	 * Connector credential index.
	 *
	 * @var Connector_Key_Index
	 */
	private $key_index;

	/**
	 * The Gatekeeper holding pending intents to enrich.
	 *
	 * @var Gatekeeper|null
	 */
	private $gatekeeper;

	/**
	 * Re-entrancy guard: attribution and enforcement may themselves read options
	 * or (in future) make HTTP calls; this prevents recursion through the filter.
	 *
	 * @var bool
	 */
	private $in_filter = false;

	/**
	 * Constructor.
	 *
	 * @param Connector_Key_Index $key_index  Credential index.
	 * @param Caller_Resolver     $resolver   Attribution resolver (shared).
	 * @param Enforcer|null       $enforcer   Enforcer (shared); null disables enforcement.
	 * @param Gatekeeper|null     $gatekeeper Gatekeeper to enrich; null disables enrichment.
	 */
	public function __construct(
		Connector_Key_Index $key_index,
		Caller_Resolver $resolver,
		$enforcer = null,
		$gatekeeper = null
	) {
		$this->key_index  = $key_index;
		$this->resolver   = $resolver;
		$this->enforcer   = $enforcer instanceof Enforcer ? $enforcer : null;
		$this->gatekeeper = $gatekeeper instanceof Gatekeeper ? $gatekeeper : null;
	}

	/**
	 * Register the pre-request HTTP filter.
	 *
	 * Priority 5 runs before the default (10) so a block here pre-empts other
	 * short-circuit callbacks; 3 args to receive $args and $url.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'pre_http_request', [ $this, 'maybe_block_request' ], 5, 3 );
	}

	/**
	 * Attribute (and optionally block) an outbound request carrying a credential.
	 *
	 * Fails open: any error, or no matched connector, returns $preempt unchanged
	 * so the request proceeds exactly as it would without this plugin.
	 *
	 * @param mixed               $preempt Short-circuit value from earlier callbacks.
	 * @param array<string,mixed> $args    Parsed request arguments.
	 * @param string              $url     Request URL.
	 * @return mixed WP_Error to block, otherwise the incoming $preempt.
	 */
	public function maybe_block_request( $preempt, $args = [], $url = '' ) {
		// Respect an existing short-circuit and avoid re-entrancy.
		if ( false !== $preempt || $this->in_filter ) {
			return $preempt;
		}

		try {
			if ( ! is_array( $args ) ) {
				$args = [];
			}

			if ( ! is_string( $url ) ) {
				$url = '';
			}

			$this->in_filter = true;

			$connector_id = $this->key_index->lookup( $args, $url );

			if ( null === $connector_id ) {
				return $preempt; // Not an AI-connector request; leave it alone.
			}

			$caller = $this->resolver->resolve();
			$user   = $this->resolver->resolve_user();

			// 1) Enrich the pending intent with exact attribution (tracking path).
			if ( null !== $this->gatekeeper ) {
				$this->gatekeeper->enrich_latest_intent( $connector_id, $caller );
			}

			// 2) Enforcement (Phase 2). Observe-only until a hard limit exists; the
			// Enforcer short-circuits to false otherwise and fails open on error.
			if ( null !== $this->enforcer ) {
				$scopes = $this->build_scopes( $caller, $user );

				if ( $this->enforcer->should_block( $scopes, Caller_Resolver::CONFIDENCE_EXACT ) ) {
					return $this->blocked_error( $connector_id, $caller );
				}
			}

			return $preempt;
		} catch ( \Throwable $e ) {
			// Fail open: a tracking/limiting plugin must never break a site's AI.
			unset( $e );
			return $preempt;
		} finally {
			$this->in_filter = false;
		}
	}

	/**
	 * Build the scope_type => scope_key map for an enforcement decision.
	 *
	 * Mirrors the Gatekeeper's scopes and the Usage_Recorder's counter buckets so
	 * limits match the same buckets usage is counted into. Model scope is omitted
	 * (the model is not known at request time).
	 *
	 * @param array<string,mixed> $caller Resolved caller (source_slug).
	 * @param array<string,mixed> $user   Resolved user (user_id, user_role).
	 * @return array<string,string> scope_type => scope_key.
	 */
	private function build_scopes( array $caller, array $user ) {
		return [
			'plugin' => isset( $caller['source_slug'] ) ? (string) $caller['source_slug'] : '__unknown__',
			'user'   => isset( $user['user_id'] ) ? (string) $user['user_id'] : '0',
			'role'   => isset( $user['user_role'] ) ? (string) $user['user_role'] : '',
			'global' => '__all__',
		];
	}

	/**
	 * Build the WP_Error that blocks the outbound request.
	 *
	 * Returning a WP_Error from 'pre_http_request' short-circuits the HTTP stack;
	 * the AI Client surfaces it as a WP_Error to the caller (no exception).
	 *
	 * @param string              $connector_id Connector being used.
	 * @param array<string,mixed> $caller       Resolved caller.
	 * @return WP_Error
	 */
	private function blocked_error( $connector_id, array $caller ) {
		$slug = isset( $caller['source_slug'] ) ? (string) $caller['source_slug'] : '__unknown__';

		return new WP_Error(
			'wp_aiut_connector_blocked',
			sprintf(
				/* translators: 1: caller slug, 2: connector ID. */
				__( 'AI usage limit reached: "%1$s" is blocked from using the "%2$s" connector.', 'wp-aiut' ),
				$slug,
				(string) $connector_id
			),
			[
				'status'       => 403,
				'connector_id' => (string) $connector_id,
				'caller'       => $slug,
			]
		);
	}
}
