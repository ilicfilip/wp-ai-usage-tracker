<?php
/**
 * Plugin wiring / container.
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter;

defined( 'ABSPATH' ) || exit;

/**
 * Central wiring class.
 *
 * Instantiates the capture and admin components on the appropriate hooks. Kept
 * deliberately minimal and defensive: each collaborator is guarded with
 * class_exists() because other agents own those files and may not be present in
 * every build during development.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Capture gatekeeper (observe-only in Phase 1).
	 *
	 * @var \WP_AI_Rate_Limiter\Capture\Gatekeeper|null
	 */
	private $gatekeeper = null;

	/**
	 * Admin settings page.
	 *
	 * @var \WP_AI_Rate_Limiter\Admin\Settings_Page|null
	 */
	private $settings_page = null;

	/**
	 * REST controller.
	 *
	 * @var \WP_AI_Rate_Limiter\Admin\Rest_Controller|null
	 */
	private $rest_controller = null;

	/**
	 * Threshold watcher (Phase 2 alerts).
	 *
	 * @var \WP_AI_Rate_Limiter\Alerts\Threshold_Watcher|null
	 */
	private $threshold_watcher = null;

	/**
	 * Boot the plugin: create the instance and register hooks once.
	 *
	 * @return Plugin
	 */
	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Constructor is private; use boot().
	 */
	private function __construct() {}

	/**
	 * Register WordPress hooks for the component lifecycle.
	 *
	 * @return void
	 */
	private function init() {
		// Capture pipeline and REST routes register on 'init'.
		add_action( 'init', [ $this, 'register_runtime' ] );

		// Admin menu registers on 'admin_menu'.
		add_action( 'admin_menu', [ $this, 'register_admin' ] );
	}

	/**
	 * Wire the runtime (capture + REST) components.
	 *
	 * Runs on 'init'. The Gatekeeper hooks the prevent_prompt filter (observe
	 * only). The REST controller registers its own routes (it is expected to
	 * hook rest_api_init internally).
	 *
	 * @return void
	 */
	public function register_runtime() {
		if ( null === $this->gatekeeper && class_exists( '\\WP_AI_Rate_Limiter\\Capture\\Gatekeeper' ) ) {
			$this->gatekeeper = new \WP_AI_Rate_Limiter\Capture\Gatekeeper();

			if ( method_exists( $this->gatekeeper, 'register' ) ) {
				$this->gatekeeper->register();
			}
		}

		if ( null === $this->rest_controller && class_exists( '\\WP_AI_Rate_Limiter\\Admin\\Rest_Controller' ) ) {
			$this->rest_controller = new \WP_AI_Rate_Limiter\Admin\Rest_Controller();

			if ( method_exists( $this->rest_controller, 'register' ) ) {
				$this->rest_controller->register();
			}
		}

		// Threshold alerts (Phase 2): watch recorded usage for limit crossings.
		if ( null === $this->threshold_watcher && class_exists( '\\WP_AI_Rate_Limiter\\Alerts\\Threshold_Watcher' ) ) {
			$this->threshold_watcher = new \WP_AI_Rate_Limiter\Alerts\Threshold_Watcher();

			if ( method_exists( $this->threshold_watcher, 'register' ) ) {
				$this->threshold_watcher->register();
			}
		}
	}

	/**
	 * Wire the admin settings page.
	 *
	 * Runs on 'admin_menu'.
	 *
	 * @return void
	 */
	public function register_admin() {
		if ( null === $this->settings_page && class_exists( '\\WP_AI_Rate_Limiter\\Admin\\Settings_Page' ) ) {
			$this->settings_page = new \WP_AI_Rate_Limiter\Admin\Settings_Page();

			if ( method_exists( $this->settings_page, 'register' ) ) {
				$this->settings_page->register();
			}
		}
	}

	/**
	 * Access the Gatekeeper instance (may be null before 'init').
	 *
	 * @return \WP_AI_Rate_Limiter\Capture\Gatekeeper|null
	 */
	public function gatekeeper() {
		return $this->gatekeeper;
	}
}
