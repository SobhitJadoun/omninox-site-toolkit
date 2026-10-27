<?php
/**
 * Settings store with sane defaults and strict sanitising.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Core;

use OmninoxSiteToolkit\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

class Settings {

	use Singleton;

	const OPTION = 'omninox_site_toolkit_settings';

	/**
	 * Cached values.
	 *
	 * @var array
	 */
	private $values = array();

	/**
	 * Load values on boot.
	 */
	public function boot() {
		$this->values = wp_parse_args( (array) get_option( self::OPTION, get_option( 'omninox_settings', array() ) ), self::defaults() );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'theme'                    => 'auto',
			'delete_data_on_uninstall' => false,
			'scan_schedule'            => 'weekly',
			'scan_modules'             => array( 'health', 'performance', 'security', 'database', 'seo' ),
			'notify_email'             => get_option( 'admin_email' ),
			'notify_on'                => array( 'critical' ),
			'retention_days'           => 90,
			'upload_limit_mb'          => 0,
			'backup_keep'              => 3,
			'login_attempts'           => 5,
			'lockout_minutes'          => 20,
			'conversion_format'        => 'webp',
			'webp_sizes'               => 'all',
			'add_webp_to_library'      => false,
			'log_errors'               => true,
			'optimizations'            => array(
				'disable_emojis'       => false,
				'disable_embeds'       => false,
				'disable_dashicons'    => false,
				'disable_jquery_migrate' => false,
				'remove_query_strings' => false,
				'lazy_load'            => false,
				'minify_css'           => false,
				'minify_js'            => false,
				'optimize_fonts'       => false,
			),
			'hardening'                => array(
				'disable_xmlrpc'         => false,
				'block_user_enumeration' => false,
				'disable_file_editor'    => false,
				'hide_wp_version'        => false,
				'limit_logins'           => false,
				'honeypot'               => false,
				'two_factor'             => false,
				'block_ips'              => false,
			),
			'asset_rules'              => array(),
			'webhooks'                 => array(
				'slack'    => '',
				'discord'  => '',
				'teams'    => '',
				'telegram' => '',
			),
			'search'                   => array(
				'enabled'             => true,
				'search_title'        => true,
				'search_content'      => true,
				'search_excerpt'      => true,
				'search_terms'        => true,
				'search_cpt'          => true,
				'post_types'          => array( 'post', 'page' ),
				'search_meta'         => false,
				'meta_keys'           => '',
				'weight_title'        => 50,
				'weight_content'      => 10,
				'weight_excerpt'      => 20,
				'weight_terms'        => 25,
				'weight_meta'         => 15,
				'exact_match_bonus'   => 100,
				'matching_mode'       => 'smart_fallback',
				'prioritize_phrases'  => true,
				'normalize_queries'   => true,
				'ignore_stop_words'   => true,
				'custom_stop_words'   => '',
				'smart_fallback'      => true,
				'partial_matching'    => true,
				'fuzzy_matching'      => false,
				'highlight_terms'     => true,
				'smart_excerpts'      => true,
				'posts_per_page'      => 0,
				'admin_search'        => false,
				'live_search'         => true,
				'prevent_duplicates'  => true,
				'excluded_post_types' => array( 'attachment', 'revision', 'nav_menu_item' ),
				'excluded_post_ids'   => '',
				'excluded_term_ids'   => '',
			),
		);
	}

	/**
	 * Get a value using dot notation.
	 *
	 * @param string $key     Key, e.g. "optimizations.lazy_load".
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key = null, $default = null ) {
		if ( null === $key ) {
			return $this->values;
		}

		$segments = explode( '.', $key );
		$value    = $this->values;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Persist a partial update.
	 *
	 * @param array $changes Changes to merge.
	 * @return array Updated settings.
	 */
	public function update( array $changes ) {
		$before = $this->values;

		$this->values = $this->sanitize( array_replace_recursive( $this->values, $changes ) );

		foreach ( array( 'theme', 'scan_schedule', 'notify_email', 'retention_days' ) as $key ) {
			if ( ( $before[ $key ] ?? null ) !== $this->values[ $key ] ) {
				\OmninoxSiteToolkit\Services\ValueHistory::record( 'setting_' . $key, $before[ $key ] ?? '', $this->values[ $key ], $key );
			}
		}

		update_option( self::OPTION, $this->values );

		do_action( 'omninox_settings_updated', $this->values );

		return $this->values;
	}

	/**
	 * Sanitize the whole settings array.
	 *
	 * @param array $input Raw values.
	 * @return array
	 */
	public function sanitize( array $input ) {
		$clean    = self::defaults();
		$defaults = self::defaults();

		$clean['theme']                    = in_array( $input['theme'] ?? 'auto', array( 'auto', 'light', 'dark' ), true ) ? $input['theme'] : 'auto';
		$clean['license_key']              = sanitize_text_field( $input['license_key'] ?? ( $this->values['license_key'] ?? '' ) );
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		$clean['scan_schedule']            = in_array( $input['scan_schedule'] ?? 'weekly', array( 'off', 'daily', 'weekly', 'monthly' ), true ) ? $input['scan_schedule'] : 'weekly';
		$clean['scan_modules']             = array_map( 'sanitize_key', (array) ( $input['scan_modules'] ?? $defaults['scan_modules'] ) );
		$clean['notify_email']             = sanitize_email( $input['notify_email'] ?? get_option( 'admin_email' ) );
		$clean['notify_on']                = array_values( array_intersect( (array) ( $input['notify_on'] ?? $defaults['notify_on'] ), array( 'critical', 'warning', 'notice' ) ) );
		$clean['retention_days']           = max( 1, min( 365, (int) ( $input['retention_days'] ?? $this->values['retention_days'] ?? 30 ) ) );
		$clean['login_attempts']           = max( 1, min( 50, (int) ( $input['login_attempts'] ?? $this->values['login_attempts'] ?? 5 ) ) );
		$clean['lockout_minutes']          = max( 1, min( 1440, (int) ( $input['lockout_minutes'] ?? $this->values['lockout_minutes'] ?? 20 ) ) );
		$clean['trusted_proxy']            = isset( $input['trusted_proxy'] ) ? ! empty( $input['trusted_proxy'] ) : ! empty( $this->values['trusted_proxy'] );
		$clean['webp_sizes']               = sanitize_text_field( $input['webp_sizes'] ?? $this->values['webp_sizes'] ?? 'all' );
		$clean['add_webp_to_library']      = isset( $input['add_webp_to_library'] ) ? ! empty( $input['add_webp_to_library'] ) : ! empty( $this->values['add_webp_to_library'] );
		$clean['log_errors']               = isset( $input['log_errors'] ) ? ! empty( $input['log_errors'] ) : ! empty( $this->values['log_errors'] );

		// Preserve & sanitize optimizations boolean flags
		$input_opts = $input['optimizations'] ?? $this->values['optimizations'] ?? array();
		if ( is_array( $input_opts ) ) {
			foreach ( $defaults['optimizations'] as $opt_key => $opt_val ) {
				$clean['optimizations'][ $opt_key ] = array_key_exists( $opt_key, $input_opts ) ? ! empty( $input_opts[ $opt_key ] ) : ! empty( $this->values['optimizations'][ $opt_key ] ?? false );
			}
		}

		// Preserve & sanitize hardening boolean flags
		$input_h = $input['hardening'] ?? $this->values['hardening'] ?? array();
		if ( is_array( $input_h ) ) {
			foreach ( $defaults['hardening'] as $h_key => $h_val ) {
				$clean['hardening'][ $h_key ] = array_key_exists( $h_key, $input_h ) ? ! empty( $input_h[ $h_key ] ) : ! empty( $this->values['hardening'][ $h_key ] ?? false );
			}
		}

		$clean['asset_rules'] = $this->sanitize_asset_rules( $input['asset_rules'] ?? $this->values['asset_rules'] ?? array() );

		if ( isset( $input['webhooks'] ) && is_array( $input['webhooks'] ) ) {
			foreach ( array( 'slack', 'discord', 'teams', 'telegram' ) as $wh ) {
				if ( isset( $input['webhooks'][ $wh ] ) ) {
					$clean['webhooks'][ $wh ] = esc_url_raw( $input['webhooks'][ $wh ] );
				}
			}
		}

		// Preserve & sanitize search settings
		$input_search = $input['search'] ?? $this->values['search'] ?? array();
		if ( is_array( $input_search ) ) {
			$clean['search'] = array(
				'enabled'             => isset( $input_search['enabled'] ) ? ! empty( $input_search['enabled'] ) : ! empty( $this->values['search']['enabled'] ?? true ),
				'search_title'        => isset( $input_search['search_title'] ) ? ! empty( $input_search['search_title'] ) : ! empty( $this->values['search']['search_title'] ?? true ),
				'search_content'      => isset( $input_search['search_content'] ) ? ! empty( $input_search['search_content'] ) : ! empty( $this->values['search']['search_content'] ?? true ),
				'search_excerpt'      => isset( $input_search['search_excerpt'] ) ? ! empty( $input_search['search_excerpt'] ) : ! empty( $this->values['search']['search_excerpt'] ?? true ),
				'search_terms'        => isset( $input_search['search_terms'] ) ? ! empty( $input_search['search_terms'] ) : ! empty( $this->values['search']['search_terms'] ?? true ),
				'search_cpt'          => isset( $input_search['search_cpt'] ) ? ! empty( $input_search['search_cpt'] ) : ! empty( $this->values['search']['search_cpt'] ?? true ),
				'post_types'          => ! empty( $input_search['post_types'] ) ? array_map( 'sanitize_key', (array) $input_search['post_types'] ) : ( $this->values['search']['post_types'] ?? array( 'post', 'page' ) ),
				'search_meta'         => isset( $input_search['search_meta'] ) ? ! empty( $input_search['search_meta'] ) : ! empty( $this->values['search']['search_meta'] ?? false ),
				'meta_keys'           => sanitize_text_field( $input_search['meta_keys'] ?? ( $this->values['search']['meta_keys'] ?? '' ) ),
				'weight_title'        => max( 1, min( 500, (int) ( $input_search['weight_title'] ?? $this->values['search']['weight_title'] ?? 50 ) ) ),
				'weight_content'      => max( 1, min( 500, (int) ( $input_search['weight_content'] ?? $this->values['search']['weight_content'] ?? 10 ) ) ),
				'weight_excerpt'      => max( 1, min( 500, (int) ( $input_search['weight_excerpt'] ?? $this->values['search']['weight_excerpt'] ?? 20 ) ) ),
				'weight_terms'        => max( 1, min( 500, (int) ( $input_search['weight_terms'] ?? $this->values['search']['weight_terms'] ?? 25 ) ) ),
				'weight_meta'         => max( 1, min( 500, (int) ( $input_search['weight_meta'] ?? $this->values['search']['weight_meta'] ?? 15 ) ) ),
				'exact_match_bonus'   => max( 0, min( 1000, (int) ( $input_search['exact_match_bonus'] ?? $this->values['search']['exact_match_bonus'] ?? 100 ) ) ),
				'matching_mode'       => in_array( $input_search['matching_mode'] ?? 'smart_fallback', array( 'smart_fallback', 'all', 'any', 'exact' ), true ) ? $input_search['matching_mode'] : 'smart_fallback',
				'prioritize_phrases'  => isset( $input_search['prioritize_phrases'] ) ? ! empty( $input_search['prioritize_phrases'] ) : ! empty( $this->values['search']['prioritize_phrases'] ?? true ),
				'normalize_queries'   => isset( $input_search['normalize_queries'] ) ? ! empty( $input_search['normalize_queries'] ) : ! empty( $this->values['search']['normalize_queries'] ?? true ),
				'ignore_stop_words'   => isset( $input_search['ignore_stop_words'] ) ? ! empty( $input_search['ignore_stop_words'] ) : ! empty( $this->values['search']['ignore_stop_words'] ?? true ),
				'custom_stop_words'   => sanitize_text_field( $input_search['custom_stop_words'] ?? ( $this->values['search']['custom_stop_words'] ?? '' ) ),
				'smart_fallback'      => isset( $input_search['smart_fallback'] ) ? ! empty( $input_search['smart_fallback'] ) : ! empty( $this->values['search']['smart_fallback'] ?? true ),
				'partial_matching'    => isset( $input_search['partial_matching'] ) ? ! empty( $input_search['partial_matching'] ) : ! empty( $this->values['search']['partial_matching'] ?? true ),
				'fuzzy_matching'      => isset( $input_search['fuzzy_matching'] ) ? ! empty( $input_search['fuzzy_matching'] ) : ! empty( $this->values['search']['fuzzy_matching'] ?? false ),
				'highlight_terms'     => isset( $input_search['highlight_terms'] ) ? ! empty( $input_search['highlight_terms'] ) : ! empty( $this->values['search']['highlight_terms'] ?? true ),
				'smart_excerpts'      => isset( $input_search['smart_excerpts'] ) ? ! empty( $input_search['smart_excerpts'] ) : ! empty( $this->values['search']['smart_excerpts'] ?? true ),
				'posts_per_page'      => max( 0, min( 100, (int) ( $input_search['posts_per_page'] ?? $this->values['search']['posts_per_page'] ?? 0 ) ) ),
				'admin_search'        => isset( $input_search['admin_search'] ) ? ! empty( $input_search['admin_search'] ) : ! empty( $this->values['search']['admin_search'] ?? false ),
				'live_search'         => isset( $input_search['live_search'] ) ? ! empty( $input_search['live_search'] ) : ! empty( $this->values['search']['live_search'] ?? true ),
				'prevent_duplicates'  => isset( $input_search['prevent_duplicates'] ) ? ! empty( $input_search['prevent_duplicates'] ) : ! empty( $this->values['search']['prevent_duplicates'] ?? true ),
				'excluded_post_types' => ! empty( $input_search['excluded_post_types'] ) ? array_map( 'sanitize_key', (array) $input_search['excluded_post_types'] ) : ( $this->values['search']['excluded_post_types'] ?? array( 'attachment', 'revision', 'nav_menu_item' ) ),
				'excluded_post_ids'   => sanitize_text_field( $input_search['excluded_post_ids'] ?? ( $this->values['search']['excluded_post_ids'] ?? '' ) ),
				'excluded_term_ids'   => sanitize_text_field( $input_search['excluded_term_ids'] ?? ( $this->values['search']['excluded_term_ids'] ?? '' ) ),
			);
		}

		return $clean;
	}

	/**
	 * Sanitize CSS/JS manager rules.
	 *
	 * @param mixed $rules Raw rules.
	 * @return array
	 */
	private function sanitize_asset_rules( $rules ) {
		$clean = array();

		foreach ( (array) $rules as $handle => $rule ) {
			$handle = sanitize_key( $handle );

			if ( '' === $handle ) {
				continue;
			}

			$clean[ $handle ] = array(
				'type'     => in_array( $rule['type'] ?? 'script', array( 'script', 'style' ), true ) ? $rule['type'] : 'script',
				'mode'     => in_array( $rule['mode'] ?? 'default', array( 'default', 'disable', 'async', 'defer', 'delay' ), true ) ? $rule['mode'] : 'default',
				'scope'    => in_array( $rule['scope'] ?? 'everywhere', array( 'everywhere', 'include', 'exclude' ), true ) ? $rule['scope'] : 'everywhere',
				'post_ids' => array_map( 'absint', (array) ( $rule['post_ids'] ?? array() ) ),
			);
		}

		return $clean;
	}
}
