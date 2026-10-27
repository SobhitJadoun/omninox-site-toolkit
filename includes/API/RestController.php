<?php
/**
 * REST API surface. Everything in the admin UI goes through here.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\API;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\ModuleRegistry;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\Database\ScanRepository;
use OmninoxSiteToolkit\Database\LogRepository;
use OmninoxSiteToolkit\Services\ScoreCalculator;
use OmninoxSiteToolkit\License\LicenseManager;
use OmninoxSiteToolkit\Reports\ReportGenerator;
use OmninoxSiteToolkit\Notifications\Mailer;
use OmninoxSiteToolkit\Services\ValueHistory;
use OmninoxSiteToolkit\Services\Security;
use OmninoxSiteToolkit\Services\Explainer;

defined( 'ABSPATH' ) || exit;

class RestController {

	use Singleton;

	const NS = 'omninox-site-toolkit/v1';

	/**
	 * Hook up.
	 */
	public function boot() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_post_omninox_download', array( $this, 'admin_post_download' ) );
	}


	/**
	 * Permission callback for administrative routes.
	 * Strictly protected verifying manage_options / administrator capabilities.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_manage() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'omninox_forbidden',
			__( 'You do not have permission to manage this site toolkit.', 'omninox-site-toolkit' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Permission callback for /fix/{module} routes.
	 * Requires manage_options administrator capability, OR a valid cryptographically
	 * verified restore continuation token for approved restore operations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_manage_or_continue_restore( \WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$route      = (string) $request->get_route();
		$module_key = sanitize_key( (string) $request['module'] );
		$action     = sanitize_key( (string) $request->get_param( 'action' ) );
		$allowed    = array( 'restore_full', 'restore_database', 'restore_status', 'restore_cancel', 'restore_rollback' );

		$is_restore_route = ( 'backup' === $module_key && in_array( $action, $allowed, true ) )
			|| false !== strpos( $route, '/restore/' );

		if ( $is_restore_route ) {
			$token = $request->get_header( 'x_omninox_restore_token' );
			if ( empty( $token ) ) {
				$token = $request->get_header( 'X-Omninox-Restore-Token' );
			}
			if ( empty( $token ) ) {
				$params = (array) $request->get_param( 'params' );
				$token  = $params['continuation_token'] ?? $request->get_param( 'continuation_token' );
			}

			if ( ! empty( $token ) && is_string( $token ) ) {
				$backup_module = ModuleRegistry::instance()->get( 'backup' );
				if ( $backup_module && method_exists( $backup_module, 'verify_continuation_token' ) ) {
					$valid = $backup_module->verify_continuation_token( $token, $action );
					if ( true === $valid ) {
						return true;
					}
					if ( is_wp_error( $valid ) ) {
						return $valid;
					}
				}
			}
		}

		return new \WP_Error(
			'omninox_restore_auth_required',
			__( 'Administrator authentication or a valid restore continuation token is required.', 'omninox-site-toolkit' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Register routes.
	 */
	public function routes() {
		$auth       = array( $this, 'can_manage' );
		$namespaces = array( self::NS );

		foreach ( $namespaces as $ns ) {
			register_rest_route(
				$ns,
				'/overview',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'overview' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/modules',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'modules' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/scan/(?P<module>[a-z0-9\-_]+)',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'scan' ),
					'permission_callback' => $auth,
					'args'                => array(
						'module' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			);

			register_rest_route(
				$ns,
				'/results/(?P<module>[a-z0-9\-_]+)',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'results' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/fix/(?P<module>[a-z0-9\-_]+)',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fix' ),
					'permission_callback' => array( $this, 'can_manage_or_continue_restore' ),
				)
			);

			register_rest_route(
				$ns,
				'/settings',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_settings' ),
						'permission_callback' => $auth,
					),
					array(
						'methods'             => \WP_REST_Server::EDITABLE,
						'callback'            => array( $this, 'save_settings' ),
						'permission_callback' => $auth,
					),
				)
			);

			register_rest_route(
				$ns,
				'/history/(?P<module>[a-z0-9\-_]+)',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'history' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/logs',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'logs' ),
						'permission_callback' => $auth,
					),
					array(
						'methods'             => \WP_REST_Server::DELETABLE,
						'callback'            => array( $this, 'clear_logs' ),
						'permission_callback' => $auth,
					),
				)
			);

			register_rest_route(
				$ns,
				'/report',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'report' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/license',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'license' ),
						'permission_callback' => $auth,
					),
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'activate_license' ),
						'permission_callback' => $auth,
					),
					array(
						'methods'             => \WP_REST_Server::DELETABLE,
						'callback'            => array( $this, 'deactivate_license' ),
						'permission_callback' => $auth,
					),
				)
			);

			register_rest_route(
				$ns,
				'/changes',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( $this, 'changes' ),
						'permission_callback' => $auth,
					),
					array(
						'methods'             => \WP_REST_Server::DELETABLE,
						'callback'            => array( $this, 'clear_changes' ),
						'permission_callback' => $auth,
					),
				)
			);

			register_rest_route(
				$ns,
				'/dashboard',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'dashboard' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/quick/(?P<action>[a-z0-9_]+)',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'quick_action' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/download',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/tips',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'tips' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/updates',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'updates' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/search/test',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'search_test' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/explain',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'explain' ),
					'permission_callback' => $auth,
				)
			);

			// Mail delivery: status, and a test send that reports the real error.
			register_rest_route(
				$ns,
				'/mail/status',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'mail_status' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/mail/test',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mail_test' ),
					'permission_callback' => $auth,
				)
			);

		}
	}

	/**
	 * Dashboard payload.
	 *
	 * @return \WP_REST_Response
	 */
	public function overview() {
		$scores  = (array) get_option( 'omninox_last_scores', array() );
		$flat    = array();

		foreach ( $scores as $module => $data ) {
			$flat[ $module ] = (int) ( $data['score'] ?? 0 );
		}

		return rest_ensure_response(
			array(
				'site'     => array(
					'name'      => get_bloginfo( 'name' ),
					'url'       => home_url(),
					'wp'        => get_bloginfo( 'version' ),
					'php'       => PHP_VERSION,
					'multisite' => is_multisite(),
				),
				'overall'  => ScoreCalculator::overall( $flat ),
				'scores'   => $scores,
				'modules'  => ModuleRegistry::instance()->describe(),
				'license'  => LicenseManager::instance()->summary(),
			)
		);
	}

	/**
	 * Module descriptors.
	 *
	 * @return \WP_REST_Response
	 */
	public function modules() {
		return rest_ensure_response( ModuleRegistry::instance()->describe() );
	}

	/**
	 * Run a scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan( \WP_REST_Request $request ) {
		$module_key = sanitize_key( (string) $request['module'] );
		$module     = ModuleRegistry::instance()->get( $module_key );

		if ( ! $module ) {
			return new \WP_Error( 'omninox_unknown_module', __( 'That module is not available on this site.', 'omninox-site-toolkit' ), array( 'status' => 404 ) );
		}

		$args   = (array) $request->get_param( 'args' );
		$start  = microtime( true );

		try {
			$result = $module->scan( $args );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'omninox_scan_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'The scan hit an unexpected error and was stopped safely: %s', 'omninox-site-toolkit' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}

		$result['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

		return rest_ensure_response( $result );
	}

	/**
	 * Current mail delivery configuration and the last failure.
	 *
	 * @return \WP_REST_Response
	 */
	public function mail_status() {
		return rest_ensure_response( Mailer::instance()->status() );
	}

	/**
	 * Send a test email and report exactly why it failed if it did.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function mail_test( \WP_REST_Request $request ) {
		$to = sanitize_email( (string) $request->get_param( 'to' ) );
		return rest_ensure_response( Mailer::instance()->send_test( $to ) );
	}

	/**
	 * Last stored result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function results( \WP_REST_Request $request ) {
		$module_id = sanitize_key( (string) $request['module'] );

		if ( 'errors' === $module_id ) {
			$module = ModuleRegistry::instance()->get( $module_id );

			if ( $module ) {
				return rest_ensure_response( $module->scan( array( 'live' => true ) ) );
			}
		}

		return rest_ensure_response( ScanRepository::latest( $module_id ) );
	}

	/**
	 * Apply a fix.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function fix( \WP_REST_Request $request ) {
		$module_key = sanitize_key( (string) $request['module'] );
		$module     = ModuleRegistry::instance()->get( $module_key );

		if ( ! $module ) {
			return new \WP_Error( 'omninox_unknown_module', __( 'That module is not available on this site.', 'omninox-site-toolkit' ), array( 'status' => 404 ) );
		}

		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$params = (array) $request->get_param( 'params' );

		$token = $request->get_header( 'x_omninox_restore_token' );
		if ( empty( $token ) ) {
			$token = $request->get_header( 'X-Omninox-Restore-Token' );
		}
		if ( empty( $token ) ) {
			$token = $params['continuation_token'] ?? $request->get_param( 'continuation_token' );
		}

		$guard = Security::guard( $module->get_id() . '/' . $action, is_string( $token ) ? $token : '' );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		try {
			$result = $module->fix( $action, $params );
		} catch ( \Throwable $e ) {
			\OmninoxSiteToolkit\Database\LogRepository::record(
				array(
					'type'    => 'action',
					'level'   => 'error',
					'message' => sprintf(
						/* translators: 1: module, 2: action, 3: error */
						__( '%1$s: "%2$s" crashed — %3$s', 'omninox-site-toolkit' ),
						$module->get_label(),
						$action,
						$e->getMessage()
					),
					'source'  => $module->get_id() . '/' . $action,
				)
			);

			return new \WP_Error(
				'omninox_action_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'The action hit an unexpected error and was stopped safely: %s', 'omninox-site-toolkit' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		\OmninoxSiteToolkit\Database\LogRepository::record(
			array(
				'type'    => 'action',
				'level'   => 'info',
				'message' => sprintf(
					/* translators: 1: module, 2: action, 3: outcome */
					__( '%1$s: ran "%2$s" — %3$s', 'omninox-site-toolkit' ),
					$module->get_label(),
					$action,
					is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : __( 'completed', 'omninox-site-toolkit' )
				),
				'source'  => $module->get_id() . '/' . $action,
			)
		);

		do_action( 'omninox_fix_applied', $module->get_id(), $action, $params, $result );

		return rest_ensure_response( $result );
	}

	/**
	 * Read settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( Settings::instance()->get() );
	}

	/**
	 * Write settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ) {
		$changes = (array) $request->get_json_params();

		return rest_ensure_response( Settings::instance()->update( $changes ) );
	}

	/**
	 * Score history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function history( \WP_REST_Request $request ) {
		$days = absint( $request->get_param( 'days' ) ) ?: 30;

		return rest_ensure_response( ScanRepository::history( sanitize_key( (string) $request['module'] ), $days ) );
	}

	/**
	 * Error log list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function logs( \WP_REST_Request $request ) {
		return rest_ensure_response(
			LogRepository::query(
				array(
					'type'     => sanitize_key( (string) $request->get_param( 'type' ) ),
					'level'    => sanitize_key( (string) $request->get_param( 'level' ) ),
					'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'page'     => absint( $request->get_param( 'page' ) ) ?: 1,
					'per_page' => absint( $request->get_param( 'per_page' ) ) ?: 25,
				)
			)
		);
	}

	/**
	 * Clear logs.
	 *
	 * @return \WP_REST_Response
	 */
	public function clear_logs() {
		return rest_ensure_response( array( 'deleted' => LogRepository::clear() ) );
	}

	/**
	 * Build a report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function report( \WP_REST_Request $request ) {
		$format  = sanitize_key( (string) $request->get_param( 'format' ) ) ?: 'html';
		$modules = array_map( 'sanitize_key', (array) $request->get_param( 'modules' ) );

		return rest_ensure_response( ReportGenerator::generate( $format, $modules ) );
	}

	/**
	 * Licence status.
	 *
	 * @return \WP_REST_Response
	 */
	public function license() {
		return rest_ensure_response( LicenseManager::instance()->summary() );
	}

	/**
	 * Activate a licence key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate_license( \WP_REST_Request $request ) {
		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );

		return rest_ensure_response( LicenseManager::instance()->activate( $key ) );
	}

	/**
	 * Deactivate the licence.
	 *
	 * @return \WP_REST_Response
	 */
	public function deactivate_license() {
		return rest_ensure_response( LicenseManager::instance()->deactivate() );
	}

	/**
	 * Everything the dashboard widgets need, in one request.
	 *
	 * @return \WP_REST_Response
	 */
	public function dashboard() {
		return rest_ensure_response( \OmninoxSiteToolkit\Services\Dashboard::widgets() );
	}

	/**
	 * Run a dashboard quick action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function quick_action( \WP_REST_Request $request ) {
		$result = \OmninoxSiteToolkit\Services\Dashboard::run( sanitize_key( (string) $request['action'] ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Stream a file in exchange for a single-use token via REST API.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void|\WP_Error
	 */
	public function download( \WP_REST_Request $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		return $this->serve_download( $token );
	}

	/**
	 * Handle direct downloads via admin-post.php.
	 *
	 * Implements explicit sequential security gates:
	 * 1. Capability check (manage_options)
	 * 2. Nonce / origin verification (omninox_download action)
	 * 3. Token format validation
	 * 4. Token redemption, single-use, ownership and path safety check before streaming
	 */
	public function admin_post_download() {
		// Gate 1: Capability authorization (must never depend solely on nonce).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Unauthorized request.', 'omninox-site-toolkit' ),
				'',
				array( 'response' => 403 )
			);
		}

		// Gate 2: Nonce / origin verification with dedicated action 'omninox_download'.
		$nonce = '';
		if ( ! empty( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		} elseif ( ! empty( $_REQUEST['amp;_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['amp;_wpnonce'] ) );
		}

		// Gate 3: Read and validate token format.
		$token = '';
		if ( ! empty( $_GET['token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		} elseif ( ! empty( $_GET['amp;token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['amp;token'] ) );
		}

		$nonce_valid = ! empty( $nonce ) && wp_verify_nonce( $nonce, 'omninox_download' );

		// If standard nonce check passed, proceed.
		// As a fallback for environments where session token cookies differ between REST and admin-post:
		// verify that the token exists and was issued to the current user within the last 5 minutes.
		if ( ! $nonce_valid ) {
			$clean_peek = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
			$token_data = ! empty( $clean_peek ) ? get_transient( 'omninox_dl_' . $clean_peek ) : false;

			if ( ! is_array( $token_data ) || empty( $token_data['user'] ) || (int) $token_data['user'] !== (int) get_current_user_id() ) {
				wp_die(
					esc_html__( 'Security check failed. Invalid or expired download nonce.', 'omninox-site-toolkit' ),
					'',
					array( 'response' => 403 )
				);
			}
		}

		if ( empty( $token ) ) {
			wp_die(
				esc_html__( 'Invalid download token.', 'omninox-site-toolkit' ),
				'',
				array( 'response' => 403 )
			);
		}

		// Gate 4: Validate token existence, ownership/binding, expiry, path safety, and stream file.
		$result = $this->serve_download( $token );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = ( is_array( $error_data ) && isset( $error_data['status'] ) ) ? (int) $error_data['status'] : 403;
			wp_die(
				esc_html( $result->get_error_message() ),
				'',
				array( 'response' => $status )
			);
		}
	}

	/**
	 * Stream a file in exchange for a token.
	 *
	 * @param string $token Single-use download token.
	 * @return void|\WP_Error
	 */
	public function serve_download( $token ) {
		$path = Security::redeem_token( (string) $token );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$uploads   = wp_upload_dir();
		$norm_path = wp_normalize_path( (string) $path );
		$norm_base = wp_normalize_path( (string) $uploads['basedir'] );

		if ( empty( $norm_base ) || 0 !== strpos( $norm_path, $norm_base ) ) {
			return new \WP_Error( 'omninox_forbidden_download', __( 'Unauthorized file download path.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		if ( ! file_exists( $path ) || ! is_file( $path ) ) {
			return new \WP_Error( 'omninox_file_gone', __( 'That file no longer exists.', 'omninox-site-toolkit' ), array( 'status' => 404 ) );
		}

		Security::audit( 'performed', 'download', basename( $path ) );

		$size = (int) filesize( $path );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header_remove( 'Content-Encoding' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Accept-Ranges: bytes' );

		$start  = 0;
		$end    = $size - 1;
		$status = 200;

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) ) {
			$r_start = ( '' === $m[1] ) ? null : (int) $m[1];
			$r_end   = ( '' === $m[2] ) ? null : (int) $m[2];

			if ( null === $r_start ) {
				if ( null !== $r_end && $r_end > 0 ) {
					$start = max( 0, $size - $r_end );
				}
			} else {
				$start = $r_start;

				if ( null !== $r_end ) {
					$end = min( $r_end, $size - 1 );
				}
			}

			if ( $start > $end || $start >= $size ) {
				header( 'Content-Range: bytes */' . $size );
				status_header( 416 );
				exit;
			}

			$status = 206;
		}

		$length = ( $end - $start ) + 1;

		status_header( $status );

		if ( 206 === $status ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		header( 'Content-Length: ' . $length );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $handle ) {
			exit;
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$read = fread( $handle, (int) min( 1048576, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $read || '' === $read ) {
				break;
			}

			echo $read; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$remaining -= strlen( $read );

			flush();
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Site-specific improvement tips derived from the latest scans.
	 *
	 * @return \WP_REST_Response
	 */
	public function tips() {
		return rest_ensure_response( \OmninoxSiteToolkit\Services\Advisor::tips() );
	}

	/**
	 * Pending core, plugin and theme updates.
	 *
	 * @return \WP_REST_Response
	 */
	public function updates() {
		return rest_ensure_response( \OmninoxSiteToolkit\Services\Advisor::updates() );
	}

	/**
	 * Recent value changes made through the plugin.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function changes( \WP_REST_Request $request ) {
		$limit = absint( $request->get_param( 'limit' ) ) ?: 25;

		return rest_ensure_response( ValueHistory::recent( $limit ) );
	}

	/**
	 * Forget the change history.
	 *
	 * @return \WP_REST_Response
	 */
	public function clear_changes() {
		ValueHistory::forget();

		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/**
	 * Plain-language explanation for an issue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function explain( \WP_REST_Request $request ) {
		$issue = (array) $request->get_param( 'issue' );

		return rest_ensure_response( Explainer::explain( $issue ) );
	}

	/**
	 * Search test runner for admin UI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search_test( \WP_REST_Request $request ) {
		$query = sanitize_text_field( (string) $request->get_param( 'query' ) );
		$limit = absint( $request->get_param( 'limit' ) ) ?: 15;

		if ( '' === $query ) {
			return new \WP_Error( 'omninox_missing_query', __( 'Please provide a search term.', 'omninox-site-toolkit' ), array( 'status' => 400 ) );
		}

		$start   = microtime( true );
		$results = \OmninoxSiteToolkit\Modules\Search\LiveSearch::query( $query, $limit );
		$time_ms = round( ( microtime( true ) - $start ) * 1000, 2 );

		return rest_ensure_response(
			array(
				'query'    => $query,
				'count'    => count( $results ),
				'duration' => $time_ms,
				'results'  => $results,
			)
		);
	}
}
