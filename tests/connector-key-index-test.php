<?php
/**
 * Tests for the Connector_Key_Index credential scan.
 *
 * Verifies that an outbound request is attributed to a connector only when it
 * actually carries that connector's credential, in either raw or url-encoded
 * form, across the URL and header values — and that unrelated traffic is left
 * alone.
 *
 * @package WP_AIUT
 */

use WP_AIUT\Capture\Connector_Key_Index;

/**
 * @covers \WP_AIUT\Capture\Connector_Key_Index
 */
class Connector_Key_Index_Test extends AIUT_TestCase {

	/**
	 * A test key long enough to clear the MIN_KEY_LENGTH floor.
	 */
	const KEY = 'sk-test-abcdefghijklmnop-1234567890';

	/**
	 * Build an index over a single fake connector whose credential lives in a
	 * seeded option, bypassing core's connector registry so the test is
	 * independent of the WP version under test.
	 *
	 * @return Connector_Key_Index
	 */
	private function make_index() {
		update_option( 'aiut_test_connector_key', self::KEY, false );

		return new class() extends Connector_Key_Index {
			protected function get_connectors() {
				return [
					'testprov' => [
						'type'           => 'ai_provider',
						'authentication' => [
							'method'       => 'api_key',
							'setting_name' => 'aiut_test_connector_key',
						],
					],
				];
			}
		};
	}

	/**
	 * A credential in a header value identifies the connector.
	 */
	public function test_matches_credential_in_header() {
		$idx = $this->make_index();

		$hit = $idx->lookup(
			[ 'headers' => [ 'x-api-key' => self::KEY, 'content-type' => 'application/json' ] ],
			'https://api.example.com/v1/messages'
		);

		$this->assertSame( 'testprov', $hit );
	}

	/**
	 * A credential url-encoded into the request URL still matches.
	 */
	public function test_matches_url_encoded_credential_in_url() {
		$idx = $this->make_index();

		$hit = $idx->lookup( [], 'https://api.example.com/v1/x?key=' . rawurlencode( self::KEY ) );

		$this->assertSame( 'testprov', $hit );
	}

	/**
	 * A request carrying no configured credential is not attributed — unrelated
	 * HTTP traffic must pass through untouched.
	 */
	public function test_no_match_without_credential() {
		$idx = $this->make_index();

		$hit = $idx->lookup(
			[ 'headers' => [ 'authorization' => 'Bearer something-else' ] ],
			'https://example.com/unrelated'
		);

		$this->assertNull( $hit );
	}

	/**
	 * A credential nested inside an array-valued header (as WP's HTTP API allows)
	 * is still scanned.
	 */
	public function test_matches_credential_in_array_header() {
		$idx = $this->make_index();

		$hit = $idx->lookup(
			[ 'headers' => [ 'x-api-key' => [ self::KEY ] ] ],
			'https://api.example.com/v1/messages'
		);

		$this->assertSame( 'testprov', $hit );
	}

	/**
	 * A too-short credential is ignored, so it cannot false-positive against
	 * unrelated header content.
	 */
	public function test_short_credentials_are_ignored() {
		update_option( 'aiut_test_short_key', 'short', false );

		$idx = new class() extends Connector_Key_Index {
			protected function get_connectors() {
				return [
					'shortprov' => [
						'authentication' => [
							'method'       => 'api_key',
							'setting_name' => 'aiut_test_short_key',
						],
					],
				];
			}
		};

		$this->assertNull( $idx->lookup( [ 'headers' => [ 'x' => 'short' ] ], 'https://x/short' ) );
	}

	/**
	 * With no connectors registered the index never matches (and does not error).
	 */
	public function test_empty_registry_matches_nothing() {
		$idx = new class() extends Connector_Key_Index {
			protected function get_connectors() {
				return [];
			}
		};

		$this->assertNull( $idx->lookup( [ 'headers' => [ 'x-api-key' => self::KEY ] ], 'https://x/' ) );
	}
}
