<?php
/**
 * Tests for the HTTP-layer guard: exact attribution enrichment + enforcement.
 *
 * The guard runs on 'pre_http_request'. It must:
 *   - leave non-connector traffic and pre-empted requests untouched,
 *   - upgrade the Gatekeeper's pending intent to `exact` confidence + connector,
 *   - block (WP_Error) only when the Enforcer says so, and
 *   - fail open on any error.
 *
 * @package WP_AIUT
 */

use WP_AIUT\Capture\Connector_Key_Index;
use WP_AIUT\Capture\Gatekeeper;
use WP_AIUT\Capture\Http_Guard;
use WP_AIUT\Attribution\Caller_Resolver;

/**
 * @covers \WP_AIUT\Capture\Http_Guard
 * @group capture
 */
class Http_Guard_Test extends AIUT_TestCase {

	const KEY = 'sk-guard-abcdefghijklmnop-1234567890';

	public function set_up() {
		parent::set_up();
		Gatekeeper::reset();
	}

	public function tear_down() {
		Gatekeeper::reset();
		parent::tear_down();
	}

	/**
	 * A key index over one fake connector keyed on a seeded option.
	 *
	 * @return Connector_Key_Index
	 */
	private function make_index() {
		update_option( 'aiut_guard_connector_key', self::KEY, false );

		return new class() extends Connector_Key_Index {
			protected function get_connectors() {
				return [
					'testprov' => [
						'authentication' => [
							'method'       => 'api_key',
							'setting_name' => 'aiut_guard_connector_key',
						],
					],
				];
			}
		};
	}

	/**
	 * A request carrying the credential.
	 *
	 * @return array{0:array<string,mixed>,1:string}
	 */
	private function keyed_request() {
		return [
			[ 'headers' => [ 'x-api-key' => self::KEY ] ],
			'https://api.example.com/v1/messages',
		];
	}

	/**
	 * Non-connector traffic is passed through unchanged (returns $preempt).
	 */
	public function test_passes_through_non_connector_request() {
		$guard = new Http_Guard( $this->make_index(), new AIUT_Stub_Resolver() );

		$this->assertFalse( $guard->maybe_block_request( false, [], 'https://example.com/' ) );
	}

	/**
	 * An already-preempted request is left exactly as-is.
	 */
	public function test_respects_existing_preempt() {
		$guard          = new Http_Guard( $this->make_index(), new AIUT_Stub_Resolver() );
		list( $a, $u )  = $this->keyed_request();
		$preempt        = [ 'body' => 'cached' ];

		$this->assertSame( $preempt, $guard->maybe_block_request( $preempt, $a, $u ) );
	}

	/**
	 * With no enforcer and a matched connector, the request passes through but the
	 * pending intent is upgraded to `exact` confidence with the connector id.
	 */
	public function test_enriches_pending_intent_to_exact() {
		// Record an intent as a low-confidence unknown caller.
		$gk = new Gatekeeper( new AIUT_Stub_Resolver( '__unknown__', 'low' ), null, null );
		$gk->observe_prompt( false, new stdClass() );

		$before = $gk->match_pending( null );
		$this->assertSame( 'low', $before['confidence'] );

		$guard = new Http_Guard(
			$this->make_index(),
			new AIUT_Stub_Resolver( 'acme-seo', 'medium' ),
			null,
			$gk
		);

		list( $a, $u ) = $this->keyed_request();
		$result        = $guard->maybe_block_request( false, $a, $u );

		$this->assertFalse( $result, 'No enforcer => request proceeds.' );

		$after = $gk->match_pending( null );
		$this->assertSame( Caller_Resolver::CONFIDENCE_EXACT, $after['confidence'] );
		$this->assertSame( 'testprov', $after['connector_id'] );
		$this->assertSame( 'acme-seo', $after['source_slug'] );
	}

	/**
	 * When the Enforcer blocks, the guard returns a WP_Error 403 carrying the
	 * connector id.
	 */
	public function test_blocks_with_wp_error_when_enforcer_blocks() {
		$guard = new Http_Guard(
			$this->make_index(),
			new AIUT_Stub_Resolver(),
			new AIUT_Stub_Enforcer( true )
		);

		list( $a, $u ) = $this->keyed_request();
		$result        = $guard->maybe_block_request( false, $a, $u );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_aiut_connector_blocked', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
		$this->assertSame( 'testprov', $data['connector_id'] );
	}

	/**
	 * With an enforcer that declines to block, the request proceeds.
	 */
	public function test_allows_when_enforcer_does_not_block() {
		$guard = new Http_Guard(
			$this->make_index(),
			new AIUT_Stub_Resolver(),
			new AIUT_Stub_Enforcer( false )
		);

		list( $a, $u ) = $this->keyed_request();

		$this->assertFalse( $guard->maybe_block_request( false, $a, $u ) );
	}

	/**
	 * A throwing enforcer must not break the request — the guard fails open and
	 * returns the incoming $preempt (false).
	 */
	public function test_fails_open_when_enforcer_throws() {
		$throwing = new class() extends \WP_AIUT\Enforcement\Enforcer {
			public function should_block( array $scopes, $confidence ) {
				throw new \RuntimeException( 'boom' );
			}
		};

		$guard         = new Http_Guard( $this->make_index(), new AIUT_Stub_Resolver(), $throwing );
		list( $a, $u ) = $this->keyed_request();

		$result = $guard->maybe_block_request( false, $a, $u );

		$this->assertFalse( is_wp_error( $result ), 'Guard must fail open, not block, on error.' );
		$this->assertFalse( $result );
	}
}
