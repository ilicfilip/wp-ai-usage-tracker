<?php
/**
 * Admin settings page: Tools -> AI Usage.
 *
 * @package WP_AIUT
 */

namespace WP_AIUT\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "AI Usage" admin page under the Tools menu and enqueues the
 * compiled React dashboard (spec §7).
 *
 * The page itself is a thin shell: render() prints a single root element and
 * enqueue_assets() loads the @wordpress/scripts build (build/index.js +
 * build/index.asset.php deps + build/style-index.css). A small config object carrying
 * the REST root and a 'wp_rest' nonce is handed to the bundle via an inline
 * script so the dashboard can talk to the 'wp-aiut/v1' namespace.
 *
 * Assets only load on our own screen — never site-wide.
 */
class Settings_Page {

	/**
	 * Admin page slug (the ?page= value).
	 */
	const PAGE_SLUG = 'wp-ai-usage';

	/**
	 * Capability required to view the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Script/style handle for the compiled dashboard bundle.
	 */
	const ASSET_HANDLE = 'wp-aiut-dashboard';

	/**
	 * The hook suffix returned by add_submenu_page(), used to gate enqueues.
	 *
	 * @var string|null
	 */
	private $hook_suffix = null;

	/**
	 * Register WordPress hooks for the page lifecycle.
	 *
	 * Called by the Plugin wiring on 'admin_menu'. Because we are already inside
	 * the 'admin_menu' action when register() runs, add_menu() is invoked
	 * directly; the asset enqueue is deferred to 'admin_enqueue_scripts'.
	 *
	 * @return void
	 */
	public function register() {
		$this->add_menu();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the "AI Usage" submenu under Tools.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook_suffix = add_submenu_page(
			'tools.php',
			__( 'AI Usage', 'wp-aiut' ),
			__( 'AI Usage', 'wp-aiut' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the page shell.
	 *
	 * Prints a heading and the root element the React app mounts into. All real
	 * UI is rendered client-side from the enqueued bundle.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		printf(
			'<div class="wrap"><h1>%s</h1><div id="wp-aiut-root"></div></div>',
			esc_html__( 'AI Usage', 'wp-aiut' )
		);
	}

	/**
	 * Enqueue the compiled dashboard assets, only on our screen.
	 *
	 * Reads the @wordpress/scripts-generated asset manifest (build/index.asset.php)
	 * for the dependency array and version hash, enqueues build/index.js and
	 * build/style-index.css, and injects the runtime config (REST root + nonce) as an
	 * inline script that runs before the bundle.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Only load on our own page.
		if ( null === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$build_dir = WP_AIUT_PLUGIN_DIR . 'build/';
		$build_url = WP_AIUT_PLUGIN_URL . 'build/';

		$script_path = $build_dir . 'index.js';
		$asset_path  = $build_dir . 'index.asset.php';
		// wp-scripts emits the extracted stylesheet as style-index.css (not index.css).
		$style_path = $build_dir . 'style-index.css';

		// Without a build, there is nothing to enqueue; show a notice instead.
		if ( ! file_exists( $script_path ) ) {
			add_action( 'admin_notices', [ $this, 'render_missing_build_notice' ] );
			return;
		}

		// Pull deps + version from the generated manifest when present.
		$asset = [
			'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ],
			'version'      => WP_AIUT_VERSION,
		];

		if ( file_exists( $asset_path ) ) {
			$manifest = include $asset_path;
			if ( is_array( $manifest ) ) {
				$asset = array_merge( $asset, $manifest );
			}
		}

		wp_enqueue_script(
			self::ASSET_HANDLE,
			$build_url . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				self::ASSET_HANDLE,
				$build_url . 'style-index.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		$config = [
			'restRoot'  => esc_url_raw( rest_url( 'wp-aiut/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'currency'  => 'USD',
			'attribute' => 'wp_aiut_attribute',
		];

		// Hand the config to the bundle before it runs.
		wp_add_inline_script(
			self::ASSET_HANDLE,
			'window.wpAiUsageTracker = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		// Allow translated strings inside the JS bundle.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::ASSET_HANDLE, 'wp-aiut' );
		}
	}

	/**
	 * Admin notice shown when the React build is missing.
	 *
	 * @return void
	 */
	public function render_missing_build_notice() {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'AI Usage Tracker: the dashboard assets have not been built yet. Run "npm install && npm run build" in the plugin directory.', 'wp-aiut' )
		);
	}
}
