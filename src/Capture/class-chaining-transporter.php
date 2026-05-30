<?php
/**
 * Chaining HTTP transporter decorator (Path B).
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Decorates an existing SDK HTTP transporter to observe responses.
 *
 * The SDK transporter contract is not statically available (the SDK may be
 * absent at analysis time), so this class deliberately implements no SDK
 * interface and instead exposes a duck-typed `send()` method that forwards to
 * the wrapped transporter and then hands the response to the Result_Capturer.
 *
 * CRITICAL: we always CHAIN — if an existing transporter is present we forward
 * to it and never swallow its behaviour (Architecture §3B / §12). We never
 * replace a non-null existing transporter.
 *
 * In the AI-plugin-ABSENT scenario (spec §3 Path B), getHttpTransporter()
 * returns null because the SDK lazily creates its own default internally. To
 * make Path B actually function in that case — the very case it exists to cover
 * — when no inner transporter is supplied we resolve the SDK's own default
 * transporter and wrap THAT, so the chain still terminates in real transport.
 */
class Chaining_Transporter {

	/**
	 * The wrapped (inner) transporter, or null.
	 *
	 * @var object|null
	 */
	private $inner;

	/**
	 * The result capturer notified after each response.
	 *
	 * @var Result_Capturer
	 */
	private $capturer;

	/**
	 * The method name on the inner transporter used to send requests.
	 *
	 * Resolved once via duck-typing so we can forward dynamically.
	 *
	 * @var string
	 */
	private $send_method = '';

	/**
	 * Constructor.
	 *
	 * @param object|null     $inner    Existing transporter to chain, or null.
	 * @param Result_Capturer $capturer Capturer to notify with responses.
	 */
	public function __construct( $inner, Result_Capturer $capturer ) {
		$this->capturer = $capturer;

		// Never replace a non-null existing transporter — wrap it. When none is
		// supplied (AI-plugin-absent case), resolve the SDK's own default
		// transporter so the chain still terminates in real transport.
		$this->inner = is_object( $inner ) ? $inner : self::resolve_default_transporter();

		if ( null !== $this->inner ) {
			foreach ( [ 'send', 'request', 'transport', '__invoke' ] as $method ) {
				if ( method_exists( $this->inner, $method ) ) {
					$this->send_method = $method;
					break;
				}
			}
		}
	}

	/**
	 * Resolve the SDK's default HTTP transporter to wrap when none is supplied.
	 *
	 * The SDK lazily creates its own default transporter internally, so
	 * getHttpTransporter() returns null until something sets one. To make Path B
	 * work in the AI-plugin-absent scenario we construct that default ourselves
	 * and chain it. Discovery is defensive: the SDK may not be loadable at static
	 * analysis time, and the concrete class name varies across SDK versions, so
	 * we probe a small set of likely classes and expose a filter for an explicit
	 * override.
	 *
	 * @return object|null A default transporter instance, or null when none is
	 *                     constructable (caller then declines to install us).
	 */
	private static function resolve_default_transporter() {
		/**
		 * Filter the default HTTP transporter the chaining decorator wraps when
		 * no existing transporter is set (AI-plugin-absent / Path B).
		 *
		 * Lets a site/integration hand us the exact default transporter object
		 * (or a factory result) when auto-discovery cannot construct one.
		 *
		 * @param object|null $transporter Default transporter, or null.
		 */
		$filtered = apply_filters( 'wp_ai_rate_limiter_default_transporter', null );

		if ( is_object( $filtered ) ) {
			return $filtered;
		}

		// Candidate default transporter classes observed across php-ai-client
		// style SDKs. We instantiate the first one that exists and constructs
		// without arguments.
		$candidates = [
			'\\WordPress\\AiClient\\Transport\\HttpTransporter',
			'\\WordPress\\AiClient\\Transport\\WpHttpTransporter',
			'\\WordPress\\AiClient\\Http\\HttpTransporter',
			'\\WordPress\\AiClient\\Http\\WpHttpTransporter',
		];

		foreach ( $candidates as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			try {
				$reflection = new \ReflectionClass( $class );

				if ( ! $reflection->isInstantiable() ) {
					continue;
				}

				$constructor = $reflection->getConstructor();

				// Only construct when we can do so safely with no required args.
				if ( null === $constructor || 0 === $constructor->getNumberOfRequiredParameters() ) {
					return $reflection->newInstance();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return null;
	}

	/**
	 * Whether this decorator can safely chain the inner transporter.
	 *
	 * Only true when there is an inner transporter (either the one supplied or a
	 * resolved SDK default) AND we found a method to forward to — otherwise
	 * installing us would break transport, so the caller declines to install.
	 *
	 * @return bool
	 */
	public function is_compatible() {
		return null !== $this->inner && '' !== $this->send_method;
	}

	/**
	 * Send a request by forwarding to the inner transporter, then observe.
	 *
	 * Mirrors the most common SDK transporter signature. The response is passed
	 * to the inner transporter untouched and returned untouched; we only peek at
	 * it for token usage.
	 *
	 * @param mixed ...$args Whatever the SDK passes to the transporter.
	 * @return mixed The inner transporter's return value, unmodified.
	 */
	public function send( ...$args ) {
		return $this->forward( $args );
	}

	/**
	 * Alternate SDK entry name; forwards identically to send().
	 *
	 * @param mixed ...$args Transport arguments.
	 * @return mixed
	 */
	public function request( ...$args ) {
		return $this->forward( $args );
	}

	/**
	 * Invokable form; forwards identically to send().
	 *
	 * @param mixed ...$args Transport arguments.
	 * @return mixed
	 */
	public function __invoke( ...$args ) {
		return $this->forward( $args );
	}

	/**
	 * Forward to the inner transporter and observe the response.
	 *
	 * The forward itself is never swallowed; only our observation is wrapped in
	 * a try/catch so a bug in capture cannot break the AI request.
	 *
	 * @param array<int,mixed> $args Arguments captured from the SDK call.
	 * @return mixed The inner transporter's response.
	 * @throws \RuntimeException When no valid inner transporter is set to forward to.
	 */
	private function forward( array $args ) {
		// If we somehow got installed without a valid inner transporter, there
		// is nothing to forward to; surface a clear failure rather than silently
		// dropping the request.
		if ( ! $this->is_compatible() ) {
			throw new \RuntimeException( 'Chaining_Transporter has no inner transporter to forward to.' );
		}

		$response = call_user_func_array( [ $this->inner, $this->send_method ], $args );

		try {
			$this->capturer->on_transporter_response( $response );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $response;
	}

	/**
	 * Expose the wrapped transporter (for diagnostics / further chaining).
	 *
	 * @return object|null
	 */
	public function get_inner() {
		return $this->inner;
	}
}
