<?php
/**
 * Result capturer — turns completed AI requests into finalised usage rows.
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Captures real (or estimated) token usage after an AI request completes and
 * finalises the matching pending intent recorded by the Gatekeeper.
 *
 * Three paths, in priority order (spec §3 / Architecture §3):
 *   - Path A: the WordPress/ai logging plugin's 'wpai_request_log_context'
 *             filter (10,3) — accurate, post-completion token usage.
 *   - Path B: a transporter decorator installed via the SDK's public
 *             setHttpTransporter(). CHAINS any existing transporter, never
 *             replaces it (Architecture §3B / §12).
 *   - Path C: a chars/4 heuristic estimate, flagged estimated = true, used when
 *             no real tokens arrive for a pending intent.
 *
 * Every SDK / AI-plugin touch point is guarded so the file passes `php -l` and
 * runs harmlessly when the SDK is absent.
 */
class Result_Capturer {

	/**
	 * Core WP 7.0 AI Client action fired after a result is generated.
	 *
	 * Carries an AfterGenerateResultEvent. We read getResult() (a
	 * GenerativeAiResult) and take tokens from its getTokenUsage() DTO and
	 * provider/model from its getProviderMetadata()/getModelMetadata() DTOs.
	 * This is the most reliable capture source — real usage straight from the
	 * SDK — so it takes priority over the optional logging filter and the
	 * transporter decorator.
	 */
	const CORE_RESULT_ACTION = 'wp_ai_client_after_generate_result';

	/**
	 * AI-plugin logging filter (Path A). Optional / experimental.
	 */
	const AI_LOG_FILTER = 'wpai_request_log_context';

	/**
	 * Default characters-per-token divisor for the estimate (Path C).
	 */
	const CHARS_PER_TOKEN = 4;

	/**
	 * The owning Gatekeeper, source of pending intents.
	 *
	 * @var Gatekeeper
	 */
	private $gatekeeper;

	/**
	 * Constructor.
	 *
	 * @param Gatekeeper $gatekeeper The gatekeeper holding pending intents.
	 */
	public function __construct( Gatekeeper $gatekeeper ) {
		$this->gatekeeper = $gatekeeper;
	}

	/**
	 * Register the capture hooks for whichever paths are available.
	 *
	 * @return void
	 */
	public function register() {
		// Primary path: core's after-generate-result action carries the real
		// GenerativeAiResult DTO with accurate token usage. Most reliable source.
		add_action( self::CORE_RESULT_ACTION, [ $this, 'capture_from_core_event' ], 10, 1 );

		// Path A: only meaningful when the AI logging plugin is present, but the
		// filter is cheap to hook regardless — it simply never fires otherwise.
		add_filter( self::AI_LOG_FILTER, [ $this, 'capture_from_ai_log' ], 10, 3 );

		// Path B: attempt to install a chaining transporter decorator. Guarded
		// internally; a no-op when the SDK is not loadable.
		add_action( 'init', [ $this, 'install_transporter_decorator' ], 5 );

		// Path C: sweep any still-pending intents at end of request and finalise
		// them with an estimate so counters still move.
		add_action( 'shutdown', [ $this, 'finalize_remaining_with_estimates' ], 100 );
	}

