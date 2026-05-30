<?php
/**
 * REST API controller for the AI Usage dashboard.
 *
 * @package WP_AI_Rate_Limiter
 */

namespace WP_AI_Rate_Limiter\Admin;

use WP_AI_Rate_Limiter\Accounting\Cost_Calculator;
use WP_AI_Rate_Limiter\Data\Schema;
use WP_AI_Rate_Limiter\Data\Usage_Repository;
use WP_AI_Rate_Limiter\Periods\Window;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the 'wp-ai-rate-limiter/v1' namespace and its read/write routes
 * (spec §6).
 *
 * All routes are guarded by a 'manage_options'-style permission callback whose
 * capability is filterable via 'wp_ai_rate_limiter_capability'. Reads delegate
 * to Usage_Repository; pricing delegates to Cost_Calculator. Every argument is
 * validated and sanitized; every response goes through rest_ensure_response().
 */
class Rest_Controller {

	/**
	 * REST namespace for all dashboard routes.
	 */
	const NAMESPACE = 'wp-ai-rate-limiter/v1';

	/**
	 * Filter controlling the required capability.
	 */
	const CAPABILITY_FILTER = 'wp_ai_rate_limiter_capability';

	/**
	 * Hook the route registration onto rest_api_init.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all Phase 1 routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/usage',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_usage' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'scope_type' => $this->arg_scope_type(),
						'period'     => $this->arg_period(),
						'period_key' => $this->arg_period_key(),
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/timeseries',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_timeseries' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'metric'     => [
							'type'              => 'string',
							'default'           => 'cost',
							'enum'              => [ 'cost', 'tokens' ],
							'sanitize_callback' => 'sanitize_key',
						],
						'scope_type' => [
							'type'              => 'string',
							'required'          => false,
							'enum'              => [ 'plugin', 'user', 'role' ],
							'sanitize_callback' => 'sanitize_key',
						],
						'scope_key'  => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'from'       => $this->arg_date( false ),
						'to'         => $this->arg_date( false ),
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/totals',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_totals' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'period'     => $this->arg_period(),
						'period_key' => $this->arg_period_key(),
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/pricing',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_pricing' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_pricing' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'pricing' => [
							'type'        => 'object',
							'required'    => true,
							'description' => __( 'Pricing table keyed by "provider/model".', 'wp-ai-rate-limiter' ),
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/scopes',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_scopes' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		// Limits collection: list + create (Phase 2).
		register_rest_route(
			self::NAMESPACE,
			'/limits',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_limits' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_limit' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->limit_args(),
				],
			]
		);

		// Single limit: update + delete (Phase 2).
		register_rest_route(
			self::NAMESPACE,
			'/limits/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_limit' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => $this->limit_args(),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_limit' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	/**
	 * Permission callback: require the (filterable) management capability.
	 *
	 * @return bool
	 */
	public function check_permission() {
		/**
		 * Filter the capability required to access the usage REST API.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = apply_filters( self::CAPABILITY_FILTER, 'manage_options' );

		return current_user_can( $capability );
	}

	/**
	 * GET /usage — ranked counters for a scope type and period.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_usage( WP_REST_Request $request ) {
		$scope_type = $request->get_param( 'scope_type' );
		$kind       = $request->get_param( 'period' );
		$period_key = $this->resolve_period_key( $kind, $request->get_param( 'period_key' ) );

		$rows = [];
		if ( class_exists( '\\WP_AI_Rate_Limiter\\Data\\Usage_Repository' ) ) {
			$rows = Usage_Repository::ranked_by_scope( $scope_type, $kind, $period_key );
		}

		if ( 'user' === $scope_type ) {
			$rows = $this->enrich_user_rows( $rows );
		} elseif ( 'role' === $scope_type ) {
			$rows = $this->enrich_role_rows( $rows );
		}

		return rest_ensure_response(
			[
				'scope_type' => $scope_type,
				'period'     => $kind,
				'period_key' => $period_key,
				'rows'       => $rows,
			]
		);
	}

	/**
	 * Add display_name + profile edit_url to user-scoped usage rows.
	 *
	 * The counters table only stores the numeric user id; the dashboard wants a
	 * human label linked to the profile. Reserved buckets (user 0 / the system
	 * key) are left without a name so the UI falls back to its generic label.
	 *
	 * @param array<int,array<string,mixed>> $rows User-scope usage rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function enrich_user_rows( array $rows ) {
		foreach ( $rows as &$row ) {
			$key     = isset( $row['scope_key'] ) ? (string) $row['scope_key'] : '';
			$user_id = is_numeric( $key ) ? (int) $key : 0;

			if ( $user_id < 1 ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$row['display_name'] = $user->display_name;
			$row['user_login']   = $user->user_login;

			if ( current_user_can( 'edit_user', $user_id ) ) {
				$row['edit_url'] = esc_url_raw( get_edit_user_link( $user_id ) );
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Add a human role label + users-list link to role-scoped usage rows.
	 *
	 * A role isn't a single profile, so we link to the Users screen filtered by
	 * that role. The reserved 'system' bucket (cron/REST/CLI) is left unlinked so
	 * the UI shows its generic label.
	 *
	 * @param array<int,array<string,mixed>> $rows Role-scope usage rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function enrich_role_rows( array $rows ) {
		$names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : [];
		$can   = current_user_can( 'list_users' );

		foreach ( $rows as &$row ) {
			$role = isset( $row['scope_key'] ) ? (string) $row['scope_key'] : '';

			if ( '' === $role || 'system' === $role ) {
				continue;
			}

			if ( isset( $names[ $role ] ) ) {
				$row['role_label'] = translate_user_role( $names[ $role ] );
			}

			if ( $can ) {
				$row['list_url'] = esc_url_raw(
					add_query_arg( 'role', $role, admin_url( 'users.php' ) )
				);
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * GET /timeseries — daily buckets for the over-time chart.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_timeseries( WP_REST_Request $request ) {
		$metric     = $request->get_param( 'metric' );
		$scope_type = $request->get_param( 'scope_type' );
		$scope_key  = $request->get_param( 'scope_key' );

		// Default the window to the current calendar month when unspecified.
		$from = $request->get_param( 'from' );
		$to   = $request->get_param( 'to' );

		if ( empty( $from ) || empty( $to ) ) {
			$range = Window::range( Window::KIND_MONTH, Window::current_period_key( Window::KIND_MONTH ) );
			if ( null !== $range ) {
				$from = empty( $from ) ? $range['from']->format( 'Y-m-d H:i:s' ) : $this->expand_date( $from, false );
				$to   = empty( $to ) ? $range['to']->format( 'Y-m-d H:i:s' ) : $this->expand_date( $to, true );
			}
		} else {
			$from = $this->expand_date( $from, false );
			$to   = $this->expand_date( $to, true );
		}

		$series = [];
		if ( class_exists( '\\WP_AI_Rate_Limiter\\Data\\Usage_Repository' ) ) {
			$series = Usage_Repository::timeseries( $metric, $from, $to, $scope_type, $scope_key );
		}

		return rest_ensure_response(
			[
				'metric' => $metric,
				'from'   => $from,
				'to'     => $to,
				'series' => $series,
			]
		);
	}

	/**
	 * GET /totals — top-line totals plus provider/model breakdown.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_totals( WP_REST_Request $request ) {
		$kind       = $request->get_param( 'period' );
		$period_key = $this->resolve_period_key( $kind, $request->get_param( 'period_key' ) );

		$data = [
			'totals'      => [],
			'by_provider' => [],
			'by_model'    => [],
		];

		if ( class_exists( '\\WP_AI_Rate_Limiter\\Data\\Usage_Repository' ) ) {
			$data = Usage_Repository::totals( $kind, $period_key );
		}

		$data['period']     = $kind;
		$data['period_key'] = $period_key;

		return rest_ensure_response( $data );
	}

	/**
	 * GET /pricing — current pricing table.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_pricing() {
		$pricing = class_exists( '\\WP_AI_Rate_Limiter\\Accounting\\Cost_Calculator' )
			? Cost_Calculator::get_pricing()
			: [];

		return rest_ensure_response( [ 'pricing' => $pricing ] );
	}

	/**
	 * PUT /pricing — persist an admin-supplied pricing table.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update_pricing( WP_REST_Request $request ) {
		$pricing = $request->get_param( 'pricing' );

		if ( ! is_array( $pricing ) ) {
			return new WP_Error(
				'aiut_invalid_pricing',
				__( 'The pricing table must be an object keyed by "provider/model".', 'wp-ai-rate-limiter' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! class_exists( '\\WP_AI_Rate_Limiter\\Accounting\\Cost_Calculator' ) ) {
			return new WP_Error(
				'aiut_unavailable',
				__( 'Pricing storage is unavailable.', 'wp-ai-rate-limiter' ),
				[ 'status' => 500 ]
			);
		}

		Cost_Calculator::update_pricing( $pricing );

		return rest_ensure_response( [ 'pricing' => Cost_Calculator::get_pricing() ] );
	}

	/**
	 * GET /scopes — discovered plugin slugs and roles for filter dropdowns.
	 *
	 * Plugin slugs are read distinct from the counters table; roles come from
	 * the live role list so the UI can offer them even before any usage.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_scopes() {
		global $wpdb;

		$plugins = [];
		$models  = [];

		if ( class_exists( '\\WP_AI_Rate_Limiter\\Data\\Schema' ) ) {
			$counters = Schema::counters_table();

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
			$plugins = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT scope_key FROM {$counters} WHERE scope_type = %s ORDER BY scope_key ASC",
					'plugin'
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
			$models = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT scope_key FROM {$counters} WHERE scope_type = %s ORDER BY scope_key ASC",
					'model'
				)
			);
		}

		$roles = [];
		if ( function_exists( 'wp_roles' ) ) {
			$roles = array_keys( wp_roles()->get_names() );
		}

		return rest_ensure_response(
			[
				'plugins' => is_array( $plugins ) ? array_values( $plugins ) : [],
				'roles'   => $roles,
				'models'  => is_array( $models ) ? array_values( $models ) : [],
			]
		);
	}

	/**
	 * GET /limits — list all configured limits.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_limits() {
		$repo = new \WP_AI_Rate_Limiter\Limits\Limit_Repository();

		return rest_ensure_response( [ 'limits' => $repo->all() ] );
	}

	/**
	 * POST /limits — create a limit.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_limit( WP_REST_Request $request ) {
		$repo = new \WP_AI_Rate_Limiter\Limits\Limit_Repository();
		$id   = $repo->save( $this->limit_payload( $request ) );

		if ( false === $id ) {
			return new \WP_Error(
				'aiut_limit_save_failed',
				__( 'Could not save the limit.', 'wp-ai-rate-limiter' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [ 'limit' => $repo->find( $id ) ] );
	}

	/**
	 * PUT /limits/{id} — update a limit.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_limit( WP_REST_Request $request ) {
		$repo = new \WP_AI_Rate_Limiter\Limits\Limit_Repository();
		$id   = (int) $request['id'];

		if ( null === $repo->find( $id ) ) {
			return new \WP_Error(
				'aiut_limit_not_found',
				__( 'Limit not found.', 'wp-ai-rate-limiter' ),
				[ 'status' => 404 ]
			);
		}

		$payload       = $this->limit_payload( $request );
		$payload['id'] = $id;
		$saved         = $repo->save( $payload );

		if ( false === $saved ) {
			return new \WP_Error(
				'aiut_limit_save_failed',
				__( 'Could not update the limit.', 'wp-ai-rate-limiter' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [ 'limit' => $repo->find( $id ) ] );
	}

	/**
	 * DELETE /limits/{id} — delete a limit.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function delete_limit( WP_REST_Request $request ) {
		$repo = new \WP_AI_Rate_Limiter\Limits\Limit_Repository();
		$ok   = $repo->delete( (int) $request['id'] );

		return rest_ensure_response( [ 'deleted' => $ok ] );
	}

	/**
	 * Extract a sanitised limit payload from a request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array<string,mixed>
	 */
	private function limit_payload( WP_REST_Request $request ) {
		return [
			'scope_type'     => (string) $request['scope_type'],
			'scope_key'      => (string) $request['scope_key'],
			'limit_type'     => (string) $request['limit_type'],
			'period_kind'    => (string) $request['period_kind'],
			'threshold'      => (int) $request['threshold'],
			'enforcement'    => (string) $request['enforcement'],
			'min_confidence' => (string) $request['min_confidence'],
			'alert_80'       => (bool) $request['alert_80'],
			'alert_100'      => (bool) $request['alert_100'],
			'enabled'        => (bool) $request['enabled'],
		];
	}

	/**
	 * Argument schema shared by create/update limit routes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function limit_args() {
		return [
			'scope_type'     => [
				'type'              => 'string',
				'required'          => true,
				'enum'              => [ 'plugin', 'user', 'role', 'model', 'global' ],
				'sanitize_callback' => 'sanitize_key',
			],
			'scope_key'      => [
				'type'              => 'string',
				'default'           => '*',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'limit_type'     => [
				'type'              => 'string',
				'required'          => true,
				'enum'              => [ 'requests', 'tokens', 'cost' ],
				'sanitize_callback' => 'sanitize_key',
			],
			'period_kind'    => [
				'type'              => 'string',
				'default'           => 'month',
				'enum'              => [ 'day', 'month' ],
				'sanitize_callback' => 'sanitize_key',
			],
			'threshold'      => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 0,
			],
			'enforcement'    => [
				'type'              => 'string',
				'default'           => 'soft',
				'enum'              => [ 'off', 'soft', 'hard' ],
				'sanitize_callback' => 'sanitize_key',
			],
			'min_confidence' => [
				'type'              => 'string',
				'default'           => 'medium',
				'enum'              => [ 'high', 'medium' ],
				'sanitize_callback' => 'sanitize_key',
			],
			'alert_80'       => [
				'type'    => 'boolean',
				'default' => true,
			],
			'alert_100'      => [
				'type'    => 'boolean',
				'default' => true,
			],
			'enabled'        => [
				'type'    => 'boolean',
				'default' => true,
			],
		];
	}

	/**
	 * Argument schema for a scope_type parameter.
	 *
	 * @return array<string, mixed>
	 */
	private function arg_scope_type() {
		return [
			'type'              => 'string',
			'default'           => 'plugin',
			'enum'              => [ 'plugin', 'user', 'role', 'model', 'global' ],
			'sanitize_callback' => 'sanitize_key',
		];
	}

	/**
	 * Argument schema for a period (kind) parameter.
	 *
	 * @return array<string, mixed>
	 */
	private function arg_period() {
		return [
			'type'              => 'string',
			'default'           => Window::KIND_MONTH,
			'enum'              => [ Window::KIND_DAY, Window::KIND_MONTH ],
			'sanitize_callback' => 'sanitize_key',
		];
	}

	/**
	 * Argument schema for an explicit period_key parameter.
	 *
	 * @return array<string, mixed>
	 */
	private function arg_period_key() {
		return [
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $value ) {
				return '' === $value || (bool) preg_match( '/^\d{4}-\d{2}(-\d{2})?$/', (string) $value );
			},
		];
	}

