<?php
/**
 * Free Plugin License & Feature Access Coordinator.
 *
 * All Free features operate 100% locally and never expire or require activation.
 * Pro feature availability is determined dynamically via the separate Pro Add-on.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\License;

use OmninoxSiteToolkit\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

class LicenseManager {

	use Singleton;

	const OPTION     = 'omninox_license';
	const SERVER_URL = 'https://netinnovix.com/api';
	const BUY_URL    = 'https://netinnovix.com/plugins/omninox-site-toolkit/buy';

	/**
	 * Boot license manager.
	 */
	public function boot() {
		// Schedule periodic background license check if key is set.
		if ( ! wp_next_scheduled( 'omninox_license_check' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'omninox_license_check' );
		}
		add_action( 'omninox_license_check', array( $this, 'maybe_background_check' ) );
	}

	/**
	 * Stored licence data.
	 *
	 * @return array
	 */
	public function data() {
		if ( $this->is_premium() ) {
			return (array) apply_filters( 'omninox_pro_license_data', get_option( self::OPTION, array() ) );
		}

		return array(
			'key'            => '',
			'status'         => 'free',
			'tier'           => 'free',
			'expires'        => 'lifetime',
			'sites'          => 1,
			'sites_used'     => 1,
			'checked_at'     => current_time( 'mysql' ),
			'last_check'     => time(),
			'last_ok'        => time(),
			'download_token' => '',
			'download_url'   => '',
			'proof'          => array(),
		);
	}

	/**
	 * Whether Pro features are active (via active license or Pro add-on).
	 *
	 * @return bool
	 */
	public function is_premium() {
		if ( ! defined( 'OMNINOX_SITE_TOOLKIT_PRO_VERSION' ) ) {
			return false;
		}

		// Strictly delegate to ProLicenseManager to verify cryptographic HMAC and server authentication.
		if ( class_exists( '\OmninoxSiteToolkitPro\License\ProLicenseManager' ) ) {
			return \OmninoxSiteToolkitPro\License\ProLicenseManager::instance()->is_active();
		}

		return false;
	}

	/**
	 * Feature token helper.
	 *
	 * @param string $feature_id Feature identifier.
	 * @return string
	 */
	public function feature_token( $feature_id ) {
		return $this->is_premium() ? 'pro' : 'free';
	}

	/**
	 * Verify feature token.
	 *
	 * @param string $feature_id Feature identifier.
	 * @param string $token      Token.
	 * @return bool
	 */
	public function verify_feature_token( $feature_id, $token ) {
		return true;
	}

	/**
	 * Whether the current install may use a feature or module action.
	 *
	 * @param string      $feature_or_module Module id.
	 * @param string|null $action            Action id, or null for the module.
	 * @return bool
	 */
	public static function can_access( $feature_or_module, $action = null ) {
		return FeatureGate::can_use( $feature_or_module, $action );
	}

	/**
	 * Periodic background check.
	 */
	public function maybe_background_check() {
		if ( $this->is_premium() ) {
			$this->validate( false );
			do_action( 'omninox_pro_background_check' );
		}
	}

	/**
	 * Gate a module or action execution.
	 *
	 * @param mixed       $module Module instance or string ID.
	 * @param string|null $action Action ID.
	 * @return true|\WP_Error
	 */
	public function gate( $module, $action = null ) {
		$module_id = is_object( $module ) && method_exists( $module, 'get_id' ) ? $module->get_id() : (string) $module;

		if ( ! FeatureGate::can_use( $module_id, $action ) ) {
			return new \WP_Error(
				'omninox_pro_required',
				__( 'This feature requires Omninox Pro.', 'omninox-site-toolkit' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Data for the UI.
	 *
	 * @return array
	 */
	public function summary() {
		$data   = (array) get_option( self::OPTION, array() );
		$is_pro = $this->is_premium();

		if ( $is_pro ) {
			$pro_summary = apply_filters( 'omninox_pro_license_summary', null );
			if ( is_array( $pro_summary ) ) {
				return $pro_summary;
			}
		}

		$raw_key    = ! empty( $data['key'] ) ? (string) $data['key'] : '';
		$masked_key = '';
		if ( strlen( $raw_key ) > 8 ) {
			$masked_key = substr( $raw_key, 0, 4 ) . '...' . substr( $raw_key, -4 );
		} elseif ( ! empty( $raw_key ) ) {
			$masked_key = '****';
		}

		$has_valid_key = ( ! empty( $data['key'] ) && ! empty( $data['status'] ) && in_array( $data['status'], array( 'active', 'valid' ), true ) );
		$pro_installed = defined( 'OMNINOX_SITE_TOOLKIT_PRO_VERSION' );

		return array(
			'tier'            => ( $is_pro || $has_valid_key ) ? ( $data['tier'] ?? 'pro' ) : 'free',
			'status'          => ! empty( $data['status'] ) ? $data['status'] : ( $has_valid_key ? 'active' : 'free' ),
			'premium'         => $is_pro,
			'has_valid_key'   => $has_valid_key,
			'pro_installed'   => $pro_installed,
			'test_mode'       => ! empty( $data['test_mode'] ),
			'expires'         => ! empty( $data['expires'] ) ? $data['expires'] : 'lifetime',
			'sites'           => ! empty( $data['sites'] ) ? (int) $data['sites'] : 1,
			'sites_used'      => ! empty( $data['sites_used'] ) ? (int) $data['sites_used'] : 1,
			'checked_at'      => ! empty( $data['checked_at'] ) ? $data['checked_at'] : current_time( 'mysql' ),
			'masked_key'      => $masked_key,
			'download_url'    => ! empty( $data['download_url'] ) ? $data['download_url'] : 'https://netinnovix.com/plugins/omninox-site-toolkit',
			'media_used'      => FeatureGate::media_conversions_used(),
			'media_limit'     => FeatureGate::MEDIA_FREE_LIMIT,
			'media_remaining' => FeatureGate::media_conversions_remaining(),
			'upgrade_url'     => self::BUY_URL,
			'plans'           => $this->get_pricing_plans(),
		);
	}

	/**
	 * Fetch live pricing plans from NetInnovix server with transient cache.
	 *
	 * @param bool $force_refresh Force network refresh.
	 * @return array
	 */
	public function get_pricing_plans( $force_refresh = false ) {
		$cache_key = 'omninox_pricing_plans_v2';
		delete_transient( 'omninox_pricing_plans' );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$fallback_plans = array(
			'single_lifetime' => array(
				'id'       => 'single_lifetime',
				'name'     => __( 'Single Site — Lifetime License', 'omninox-site-toolkit' ),
				'price'    => '$15',
				'billing'  => __( 'One-time payment (Pay once, use forever)', 'omninox-site-toolkit' ),
				'sites'    => 1,
				'period'   => 'lifetime',
				'popular'  => true,
				'features' => array(
					__( '1 Site License with Lifetime Updates', 'omninox-site-toolkit' ),
					__( 'Full Site Backups & Restore', 'omninox-site-toolkit' ),
					__( 'Import Archives from Other Sites', 'omninox-site-toolkit' ),
					__( 'Unlimited WebP & AVIF Image Conversions', 'omninox-site-toolkit' ),
					__( 'CSS & JS Minification & Lazy Load', 'omninox-site-toolkit' ),
					__( 'Security Lockout Limits & Username Protection', 'omninox-site-toolkit' ),
					__( 'No Recurring Fees / No Subscriptions', 'omninox-site-toolkit' ),
				),
				'buy_url'  => self::BUY_URL . '?plan=single_lifetime',
			),
		);

		$response = wp_remote_get(
			trailingslashit( self::SERVER_URL ) . 'plugins/catalog',
			array(
				'timeout'   => 8,
				'sslverify' => true,
				'headers'   => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['plugins'] ) && is_array( $body['plugins'] ) ) {
				$found_plugin = null;
				foreach ( $body['plugins'] as $p ) {
					$s = $p['slug'] ?? '';
					if ( in_array( $s, array( 'omninox-site-toolkit-pro', 'omninox-site-toolkit' ), true ) ) {
						$found_plugin = $p;
						break;
					}
				}

				if ( $found_plugin ) {
					$price = ! empty( $found_plugin['displayPriceUsd'] ) ? $found_plugin['displayPriceUsd'] : ( ! empty( $found_plugin['displayPriceInr'] ) ? $found_plugin['displayPriceInr'] : '$15' );
					$fallback_plans['single_lifetime']['price'] = $price;
					set_transient( $cache_key, $fallback_plans, 12 * HOUR_IN_SECONDS );
					return $fallback_plans;
				}
			}
		}

		set_transient( $cache_key, $fallback_plans, 2 * HOUR_IN_SECONDS );
		return $fallback_plans;
	}

	/**
	 * Activate a licence key against the NetInnovix licensing server.
	 *
	 * @param string $key Licence key.
	 * @return array|\WP_Error
	 */
	public function activate( $key ) {
		$key = trim( sanitize_text_field( $key ) );

		if ( empty( $key ) ) {
			return new \WP_Error(
				'omninox_empty_license',
				__( 'Please enter a valid license key.', 'omninox-site-toolkit' ),
				array( 'status' => 400 )
			);
		}

		// Allow Pro add-on or custom filter hook override.
		$filter_res = apply_filters( 'omninox_pro_activate_license', null, $key );
		if ( null !== $filter_res ) {
			return $filter_res;
		}

		// Connect to NetInnovix license server
		$response = wp_remote_post(
			trailingslashit( self::SERVER_URL ) . 'activate',
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'key'         => $key,
						'licenseKey'  => $key,
						'site_url'    => home_url(),
						'siteUrl'     => home_url(),
						'plugin'      => 'omninox-site-toolkit',
						'plugin_slug' => 'omninox-site-toolkit',
						'version'     => defined( 'OMNINOX_SITE_TOOLKIT_VERSION' ) ? OMNINOX_SITE_TOOLKIT_VERSION : '1.0.7',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'omninox_license_connection_error',
				sprintf(
					/* translators: %s: connection error message */
					__( 'Could not connect to NetInnovix licensing server (%s). Please try again.', 'omninox-site-toolkit' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$res_data    = json_decode( $body_raw, true );

		$is_ok = ( 200 === $status_code ) && (
			! empty( $res_data['ok'] ) ||
			! empty( $res_data['success'] ) ||
			! empty( $res_data['valid'] )
		);

		if ( ! $is_ok ) {
			$error_message = ! empty( $res_data['error'] )
				? $res_data['error']
				: ( ! empty( $res_data['reason'] )
					? $res_data['reason']
					: ( ! empty( $res_data['message'] )
						? $res_data['message']
						: __( 'Invalid license key. Please check your purchase receipt or buy a license.', 'omninox-site-toolkit' ) ) );

			return new \WP_Error( 'omninox_invalid_license', $error_message, array( 'status' => 400 ) );
		}

		// License is validated
		$license_data = array(
			'key'            => $key,
			'status'         => 'active',
			'tier'           => ! empty( $res_data['tier'] ) ? sanitize_key( $res_data['tier'] ) : 'pro',
			'expires'        => ! empty( $res_data['expires'] ) ? sanitize_text_field( $res_data['expires'] ) : 'lifetime',
			'sites'          => ! empty( $res_data['sites'] ) ? (int) $res_data['sites'] : 1,
			'sites_used'     => ! empty( $res_data['sites_used'] ) ? (int) $res_data['sites_used'] : 1,
			'checked_at'     => current_time( 'mysql' ),
			'last_check'     => time(),
			'last_ok'        => time(),
			'download_token' => $res_data['download_token'] ?? '',
			'download_url'   => $res_data['download_url'] ?? '',
			'proof'          => (array) ( $res_data['proof'] ?? array() ),
		);

		update_option( self::OPTION, $license_data );
		update_option( 'omninox_pro_license', $license_data );

		$message = defined( 'OMNINOX_SITE_TOOLKIT_PRO_VERSION' )
			? __( 'Omninox Pro license activated successfully! All Pro features are unlocked.', 'omninox-site-toolkit' )
			: __( 'License activated successfully! Please install and activate the Omninox Site Toolkit Pro plugin to unlock all Pro features.', 'omninox-site-toolkit' );

		return array(
			'success'       => true,
			'message'       => $message,
			'pro_installed' => defined( 'OMNINOX_SITE_TOOLKIT_PRO_VERSION' ),
			'license'       => $this->summary(),
		);
	}

	/**
	 * Deactivate the licence.
	 *
	 * @return array
	 */
	public function deactivate() {
		$data     = (array) get_option( self::OPTION, array() );
		$pro_data = (array) get_option( 'omninox_pro_license', array() );
		$key      = ! empty( $data['key'] ) ? $data['key'] : ( $pro_data['key'] ?? '' );

		if ( ! empty( $key ) ) {
			wp_remote_post(
				trailingslashit( self::SERVER_URL ) . 'deactivate',
				array(
					'timeout'   => 10,
					'sslverify' => true,
					'headers'   => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'      => wp_json_encode(
						array(
							'key'         => $key,
							'license_key' => $key,
							'licenseKey'  => $key,
							'site_url'    => home_url(),
							'siteUrl'     => home_url(),
							'plugin_slug' => 'omninox-site-toolkit',
						)
					),
				)
			);
		}

		delete_option( self::OPTION );
		delete_option( 'omninox_pro_license' );

		do_action( 'omninox_license_deactivated' );

		return array(
			'success' => true,
			'message' => __( 'License deactivated. Pro features are now disabled.', 'omninox-site-toolkit' ),
			'license' => $this->summary(),
		);
	}

	/**
	 * Re-validate license against server.
	 */
	public function revalidate() {
		$this->validate( true );
	}

	/**
	 * Validate license status against NetInnovix server.
	 *
	 * @param bool $force Force remote validation check.
	 * @return bool
	 */
	public function validate( $force = false ) {
		$data = (array) get_option( self::OPTION, array() );

		if ( empty( $data['key'] ) ) {
			return false;
		}

		if ( ! $force && ! empty( $data['last_check'] ) && ( time() - $data['last_check'] < 7 * DAY_IN_SECONDS ) ) {
			return 'active' === ( $data['status'] ?? '' );
		}

		$response = wp_remote_post(
			trailingslashit( self::SERVER_URL ) . 'validate',
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'key'         => $data['key'],
						'licenseKey'  => $data['key'],
						'site_url'    => home_url(),
						'siteUrl'     => home_url(),
						'plugin_slug' => 'omninox-site-toolkit',
					)
				),
			)
		);

		$data['last_check'] = time();

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$res_data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $res_data['ok'] ) || ! empty( $res_data['valid'] ) || ! empty( $res_data['success'] ) ) {
				$data['status']  = 'active';
				$data['last_ok'] = time();
			} else {
				$data['status'] = 'expired';
			}
		}

		update_option( self::OPTION, $data );

		return 'active' === ( $data['status'] ?? '' );
	}
}