	/**
	 * Primary path — capture real usage from core's after-generate-result event.
	 *
	 * The event (AfterGenerateResultEvent) exposes getResult(), a
	 * GenerativeAiResult whose getTokenUsage() returns a TokenUsage DTO with
	 * getPromptTokens()/getCompletionTokens()/getThoughtTokens(). Provider/model
	 * come from getProviderMetadata()/getModelMetadata(). All access is guarded
	 * with method_exists so a future SDK shape change degrades gracefully.
	 *
	 * @param object $event The AfterGenerateResultEvent.
	 * @return void
	 */
	public function capture_from_core_event( $event ) {
		try {
			if ( ! is_object( $event ) || ! method_exists( $event, 'getResult' ) ) {
				return;
			}

			$result = $event->getResult();

			if ( ! is_object( $result ) ) {
				return;
			}

			$usage = $this->extract_usage_from_result( $result );

			if ( null === $usage ) {
				return;
			}

			$meta  = $this->extract_meta_from_result( $result );
			$match = $this->gatekeeper->match_pending( null );

			if ( null !== $match ) {
				$this->finalize( $match, $usage, $meta, false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Extract token usage from a GenerativeAiResult DTO.
	 *
	 * @param object $result The GenerativeAiResult.
	 * @return array{input:int,output:int,thinking:int}|null
	 */
	private function extract_usage_from_result( $result ) {
		if ( ! method_exists( $result, 'getTokenUsage' ) ) {
			return null;
		}

		$tu = $result->getTokenUsage();

		if ( ! is_object( $tu ) ) {
			return null;
		}

		$input    = method_exists( $tu, 'getPromptTokens' ) ? (int) $tu->getPromptTokens() : 0;
		$output   = method_exists( $tu, 'getCompletionTokens' ) ? (int) $tu->getCompletionTokens() : 0;
		$thinking = method_exists( $tu, 'getThoughtTokens' ) ? (int) $tu->getThoughtTokens() : 0;

		if ( 0 === $input && 0 === $output && 0 === $thinking ) {
			return null;
		}

		return [
			'input'    => $input,
			'output'   => $output,
			'thinking' => $thinking,
		];
	}

	/**
	 * Extract provider/model from a GenerativeAiResult DTO.
	 *
	 * @param object $result The GenerativeAiResult.
	 * @return array{provider:string,model:string,slug:null}
	 */
	private function extract_meta_from_result( $result ) {
		$provider = '';
		$model    = '';

		if ( method_exists( $result, 'getProviderMetadata' ) ) {
			$pm = $result->getProviderMetadata();
			if ( is_object( $pm ) && method_exists( $pm, 'getId' ) ) {
				$provider = (string) $pm->getId();
			}
		}

		if ( method_exists( $result, 'getModelMetadata' ) ) {
			$mm = $result->getModelMetadata();
			if ( is_object( $mm ) && method_exists( $mm, 'getId' ) ) {
				$model = (string) $mm->getId();
			}
		}

		return [
			'provider' => $provider,
			'model'    => $model,
			'slug'     => null,
		];
	}

	/**
	 * Path A — read real token usage from the AI logging plugin's filter.
	 *
	 * Signature mandated by the AI plugin: ($context, $decoded, $log_data),
	 * priority 10, 3 args. We never modify $context — we only read usage and
	 * finalise a matching pending intent — so we return it unchanged.
	 *
	 * @param mixed $context  The log context (returned unchanged).
	 * @param mixed $decoded  Decoded request/response payload.
	 * @param mixed $log_data Additional log metadata.
	 * @return mixed The unchanged $context.
	 */
	public function capture_from_ai_log( $context, $decoded = null, $log_data = null ) {
		try {
			$usage = $this->extract_usage_from_log( $decoded, $log_data );

			if ( null !== $usage ) {
				$meta  = $this->extract_meta_from_log( $decoded, $log_data );
				$match = $this->gatekeeper->match_pending( $meta['slug'] );

				if ( null !== $match ) {
					$this->finalize( $match, $usage, $meta, false );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $context;
	}

	/**
	 * Path B — install a chaining transporter decorator on the SDK.
	 *
	 * Heavily guarded: the SDK classes may not be autoloadable at static
	 * analysis time, so every interaction is behind function_exists /
	 * class_exists / method_exists and duck-typing. If an existing transporter
	 * is set we DECORATE it (chain), never replace it.
	 *
	 * @return void
	 */
	public function install_transporter_decorator() {
		try {
			$client = $this->locate_sdk_client();

			if ( null === $client ) {
				return; // SDK not present / not locatable — Paths A & C cover us.
			}

			// Must be able to both read and set the transporter to chain safely.
			if ( ! method_exists( $client, 'setHttpTransporter' ) ) {
				return;
			}

			$existing = null;

			if ( method_exists( $client, 'getHttpTransporter' ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- third-party SDK method name.
				$existing = $client->getHttpTransporter();
			}

			$decorator = new Chaining_Transporter( $existing, $this );

			// Only install if the decorator satisfies the transporter contract.
			if ( $decorator->is_compatible() ) {
				$client->setHttpTransporter( $decorator );
			}
		} catch ( \Throwable $e ) {
			// A failed decoration must never break AI requests; fall through to
			// Paths A and C.
			unset( $e );
		}
	}

	/**
	 * Callback for the transporter decorator after a response is observed.
	 *
	 * Extracts token usage from a raw SDK response and finalises the most
	 * recent matching pending intent.
	 *
	 * @param mixed $response The SDK response object/array.
	 * @return void
	 */
	public function on_transporter_response( $response ) {
		try {
			$usage = $this->extract_usage_from_response( $response );

			if ( null === $usage ) {
				return;
			}

			$meta  = $this->extract_meta_from_response( $response );
			$match = $this->gatekeeper->match_pending( null );

			if ( null !== $match ) {
				$this->finalize( $match, $usage, $meta, false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Path C — finalise any leftover pending intents with an estimate.
	 *
	 * Runs late on 'shutdown'. Anything not already finalised by Path A or B
	 * gets a chars/4 estimate and is marked estimated = true so the dashboard
	 * can flag it.
	 *
	 * @return void
	 */
	public function finalize_remaining_with_estimates() {
		try {
			foreach ( $this->gatekeeper->pending_intents() as $intent ) {
				if ( ! empty( $intent['finalized'] ) ) {
					continue;
				}

				$intent['_key'] = $intent['fingerprint'];

				$usage = $this->estimate_usage( $intent );
				$meta  = [
					'provider' => '',
					'model'    => '',
					'slug'     => null,
				];

				$this->finalize( $intent, $usage, $meta, true );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Finalise a matched intent into a usage row via the Usage_Recorder.
	 *
	 * @param array<string,mixed> $intent    The matched pending intent (with _key).
	 * @param array<string,int>   $usage     Token usage (input/output/thinking).
	 * @param array<string,mixed> $meta      Provider/model metadata.
	 * @param bool                $estimated Whether tokens were estimated.
	 * @return void
	 */
	private function finalize( array $intent, array $usage, array $meta, $estimated ) {
		$key = isset( $intent['_key'] ) ? $intent['_key'] : ( isset( $intent['fingerprint'] ) ? $intent['fingerprint'] : '' );

		// Guard against double-finalisation under concurrent paths.
		if ( '' !== $key ) {
			$this->gatekeeper->mark_finalized( $key );
		}

		$row = [
			'plugin_slug'       => isset( $intent['source_slug'] ) ? $intent['source_slug'] : '__unknown__',
			'plugin_confidence' => isset( $intent['confidence'] ) ? $intent['confidence'] : 'low',
			'user_id'           => isset( $intent['user_id'] ) ? (int) $intent['user_id'] : 0,
			'user_role'         => isset( $intent['user_role'] ) ? (string) $intent['user_role'] : '',
			'provider'          => isset( $meta['provider'] ) ? (string) $meta['provider'] : '',
			'model'             => isset( $meta['model'] ) ? (string) $meta['model'] : '',
			'input_tokens'      => isset( $usage['input'] ) ? (int) $usage['input'] : 0,
			'output_tokens'     => isset( $usage['output'] ) ? (int) $usage['output'] : 0,
			'thinking_tokens'   => isset( $usage['thinking'] ) ? (int) $usage['thinking'] : 0,
			'estimated'         => $estimated ? 1 : 0,
		];

		if ( class_exists( '\\WP_AIUT\\Accounting\\Usage_Recorder' )
			&& method_exists( '\\WP_AIUT\\Accounting\\Usage_Recorder', 'record' ) ) {
			\WP_AIUT\Accounting\Usage_Recorder::record( $row );
		}
	}

	/**
	 * Produce an estimated usage array from a pending intent (Path C).
	 *
	 * @param array<string,mixed> $intent The pending intent.
	 * @return array{input:int,output:int,thinking:int}
	 */
	private function estimate_usage( array $intent ) {
		$chars = isset( $intent['prompt_chars'] ) ? (int) $intent['prompt_chars'] : 0;

		$input = (int) floor( $chars / self::CHARS_PER_TOKEN );

		/**
		 * Filter the per-token character divisor used by the estimate.
		 *
		 * @param int $divisor Characters per token (default 4).
		 */
		$divisor = (int) apply_filters( 'wp_aiut_chars_per_token', self::CHARS_PER_TOKEN );

		if ( $divisor > 0 && self::CHARS_PER_TOKEN !== $divisor ) {
			$input = (int) floor( $chars / $divisor );
		}

		return [
			'input'    => $input,
			'output'   => 0,
			'thinking' => 0,
		];
	}

	/**
	 * Extract token usage from the AI-plugin log payload (Path A).
	 *
	 * Defensive: the exact shape is experimental, so we probe several likely
	 * key paths in $decoded and $log_data.
	 *
	 * @param mixed $decoded  Decoded payload.
	 * @param mixed $log_data Log metadata.
	 * @return array{input:int,output:int,thinking:int}|null Usage, or null.
	 */
	private function extract_usage_from_log( $decoded, $log_data ) {
		foreach ( [ $log_data, $decoded ] as $source ) {
			$usage = $this->probe_usage_shape( $source );

			if ( null !== $usage ) {
				return $usage;
			}
		}

		return null;
	}

	/**
	 * Probe a value for a recognisable token-usage shape.
	 *
	 * Accepts arrays or objects with usage/token_usage members carrying
	 * input/output/thinking (or prompt/completion) counts.
	 *
	 * @param mixed $value Candidate value.
	 * @return array{input:int,output:int,thinking:int}|null
	 */
	private function probe_usage_shape( $value ) {
		$arr = $this->to_array( $value );

		if ( ! is_array( $arr ) ) {
			return null;
		}

		// Drill into a nested usage container if present.
		foreach ( [ 'usage', 'token_usage', 'tokenUsage', 'tokens' ] as $container ) {
			if ( isset( $arr[ $container ] ) ) {
				$nested = $this->to_array( $arr[ $container ] );

				if ( is_array( $nested ) ) {
					$arr = $nested;
					break;
				}
			}
		}

		$input    = $this->first_numeric( $arr, [ 'input', 'input_tokens', 'inputTokens', 'prompt', 'prompt_tokens', 'promptTokens' ] );
		$output   = $this->first_numeric( $arr, [ 'output', 'output_tokens', 'outputTokens', 'completion', 'completion_tokens', 'completionTokens' ] );
		$thinking = $this->first_numeric( $arr, [ 'thinking', 'thinking_tokens', 'thinkingTokens', 'reasoning', 'reasoning_tokens' ] );

		if ( null === $input && null === $output && null === $thinking ) {
			return null;
		}

		return [
			'input'    => (int) $input,
			'output'   => (int) $output,
			'thinking' => (int) $thinking,
		];
	}

	/**
	 * Extract provider/model/slug hints from the AI-plugin log payload.
	 *
	 * @param mixed $decoded  Decoded payload.
	 * @param mixed $log_data Log metadata.
	 * @return array{provider:string,model:string,slug:string|null}
	 */
	private function extract_meta_from_log( $decoded, $log_data ) {
		$provider = '';
		$model    = '';
		$slug     = null;

		foreach ( [ $log_data, $decoded ] as $source ) {
			$arr = $this->to_array( $source );

			if ( ! is_array( $arr ) ) {
				continue;
			}

			if ( '' === $provider ) {
				$provider = (string) $this->first_string( $arr, [ 'provider', 'provider_slug', 'providerSlug' ] );
			}

			if ( '' === $model ) {
				$model = (string) $this->first_string( $arr, [ 'model', 'model_slug', 'modelSlug', 'modelId' ] );
			}

			if ( null === $slug ) {
				$hint = $this->first_string( $arr, [ 'feature', 'kind', 'source', 'plugin' ] );
				$slug = ( '' === $hint ) ? null : sanitize_key( $hint );
			}
		}

		return [
			'provider' => $provider,
			'model'    => $model,
			'slug'     => $slug,
		];
	}

	/**
	 * Extract token usage from a raw SDK transporter response (Path B).
	 *
	 * @param mixed $response The SDK response.
	 * @return array{input:int,output:int,thinking:int}|null
	 */
	private function extract_usage_from_response( $response ) {
		// Many SDK responses expose a body/json accessor; probe object methods
		// and array shapes defensively.
		$candidates = [];

		if ( is_object( $response ) ) {
			foreach ( [ 'getDecodedBody', 'getBody', 'toArray', 'getData', 'getUsage' ] as $method ) {
				if ( method_exists( $response, $method ) ) {
					try {
						$candidates[] = $response->$method();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
		}

		$candidates[] = $response;

		foreach ( $candidates as $candidate ) {
			$decoded = $candidate;

			if ( is_string( $candidate ) ) {
				$json = json_decode( $candidate, true );

				if ( is_array( $json ) ) {
					$decoded = $json;
				}
			}

			$usage = $this->probe_usage_shape( $decoded );

			if ( null !== $usage ) {
				return $usage;
			}
		}

		return null;
	}

	/**
	 * Extract provider/model from a raw SDK transporter response (Path B).
	 *
	 * @param mixed $response The SDK response.
	 * @return array{provider:string,model:string,slug:null}
	 */
	private function extract_meta_from_response( $response ) {
		$provider = '';
		$model    = '';

		$arr = $this->to_array( $response );

		if ( is_array( $arr ) ) {
			$provider = (string) $this->first_string( $arr, [ 'provider', 'provider_slug' ] );
			$model    = (string) $this->first_string( $arr, [ 'model', 'model_slug', 'modelId' ] );
		}

		return [
			'provider' => $provider,
			'model'    => $model,
			'slug'     => null,
		];
	}

	/**
	 * Locate the SDK client/registry exposing setHttpTransporter().
	 *
	 * Probes a small set of likely entry points without hard-coding a single
	 * class, and returns the first object that exposes the method. Returns null
	 * when the SDK is not loadable.
	 *
	 * @return object|null
	 */
	private function locate_sdk_client() {
		/**
		 * Filter the SDK client object used for transporter decoration.
		 *
		 * Lets a site/integration hand us the exact object that owns the
		 * transporter when auto-discovery cannot find it.
		 *
		 * @param object|null $client Discovered client, or null.
		 */
		$filtered = apply_filters( 'wp_aiut_sdk_client', null );

		if ( is_object( $filtered ) && method_exists( $filtered, 'setHttpTransporter' ) ) {
			return $filtered;
		}

		// Candidate static accessors observed in php-ai-client style SDKs.
		$candidates = [
			[ '\\WordPress\\AiClient\\AiClient', 'instance' ],
			[ '\\WordPress\\AiClient\\AiClient', 'getInstance' ],
			[ '\\WordPress\\AiClient\\ProviderRegistry', 'instance' ],
		];

		foreach ( $candidates as $candidate ) {
			list( $class, $method ) = $candidate;

			if ( class_exists( $class ) && method_exists( $class, $method ) ) {
				try {
					$obj = call_user_func( [ $class, $method ] );

					if ( is_object( $obj ) && method_exists( $obj, 'setHttpTransporter' ) ) {
						return $obj;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		return null;
	}

	/**
	 * Coerce a value to an array view (objects via get_object_vars / toArray).
	 *
	 * @param mixed $value Candidate value.
	 * @return array<mixed>|null Array view, or null.
	 */
	private function to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			if ( method_exists( $value, 'toArray' ) ) {
				try {
					$out = $value->toArray();

					if ( is_array( $out ) ) {
						return $out;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			return get_object_vars( $value );
		}

		return null;
	}

	/**
	 * Return the first numeric value among candidate keys.
	 *
	 * @param array<mixed> $arr  Source array.
	 * @param string[]     $keys Candidate keys, in priority order.
	 * @return int|null First numeric value, or null.
	 */
	private function first_numeric( array $arr, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $arr[ $key ] ) && is_numeric( $arr[ $key ] ) ) {
				return (int) $arr[ $key ];
			}
		}

		return null;
	}

	/**
	 * Return the first non-empty string among candidate keys.
	 *
	 * @param array<mixed> $arr  Source array.
	 * @param string[]     $keys Candidate keys, in priority order.
	 * @return string First string value, or '' when none found.
	 */
	private function first_string( array $arr, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $arr[ $key ] ) && is_string( $arr[ $key ] ) && '' !== $arr[ $key ] ) {
				return $arr[ $key ];
			}
		}

		return '';
	}
}
