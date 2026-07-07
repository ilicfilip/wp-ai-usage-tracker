<?php
/**
 * Shared test doubles used across multiple test files.
 *
 * Loaded by tests/bootstrap.php (no -test.php suffix, so PHPUnit does not
 * auto-collect it as a test). Defining these here — rather than inside a single
 * *-test.php file — avoids depending on the order PHPUnit loads test files in.
 *
 * @package WP_AIUT
 */

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
	 * Confidence this stub reports.
	 *
	 * @var string
	 */
	private $confidence;

	/**
	 * @param string $slug       Slug to report from resolve().
	 * @param string $confidence Confidence to report.
	 */
	public function __construct( $slug = 'acme', $confidence = 'high' ) {
		$this->slug       = $slug;
		$this->confidence = $confidence;
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
			'confidence'  => $this->confidence,
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
