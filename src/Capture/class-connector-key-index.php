<?php
/**
 * Connector credential index — attributes an outbound HTTP request to a connector.
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a lookup of AI-connector credentials to connector IDs, then matches an
 * outbound HTTP request to the connector whose credential it carries.
 *
 * WordPress 7.0 exposes registered connectors through the core function
 * wp_get_connectors() (wp-includes/connectors.php). Each connector's
 * 'authentication' block names where its API key lives — a DB option
 * (setting_name), an environment variable (env_var_name), and/or a PHP constant
 * (constant_name). We read those values and scan each outbound request's URL and
 * header values for any of them: a match identifies the exact connector leaving
 * the site, which is a far stronger attribution signal than inferring a caller
 * from the AI Client builder internals.
 *
 * The index is read from WordPress *core* — never from the optional WordPress/ai
 * plugin — so this class introduces no hard dependency on that plugin (hard
 * invariant #2). Every core touch point is runtime-guarded (invariant #4): with
 * wp_get_connectors() absent the index is simply empty and lookup() never
 * matches, so non-AI traffic is untouched.
 *
 * Limitation: a caller that transforms the credential before sending (signing,
 * custom encryption) is not matched, because the raw key is not present in the
 * request. The keyless-provider base-URL fallback used by the WordPress/ai
 * experiment (for e.g. Ollama) is intentionally omitted here — it needs the SDK
 * provider registry; the credential scan is the reliable, dependency-free win.
 *
 * Credentials are held in plaintext only for substring scanning; they are
 * already in memory for the request (the connector plugin itself read them).
 * This class never persists or logs key material.
 */
class Connector_Key_Index {

	/**
	 * Minimum length a credential must have to be scanned for.
	 *
	 * Short strings would false-positive against unrelated header values; real
	 * provider keys are consistently longer than this.
	 */
	const MIN_KEY_LENGTH = 10;

	/**
	 * Lazily-built map of credential string => connector_id.
	 *
	 * Null until the first lookup(). Built lazily on purpose: core's
	 * wp_get_connectors() returns an empty array until the connector registry is
	 * initialised (on '_wp_connectors_init'). Because lookup() first runs when an
	 * outbound HTTP request is actually made — well after init — a lazy build
	 * sees a populated registry. Do NOT build this eagerly at construction time.
	 *
	 * @var array<string,string>|null
	 */
	private $key_to_connector = null;

	/**
	 * Find the connector ID whose credential appears in the request.
	 *
	 * Scans the URL and every header value (string or array of strings) for each
	 * configured credential, in both raw and rawurlencode()'d forms so a key
	 * serialised into a query string still matches.
	 *
	 * @param array<string,mixed> $args Request arguments from 'pre_http_request'.
	 * @param string              $url  The request URL.
	 * @return string|null Connector ID, or null when nothing matched.
	 */
	public function lookup( array $args, $url ) {
		$keys = $this->get_keys();

		if ( [] === $keys ) {
			return null;
		}

		$haystacks = $this->collect_haystacks( $args, (string) $url );

		if ( [] === $haystacks ) {
			return null;
		}

		foreach ( $keys as $key => $connector_id ) {
			$encoded = rawurlencode( $key );

			foreach ( $haystacks as $haystack ) {
				if ( false !== strpos( $haystack, $key ) ) {
					return $connector_id;
				}

				if ( $encoded !== $key && false !== strpos( $haystack, $encoded ) ) {
					return $connector_id;
				}
			}
		}

		return null;
	}

	/**
	 * Clear the cached index so the next lookup() rebuilds it.
	 *
	 * Useful when connector credentials change during the same request (tests,
	 * long-running CLI). Production requests get a fresh index anyway.
	 *
	 * @return void
	 */
	public function invalidate() {
		$this->key_to_connector = null;
	}

	/**
	 * Return the credential => connector_id map, building it on first access.
	 *
	 * @return array<string,string>
	 */
	private function get_keys() {
		if ( null !== $this->key_to_connector ) {
			return $this->key_to_connector;
		}

		$this->key_to_connector = $this->build_index();

		return $this->key_to_connector;
	}

	/**
	 * Build the credential => connector_id map from core-registered connectors.
	 *
	 * @return array<string,string>
	 */
	private function build_index() {
		$index = [];

		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return $index;
		}

		$connectors = wp_get_connectors();

		if ( ! is_array( $connectors ) ) {
			return $index;
		}

		foreach ( $connectors as $connector_id => $data ) {
			if ( ! is_string( $connector_id ) || '' === $connector_id || ! is_array( $data ) ) {
				continue;
			}

			$auth = isset( $data['authentication'] ) && is_array( $data['authentication'] )
				? $data['authentication']
				: [];

			foreach ( $this->read_credentials( $auth ) as $credential ) {
				if ( strlen( $credential ) < self::MIN_KEY_LENGTH ) {
					continue;
				}

				// First writer wins; connectors rarely share a credential, and a
				// deterministic map keeps attribution stable across a request.
				if ( ! isset( $index[ $credential ] ) ) {
					$index[ $credential ] = $connector_id;
				}
			}
		}

		return $index;
	}

	/**
	 * Read every credential string configured for an authentication block.
	 *
	 * A connector may populate any of: a DB option, an environment variable, or a
	 * PHP constant. We collect whichever are present.
	 *
	 * @param array<string,mixed> $auth Authentication metadata from a connector.
	 * @return string[]
	 */
	private function read_credentials( array $auth ) {
		$credentials = [];

		$setting_name = isset( $auth['setting_name'] ) && is_string( $auth['setting_name'] ) ? $auth['setting_name'] : '';
		if ( '' !== $setting_name ) {
			$value = get_option( $setting_name, '' );
			if ( is_string( $value ) && '' !== $value ) {
				$credentials[] = $value;
			}
		}

		$env_var_name = isset( $auth['env_var_name'] ) && is_string( $auth['env_var_name'] ) ? $auth['env_var_name'] : '';
		if ( '' !== $env_var_name ) {
			$value = getenv( $env_var_name );
			if ( is_string( $value ) && '' !== $value ) {
				$credentials[] = $value;
			}
		}

		$constant_name = isset( $auth['constant_name'] ) && is_string( $auth['constant_name'] ) ? $auth['constant_name'] : '';
		if ( '' !== $constant_name && defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== $value ) {
				$credentials[] = $value;
			}
		}

		return $credentials;
	}

	/**
	 * Collect the strings that might carry a credential for a given request.
	 *
	 * @param array<string,mixed> $args Request args.
	 * @param string              $url  Request URL.
	 * @return string[]
	 */
	private function collect_haystacks( array $args, $url ) {
		$haystacks = [];

		if ( '' !== $url ) {
			$haystacks[] = $url;
		}

		$headers = isset( $args['headers'] ) ? $args['headers'] : [];

		if ( is_array( $headers ) ) {
			foreach ( $headers as $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$haystacks[] = $value;
				} elseif ( is_array( $value ) ) {
					foreach ( $value as $sub ) {
						if ( is_string( $sub ) && '' !== $sub ) {
							$haystacks[] = $sub;
						}
					}
				}
			}
		}

		return $haystacks;
	}
}
