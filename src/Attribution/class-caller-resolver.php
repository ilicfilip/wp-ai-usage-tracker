<?php
/**
 * Caller (plugin/theme) and user attribution resolver.
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which plugin/theme and which user triggered an AI Client prompt.
 *
 * Layered, most-reliable first (spec §4 / Architecture §5):
 *   1. Self-ID hook  (high)   — cooperating plugins call
 *                               do_action( 'wp_ai_rate_limiter_attribute', 'slug' )
 *                               before their prompt; we read the top of a
 *                               request-scoped stack.
 *   2. Backtrace     (medium) — walk debug_backtrace() and map the first frame
 *                               under wp-content/plugins/<slug>/ or themes/<slug>/
 *                               to that slug. Cached per call site.
 *   3. Unknown       (low)    — '__unknown__'. Still tracked, never dropped.
 *
 * Stateless aside from two request-scoped static caches; safe to instantiate
 * freely.
 */
class Caller_Resolver {

	/**
	 * Attribution action other plugins call to self-identify.
	 */
	const ATTRIBUTE_ACTION = 'wp_ai_rate_limiter_attribute';

	/**
	 * Slug used when no caller can be resolved.
	 */
	const UNKNOWN_SLUG = '__unknown__';

	/**
	 * Scope key used when there is no logged-in user (cron/REST/system).
	 */
	const SYSTEM_USER_KEY = '__system__';

	/**
	 * Maximum number of backtrace frames to inspect.
	 */
	const BACKTRACE_DEPTH = 30;

	/**
	 * Request-scoped self-identification stack of plugin slugs.
	 *
	 * The most recent (top) entry is the active caller. Shared across instances
	 * within a request because attribution is a request-global concern.
	 *
	 * @var string[]
	 */
	private static $self_id_stack = [];

	/**
	 * Memo cache mapping "file:line" call sites to a resolved slug array.
	 *
	 * Avoids re-walking the filesystem path for repeated calls from the same
	 * code location during a request.
	 *
	 * @var array<string,array{source_slug:string,source_type:string}>
	 */
	private static $call_site_cache = [];

	/**
	 * Whether the self-ID action listener has been registered this request.
	 *
	 * @var bool
	 */
	private static $listener_registered = false;

	/**
	 * Register the self-ID action listener.
	 *
	 * Cooperating plugins fire do_action( 'wp_ai_rate_limiter_attribute', 'slug' )
	 * immediately before their prompt; we push the slug onto the stack. The
	 * Gatekeeper pops/reads it when the prompt fires. Idempotent.
	 *
	 * @return void
	 */
	public function register() {
		if ( self::$listener_registered ) {
			return;
		}

		add_action( self::ATTRIBUTE_ACTION, [ $this, 'on_attribute_action' ], 10, 1 );
		self::$listener_registered = true;
	}

	/**
	 * Action callback: push a self-identified slug onto the stack.
	 *
	 * @param string $slug Plugin/theme slug supplied by the caller.
	 * @return void
	 */
	public function on_attribute_action( $slug ) {
		$this->push_slug( $slug );
	}

	/**
	 * Push a self-identified slug onto the request-scoped stack.
	 *
	 * The slug is sanitised to the same shape WordPress uses for directory
	 * slugs so it joins cleanly with backtrace-derived slugs downstream.
	 *
	 * @param string $slug Raw slug from the caller.
	 * @return void
	 */
	public function push_slug( $slug ) {
		$clean = sanitize_key( (string) $slug );

		if ( '' === $clean ) {
			return;
		}

		self::$self_id_stack[] = $clean;
	}

	/**
	 * Pop the most recent self-identified slug off the stack.
	 *
	 * @return string|null The popped slug, or null when the stack is empty.
	 */
	public function pop_slug() {
		return array_pop( self::$self_id_stack );
	}

	/**
	 * Read the current (top) self-identified slug without removing it.
	 *
	 * @return string|null The top slug, or null when none is set.
	 */
	public function current_slug() {
		$count = count( self::$self_id_stack );

		if ( 0 === $count ) {
			return null;
		}

		return self::$self_id_stack[ $count - 1 ];
	}

	/**
	 * Resolve the caller for the current prompt.
	 *
	 * @return array{source_slug:string,source_type:string,confidence:string}
	 *               source_type is 'plugin'|'theme'|'unknown';
	 *               confidence is 'high'|'medium'|'low'.
	 */
	public function resolve() {
		// 1. Self-ID hook (high confidence).
		$self = $this->current_slug();

		if ( null !== $self ) {
			return [
				'source_slug' => $self,
				'source_type' => 'plugin',
				'confidence'  => 'high',
			];
		}

		// 2. Backtrace path-mapping (medium confidence).
		$from_trace = $this->resolve_from_backtrace();

		if ( null !== $from_trace ) {
			return [
				'source_slug' => $from_trace['source_slug'],
				'source_type' => $from_trace['source_type'],
				'confidence'  => 'medium',
			];
		}

		// 3. Unknown bucket (low confidence).
		return [
			'source_slug' => self::UNKNOWN_SLUG,
			'source_type' => 'unknown',
			'confidence'  => 'low',
		];
	}

