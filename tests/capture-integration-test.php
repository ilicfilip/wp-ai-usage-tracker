<?php
/**
 * Capture integration test — the real post-request capture path.
 *
 * Drives Result_Capturer::capture_from_core_event() with a fake
 * GenerativeAiResult DTO (the same getter shape the WP 7.0 AI Client exposes),
 * after registering a pending intent through the Gatekeeper. This proves the
 * end-to-end capture chain works: pre-request intent -> core result event ->
 * recency correlation -> Usage_Recorder -> counters — i.e. a completed AI call
 * actually gets tracked and attributed, without needing a live provider.
 *
 * @package WP_AIUT
 */

use WP_AIUT\Capture\Gatekeeper;
use WP_AIUT\Capture\Result_Capturer;
use WP_AIUT\Accounting\Counter_Store;
use WP_AIUT\Periods\Window;

/**
 * Fake TokenUsage DTO: getter methods, not array keys (matches the SDK).
 */
class AIUT_Fake_Token_Usage {

	/** @var int */ private $prompt;
	/** @var int */ private $completion;
	/** @var int */ private $thought;

	/**
	 * @param int $prompt     Input tokens.
	 * @param int $completion Output tokens.
	 * @param int $thought    Thinking tokens.
	 */
	public function __construct( $prompt, $completion, $thought ) {
		$this->prompt     = $prompt;
		$this->completion = $completion;
		$this->thought    = $thought;
	}

	public function getPromptTokens() {
		return $this->prompt;
	}

	public function getCompletionTokens() {
		return $this->completion;
	}

	public function getThoughtTokens() {
		return $this->thought;
	}
}

/**
 * Fake provider/model metadata DTO exposing getId().
 */
class AIUT_Fake_Metadata {

	/** @var string */ private $id;

	/**
	 * @param string $id The provider or model id.
	 */
	public function __construct( $id ) {
		$this->id = $id;
	}

	public function getId() {
		return $this->id;
	}
}

/**
 * Fake GenerativeAiResult DTO.
 */
class AIUT_Fake_Result {

	/** @var AIUT_Fake_Token_Usage */ private $usage;
	/** @var AIUT_Fake_Metadata */ private $provider;
	/** @var AIUT_Fake_Metadata */ private $model;

	/**
	 * @param AIUT_Fake_Token_Usage $usage    Token usage DTO.
	 * @param string                $provider Provider id.
	 * @param string                $model    Model id.
	 */
	public function __construct( AIUT_Fake_Token_Usage $usage, $provider, $model ) {
		$this->usage    = $usage;
		$this->provider = new AIUT_Fake_Metadata( $provider );
		$this->model    = new AIUT_Fake_Metadata( $model );
	}

	public function getTokenUsage() {
		return $this->usage;
	}

	public function getProviderMetadata() {
		return $this->provider;
	}

	public function getModelMetadata() {
		return $this->model;
	}
}

/**
 * Fake AfterGenerateResultEvent exposing getResult().
 */
class AIUT_Fake_Event {

	/** @var AIUT_Fake_Result */ private $result;

	/**
	 * @param AIUT_Fake_Result $result The result DTO.
	 */
	public function __construct( AIUT_Fake_Result $result ) {
		$this->result = $result;
	}

	public function getResult() {
		return $this->result;
	}
}

/**
 * @group capture
 */
class Capture_Integration_Test extends AIUT_TestCase {

	public function set_up() {
		parent::set_up();
		Gatekeeper::reset();
	}

	public function tear_down() {
		Gatekeeper::reset();
		parent::tear_down();
	}

	/**
	 * A completed AI result is captured from the core event, correlated to the
	 * pending intent, and recorded with the real DTO tokens + provider/model,
	 * flagged as NOT estimated.
	 */
	public function test_core_event_capture_records_real_usage() {
		// 1) Pre-request: register a pending intent (self-identified "acme-ai").
		$gk = new Gatekeeper( new AIUT_Stub_Resolver( 'acme-ai' ), null, null );
		$gk->observe_prompt( false, new stdClass() );

		$capturer = new Result_Capturer( $gk );

		// 2) Post-request: the core event carries a real GenerativeAiResult DTO.
		$event = new AIUT_Fake_Event(
			new AIUT_Fake_Result(
				new AIUT_Fake_Token_Usage( 800, 400, 100 ),
				'anthropic',
				'claude-opus-4-8'
			)
		);

		$capturer->capture_from_core_event( $event );

		// 3) The usage must have fanned out to the plugin counter for this month.
		$month   = Window::current_period_key( Window::KIND_MONTH );
		$counter = Counter_Store::read_one( 'plugin', 'acme-ai', 'month', $month );

		$this->assertNotNull( $counter, 'Captured usage must accrue to the intent\'s plugin scope.' );
		$this->assertSame( 1, (int) $counter['requests'] );
		$this->assertSame( 800, (int) $counter['input_tokens'] );
		$this->assertSame( 400, (int) $counter['output_tokens'] );
		$this->assertSame( 100, (int) $counter['thinking_tokens'] );

		// Real DTO tokens => cost is exact, not an estimate.
		// 800*15 + 400*75 + 100*75 = 12000 + 30000 + 7500 = 49500 micros.
		$this->assertSame( 49500, (int) $counter['est_cost_micros'] );

		// The model scope proves provider/model were captured from the DTO.
		$model_counter = Counter_Store::read_one( 'model', 'anthropic/claude-opus-4-8', 'month', $month );
		$this->assertNotNull( $model_counter );
		$this->assertSame( 1, (int) $model_counter['requests'] );
	}

	/**
	 * The intent is finalised after capture, so a second event does NOT re-record
	 * the same call against a stale intent.
	 */
	public function test_capture_finalises_intent_no_double_count() {
		$gk = new Gatekeeper( new AIUT_Stub_Resolver( 'acme-ai' ), null, null );
		$gk->observe_prompt( false, new stdClass() );

		$capturer = new Result_Capturer( $gk );

		$event = new AIUT_Fake_Event(
			new AIUT_Fake_Result(
				new AIUT_Fake_Token_Usage( 800, 400, 100 ),
				'anthropic',
				'claude-opus-4-8'
			)
		);

		// Fire the capture twice; only the first should match a pending intent.
		$capturer->capture_from_core_event( $event );
		$capturer->capture_from_core_event( $event );

		$month   = Window::current_period_key( Window::KIND_MONTH );
		$counter = Counter_Store::read_one( 'plugin', 'acme-ai', 'month', $month );

		$this->assertNotNull( $counter );
		$this->assertSame( 1, (int) $counter['requests'], 'A finalised intent must not be matched again.' );
	}

	/**
	 * A result with no matching pending intent is a no-op (nothing to attribute
	 * it to) rather than recording an orphaned row.
	 */
	public function test_result_without_intent_records_nothing() {
		$gk       = new Gatekeeper( new AIUT_Stub_Resolver( 'acme-ai' ), null, null );
		$capturer = new Result_Capturer( $gk );

		$event = new AIUT_Fake_Event(
			new AIUT_Fake_Result(
				new AIUT_Fake_Token_Usage( 800, 400, 100 ),
				'anthropic',
				'claude-opus-4-8'
			)
		);

		$capturer->capture_from_core_event( $event );

		$month   = Window::current_period_key( Window::KIND_MONTH );
		$counter = Counter_Store::read_one( 'global', '__all__', 'month', $month );

		$this->assertNull( $counter, 'No pending intent => no recorded usage.' );
	}
}
