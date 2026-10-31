<?php
/**
 * Module 4: CSS & JS Manager.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Performance;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\Helpers\Utils;

defined( 'ABSPATH' ) || exit;

class AssetManagerModule extends AbstractModule {

	protected $id      = 'assets';
	protected $icon    = 'editor-code';
	protected $premium = false;

	public function get_label() {
		return __( 'CSS & JS Manager', 'omninox-site-toolkit' );
	}

	/**
	 * Apply the stored rules on the front end.
	 */
	public function register() {
		// The asset manager (per-page unloader, defer/async/delay, global
		// rules) is a wholly-Pro module; its runtime hooks stay off entirely
		// on free installs so saved rules can't act without a licence.
		if ( ! \OmninoxSiteToolkit\License\LicenseManager::can_access( 'assets' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'apply_rules' ), 9999 );
		add_filter( 'script_loader_tag', array( $this, 'apply_loading_strategy' ), 20, 3 );
		add_action( 'wp_footer', array( $this, 'print_delay_runtime' ), 99 );
		add_action( 'wp_footer', array( $this, 'capture_enqueued' ), 999 );
	}

	/**
	 * List everything currently registered.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$inventory = get_transient( 'omninox_asset_inventory' );

		if ( ! is_array( $inventory ) ) {
			$inventory = array( 'scripts' => array(), 'styles' => array() );

			$this->add(
				array(
					'id'          => 'inventory_pending',
					'label'       => __( 'Asset inventory not built yet', 'omninox-site-toolkit'),
					'severity'    => self::SEV_NOTICE,
					'description' => __( 'Open the front end of the site once. The inventory is collected on the next page view and appears here.', 'omninox-site-toolkit'),
					'weight'      => 1,
				)
			);
		} else {
			$total = count( $inventory['scripts'] ) + count( $inventory['styles'] );

			$this->add(
				array(
					'id'       => 'asset_count',
					'label'    => __( 'Registered assets on the front end', 'omninox-site-toolkit'),
					'value'    => (string) $total,
					'expected' => '< 40',
					'weight'   => 2,
					'severity' => $total < 40 ? self::SEV_PASS : self::SEV_WARNING,
				)
			);
		}

		$duplicates = $this->duplicate_analysis( $inventory );

		$this->add(
			array(
				'id'          => 'duplicate_libraries',
				'label'       => __( 'Duplicate libraries loaded by more than one plugin', 'omninox-site-toolkit'),
				'value'       => (string) count( $duplicates['libraries'] ),
				'expected'    => '0',
				'weight'      => 3,
				'severity'    => empty( $duplicates['libraries'] ) ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'The same library bundled by several plugins is downloaded several times, and loading something like jQuery twice breaks scripts that depend on it.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => $duplicates['libraries'] ),
			)
		);

		$this->add(
			array(
				'id'          => 'identical_files',
				'label'       => __( 'Byte-identical files loaded from different paths', 'omninox-site-toolkit'),
				'value'       => (string) count( $duplicates['identical'] ),
				'expected'    => '0',
				'weight'      => 2,
				'severity'    => empty( $duplicates['identical'] ) ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'These files have the same contents but different URLs, so the browser caches and downloads each one separately.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => $duplicates['identical'] ),
			)
		);

		$this->add(
			array(
				'id'          => 'repeated_handles',
				'label'       => __( 'Handles registered more than once', 'omninox-site-toolkit'),
				'value'       => (string) count( $duplicates['handles'] ),
				'weight'      => 1.5,
				'severity'    => empty( $duplicates['handles'] ) ? self::SEV_PASS : self::SEV_NOTICE,
				'context'     => array( 'items' => $duplicates['handles'] ),
			)
		);

		return $this->result(
			array(
				'inventory'  => $inventory,
				'rules'      => (array) Settings::instance()->get( 'asset_rules', array() ),
				'duplicates' => $duplicates,
				'charts'     => array(
					array(
						'id'    => 'assets_by_owner',
						'type'  => 'bar',
						'title' => __( 'Assets loaded per plugin or theme', 'omninox-site-toolkit'),
						'data'  => $duplicates['by_owner'],
					),
				),
			)
		);
	}

	/**
	 * Work out what is genuinely duplicated, and which files are responsible.
	 *
	 * Three separate questions are answered here, because they need different
	 * fixes: the same library shipped by different plugins, byte-identical
	 * files served from two paths, and one handle registered twice.
	 *
	 * @param array $inventory Enqueued assets.
	 * @return array
	 */
	private function duplicate_analysis( array $inventory ) {
		$all = array_merge(
			array_map( static function ( $item ) { $item['kind'] = 'script'; return $item; }, (array) ( $inventory['scripts'] ?? array() ) ),
			array_map( static function ( $item ) { $item['kind'] = 'style'; return $item; }, (array) ( $inventory['styles'] ?? array() ) )
		);

		$libraries = array();
		$hashes    = array();
		$handles   = array();
		$owners    = array();
		$identical = array();
		$repeated  = array();

		$known = array(
			'jquery', 'bootstrap', 'swiper', 'slick', 'owl.carousel', 'fontawesome', 'font-awesome',
			'lodash', 'moment', 'chart', 'select2', 'magnific-popup', 'lightbox', 'aos', 'gsap',
			'isotope', 'masonry', 'flickity', 'popper', 'axios', 'vue', 'react',
		);

		foreach ( $all as $item ) {
			$src = (string) ( $item['src'] ?? '' );

			if ( '' === $src ) {
				continue;
			}

			$owner = $this->owner_of( $src );
			$owners[ $owner ] = ( $owners[ $owner ] ?? 0 ) + 1;

			$handle = $item['handle'];
			$handles[ $handle ] = ( $handles[ $handle ] ?? 0 ) + 1;

			if ( ( $handles[ $handle ] ) > 1 ) {
				$repeated[] = array( 'handle' => $handle, 'file' => $src, 'owner' => $owner );
			}

			// Library detection by filename, grouped by owner.
			$basename = strtolower( wp_basename( strtok( $src, '?' ) ) );

			foreach ( $known as $library ) {
				if ( false !== strpos( $basename, $library ) ) {
					$libraries[ $library ][] = array(
						'handle' => $handle,
						'owner'  => $owner,
						'file'   => $src,
						'size'   => $this->size_of( $src ),
					);
					break;
				}
			}

			// Byte-identical detection for local files.
			$path = $this->path_of( $src );

			if ( $path && is_readable( $path ) && filesize( $path ) < 4194304 ) {
				$hash = md5_file( $path );

				if ( isset( $hashes[ $hash ] ) && $hashes[ $hash ]['file'] !== $src ) {
					$identical[] = array(
						'file'          => $src,
						'identical_to'  => $hashes[ $hash ]['file'],
						'owner'         => $owner,
						'other_owner'   => $hashes[ $hash ]['owner'],
						'size'          => $this->size_of( $src ),
					);
				} else {
					$hashes[ $hash ] = array( 'file' => $src, 'owner' => $owner );
				}
			}
		}

		// Only report a library as duplicated when more than one owner ships it.
		$duplicated_libraries = array();

		foreach ( $libraries as $library => $entries ) {
			$distinct = array_unique( wp_list_pluck( $entries, 'owner' ) );

			if ( count( $distinct ) > 1 ) {
				$duplicated_libraries[] = array(
					'library'  => $library,
					'copies'   => count( $entries ),
					'loaded_by' => implode( ', ', $distinct ),
					'files'    => implode( ' | ', array_slice( wp_list_pluck( $entries, 'file' ), 0, 4 ) ),
				);
			}
		}

		arsort( $owners );

		$by_owner = array();

		foreach ( array_slice( $owners, 0, 12, true ) as $name => $count ) {
			$by_owner[] = array( 'label' => $name, 'value' => $count );
		}

		return array(
			'libraries' => $duplicated_libraries,
			'identical' => $identical,
			'handles'   => $repeated,
			'by_owner'  => $by_owner,
		);
	}

	/**
	 * Which plugin, theme or core area an asset belongs to.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	private function owner_of( $src ) {
		if ( preg_match( '#/(?:plugins|mu-plugins)/([^/]+)/#i', $src, $match ) ) {
			return 'plugin: ' . $match[1];
		}

		if ( preg_match( '#/themes/([^/]+)/#i', $src, $match ) ) {
			return 'theme: ' . $match[1];
		}

		if ( preg_match( '#/wp-includes/#', $src ) ) {
			return 'core';
		}

		$host = wp_parse_url( $src, PHP_URL_HOST );

		return $host ? 'external: ' . $host : 'unknown';
	}

	/**
	 * Local filesystem path for an asset URL.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	private function path_of( $src ) {
		return Utils::url_to_path( $src );
	}

	/**
	 * Human readable size of a local asset.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	private function size_of( $src ) {
		$path = $this->path_of( $src );

		return ( $path && is_readable( $path ) ) ? size_format( filesize( $path ) ) : '—';
	}

	/**
	 * Save or clear a rule.
	 *
	 * @param string $action Action id.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		$settings = Settings::instance();
		$rules    = (array) $settings->get( 'asset_rules', array() );

		if ( 'set_rule' === $action ) {
			$handle = sanitize_key( $params['handle'] ?? '' );

			if ( '' === $handle ) {
				return new \WP_Error( 'omninox_missing_handle', __( 'Choose an asset handle first.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
			}

			$rules[ $handle ] = array(
				'type'     => $params['type'] ?? 'script',
				'mode'     => $params['mode'] ?? 'default',
				'scope'    => $params['scope'] ?? 'everywhere',
				'post_ids' => array_map( 'absint', (array) ( $params['post_ids'] ?? array() ) ),
			);

			$settings->update( array( 'asset_rules' => $rules ) );

			return array( 'message' => __( 'Rule saved.', 'omninox-site-toolkit'), 'rules' => $settings->get( 'asset_rules' ) );
		}

		if ( 'clear_rule' === $action ) {
			$handle = sanitize_key( $params['handle'] ?? '' );
			unset( $rules[ $handle ] );

			$settings->update( array( 'asset_rules' => $rules ) );

			return array( 'message' => __( 'Rule removed.', 'omninox-site-toolkit'), 'rules' => $rules );
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Dequeue assets according to the rules.
	 */
	public function apply_rules() {
		if ( is_admin() ) {
			return;
		}

		$rules   = (array) Settings::instance()->get( 'asset_rules', array() );
		$post_id = get_queried_object_id();

		foreach ( $rules as $handle => $rule ) {
			if ( 'disable' !== $rule['mode'] || ! $this->applies_here( $rule, $post_id ) ) {
				continue;
			}

			if ( 'style' === $rule['type'] ) {
				wp_dequeue_style( $handle );
			} else {
				wp_dequeue_script( $handle );
			}
		}
	}

	/**
	 * Add async/defer/delay attributes.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    URL.
	 * @return string
	 */
	public function apply_loading_strategy( $tag, $handle, $src ) {
		$rules = (array) Settings::instance()->get( 'asset_rules', array() );
		$rule  = $rules[ $handle ] ?? null;

		if ( ! $rule || 'script' !== $rule['type'] || ! $this->applies_here( $rule, get_queried_object_id() ) ) {
			return $tag;
		}

		switch ( $rule['mode'] ) {
			case 'async':
				return str_replace( ' src=', ' async src=', $tag );

			case 'defer':
				return str_replace( ' src=', ' defer src=', $tag );

			case 'delay':
				return str_replace(
					array( ' src=', 'type="text/javascript"' ),
					array( ' data-omninox-src=', 'type="text/omninox-delayed"' ),
					$tag
				);
		}

		return $tag;
	}

	/**
	 * Load delayed scripts on the first interaction.
	 */
	public function print_delay_runtime() {
		$rules = (array) Settings::instance()->get( 'asset_rules', array() );

		$has_delay = false;

		foreach ( $rules as $rule ) {
			if ( 'delay' === $rule['mode'] ) {
				$has_delay = true;
				break;
			}
		}

		if ( ! $has_delay ) {
			return;
		}

		wp_enqueue_script(
			'omninox-delay-runtime',
			OMNINOX_SITE_TOOLKIT_URL . 'assets/js/delay-runtime.js',
			array(),
			OMNINOX_SITE_TOOLKIT_VERSION,
			true
		);
	}

	/**
	 * Store the list of enqueued handles for the UI.
	 */
	public function capture_enqueued() {
		if ( is_admin() || is_user_logged_in() === false ) {
			// Only build the inventory from an authenticated page view to avoid caching side effects.
			return;
		}

		global $wp_scripts, $wp_styles;

		$inventory = array( 'scripts' => array(), 'styles' => array() );

		foreach ( (array) ( $wp_scripts->done ?? array() ) as $handle ) {
			$item = $wp_scripts->registered[ $handle ] ?? null;

			$inventory['scripts'][] = array(
				'handle' => $handle,
				'src'    => $item ? $item->src : '',
				'deps'   => $item ? $item->deps : array(),
			);
		}

		foreach ( (array) ( $wp_styles->done ?? array() ) as $handle ) {
			$item = $wp_styles->registered[ $handle ] ?? null;

			$inventory['styles'][] = array(
				'handle' => $handle,
				'src'    => $item ? $item->src : '',
				'deps'   => $item ? $item->deps : array(),
			);
		}

		set_transient( 'omninox_asset_inventory', $inventory, HOUR_IN_SECONDS );
	}

	/**
	 * Does a rule apply to the current request?
	 *
	 * @param array $rule    Rule.
	 * @param int   $post_id Current post id.
	 * @return bool
	 */
	private function applies_here( array $rule, $post_id ) {
		if ( 'everywhere' === $rule['scope'] ) {
			return true;
		}

		$listed = in_array( (int) $post_id, array_map( 'intval', (array) $rule['post_ids'] ), true );

		return 'include' === $rule['scope'] ? $listed : ! $listed;
	}
}