	/**
	 * Resolve the current user and their primary role.
	 *
	 * Falls back to a system bucket for cron/REST/CLI contexts that have no
	 * logged-in user.
	 *
	 * @return array{user_id:int,user_role:string}
	 */
	public function resolve_user() {
		$user_id = (int) get_current_user_id();

		if ( 0 === $user_id ) {
			return [
				'user_id'   => 0,
				'user_role' => 'system',
			];
		}

		$role = '';
		$user = get_userdata( $user_id );

		if ( $user && ! empty( $user->roles ) && is_array( $user->roles ) ) {
			// Primary role = first assigned role.
			$role = (string) reset( $user->roles );
		}

		return [
			'user_id'   => $user_id,
			'user_role' => $role,
		];
	}

	/**
	 * Walk the backtrace to find the first frame inside a plugin or theme dir.
	 *
	 * Frames inside WordPress core and inside this plugin's own directory are
	 * skipped. Results are memoised per call site (file:line) for the request.
	 *
	 * @return array{source_slug:string,source_type:string}|null
	 *               Resolved slug array, or null when nothing matched.
	 */
	private function resolve_from_backtrace() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- intentional, args ignored, depth capped.
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_DEPTH );

		$plugins_dir = $this->normalize_path( $this->plugins_base_dir() );
		$themes_dir  = $this->normalize_path( $this->themes_base_dir() );
		$own_dir     = $this->normalize_path( WP_AI_RATE_LIMITER_PLUGIN_DIR );
		$core_dir    = $this->normalize_path( ABSPATH . WPINC );

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = $this->normalize_path( $frame['file'] );
			$line = isset( $frame['line'] ) ? (int) $frame['line'] : 0;
			$site = $file . ':' . $line;

			// Serve from the per-call-site memo when present.
			if ( array_key_exists( $site, self::$call_site_cache ) ) {
				$cached = self::$call_site_cache[ $site ];

				if ( null !== $cached ) {
					return $cached;
				}

				continue;
			}

			// Skip our own frames and obvious core frames.
			if ( '' !== $own_dir && $this->path_starts_with( $file, $own_dir ) ) {
				self::$call_site_cache[ $site ] = null;
				continue;
			}

			if ( '' !== $core_dir && $this->path_starts_with( $file, $core_dir ) ) {
				self::$call_site_cache[ $site ] = null;
				continue;
			}

			// Plugin frame?
			$slug = $this->slug_under_base( $file, $plugins_dir );

			if ( null !== $slug ) {
				$resolved = [
					'source_slug' => $slug,
					'source_type' => 'plugin',
				];

				self::$call_site_cache[ $site ] = $resolved;
				return $resolved;
			}

			// Theme frame?
			$slug = $this->slug_under_base( $file, $themes_dir );

			if ( null !== $slug ) {
				$resolved = [
					'source_slug' => $slug,
					'source_type' => 'theme',
				];

				self::$call_site_cache[ $site ] = $resolved;
				return $resolved;
			}

			// Frame is somewhere uninteresting (e.g. mu-plugins outside our map).
			self::$call_site_cache[ $site ] = null;
		}

		return null;
	}

	/**
	 * Extract the immediate directory slug for a file living under a base dir.
	 *
	 * For base "/x/wp-content/plugins/" and file
	 * "/x/wp-content/plugins/my-plugin/inc/foo.php" this returns "my-plugin".
	 *
	 * @param string $file Normalised absolute file path.
	 * @param string $base Normalised absolute base directory (no trailing slash).
	 * @return string|null Slug, or null when the file is not under the base.
	 */
	private function slug_under_base( $file, $base ) {
		if ( '' === $base ) {
			return null;
		}

		$base_with_slash = $base . '/';

		if ( ! $this->path_starts_with( $file, $base_with_slash ) ) {
			return null;
		}

		$remainder = substr( $file, strlen( $base_with_slash ) );
		$remainder = ltrim( $remainder, '/' );

		if ( '' === $remainder ) {
			return null;
		}

		$parts = explode( '/', $remainder );
		$slug  = sanitize_key( $parts[0] );

		return '' === $slug ? null : $slug;
	}

	/**
	 * Base directory for plugins, normalised later by the caller.
	 *
	 * @return string
	 */
	private function plugins_base_dir() {
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			return WP_PLUGIN_DIR;
		}

		return WP_CONTENT_DIR . '/plugins';
	}

	/**
	 * Base directory for themes.
	 *
	 * Uses get_theme_root() when available (handles custom theme roots), with a
	 * constant-based fallback for very early contexts.
	 *
	 * @return string
	 */
	private function themes_base_dir() {
		if ( function_exists( 'get_theme_root' ) ) {
			$root = get_theme_root();

			if ( is_string( $root ) && '' !== $root ) {
				return $root;
			}
		}

		return WP_CONTENT_DIR . '/themes';
	}

	/**
	 * Normalise a filesystem path: forward slashes, no trailing slash.
	 *
	 * @param string $path Raw path.
	 * @return string Normalised path ('' for empty input).
	 */
	private function normalize_path( $path ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return '';
		}

		if ( function_exists( 'wp_normalize_path' ) ) {
			$path = wp_normalize_path( $path );
		} else {
			$path = str_replace( '\\', '/', $path );
		}

		return rtrim( $path, '/' );
	}

	/**
	 * Case-aware prefix test for normalised paths.
	 *
	 * @param string $haystack Full path.
	 * @param string $needle   Prefix to test.
	 * @return bool True when $haystack starts with $needle.
	 */
	private function path_starts_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return false;
		}

		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}