	/**
	 * Argument schema for a date parameter ('Y-m-d' or 'Y-m-d H:i:s').
	 *
	 * @param bool $required Whether the parameter is required.
	 * @return array<string, mixed>
	 */
	private function arg_date( $required = false ) {
		return [
			'type'              => 'string',
			'required'          => (bool) $required,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $value ) {
				if ( '' === $value || null === $value ) {
					return true;
				}
				return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', (string) $value );
			},
		];
	}

	/**
	 * Resolve the effective period key: explicit value or current period.
	 *
	 * @param string      $kind       'day'|'month'.
	 * @param string|null $period_key Optional explicit key.
	 * @return string
	 */
	private function resolve_period_key( $kind, $period_key ) {
		if ( is_string( $period_key ) && '' !== $period_key ) {
			// Guard the key shape against the kind to avoid empty result sets.
			$is_month = (bool) preg_match( '/^\d{4}-\d{2}$/', $period_key );
			$is_day   = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_key );

			if ( ( Window::KIND_MONTH === $kind && $is_month ) || ( Window::KIND_DAY === $kind && $is_day ) ) {
				return $period_key;
			}
		}

		return Window::current_period_key( $kind );
	}

	/**
	 * Expand a date-only value to a full datetime bound.
	 *
	 * A bare 'Y-m-d' becomes the start of that day, or — for an exclusive upper
	 * bound — the start of the following day so the range stays half-open.
	 *
	 * @param string $value     Date or datetime string.
	 * @param bool   $exclusive Whether this is the exclusive upper bound.
	 * @return string Datetime string 'Y-m-d H:i:s'.
	 */
	private function expand_date( $value, $exclusive ) {
		$value = (string) $value;

		// Already a full datetime.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			if ( $exclusive ) {
				$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value . ' 00:00:00', wp_timezone() );
				if ( false !== $dt ) {
					return $dt->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
				}
			}
			return $value . ' 00:00:00';
		}

		// Fallback: current month boundary.
		$range = Window::range( Window::KIND_MONTH, Window::current_period_key( Window::KIND_MONTH ) );
		if ( null !== $range ) {
			return $exclusive ? $range['to']->format( 'Y-m-d H:i:s' ) : $range['from']->format( 'Y-m-d H:i:s' );
		}

		return $value;
	}
}
