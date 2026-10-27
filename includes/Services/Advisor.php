<?php
/**
 * Turns the most recent scan results into a short, ordered list of things
 * actually worth doing on this specific site, rather than generic advice.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Services;

use OmninoxSiteToolkit\Database\ScanRepository;
use OmninoxSiteToolkit\Core\ModuleRegistry;
use OmninoxSiteToolkit\Helpers\Utils;

defined( 'ABSPATH' ) || exit;

class Advisor {

	/**
	 * Ranked improvement tips built from stored findings.
	 *
	 * @param int $limit Maximum tips.
	 * @return array
	 */
	public static function tips( $limit = 8 ) {
		$tips = array();

		foreach ( array_keys( ModuleRegistry::instance()->all() ) as $module ) {
			try {
				$result = ScanRepository::latest( $module );
			} catch ( \Throwable $e ) {
				continue;
			}

			if ( ! $result || empty( $result['issues'] ) ) {
				continue;
			}

			foreach ( $result['issues'] as $issue ) {
				$severity = $issue['severity'] ?? 'notice';

				if ( 'pass' === $severity ) {
					continue;
				}

				$tips[] = array(
					'module'    => $module,
					'module_label' => $result['label'] ?? $module,
					'title'     => $issue['label'] ?? '',
					'severity'  => $severity,
					'value'     => is_scalar( $issue['value'] ?? '' ) ? $issue['value'] : '',
					'advice'    => self::advice_for( $issue ),
					'fix'       => $issue['fix'] ?? '',
					'weight'    => self::rank( $severity, (float) ( $issue['weight'] ?? 1 ), ! empty( $issue['fix'] ) ),
				);
			}
		}

		usort(
			$tips,
			static function ( $a, $b ) {
				return $b['weight'] <=> $a['weight'];
			}
		);

		if ( empty( $tips ) ) {
			$tips[] = array(
				'module'       => 'health',
				'module_label' => __( 'Getting started', 'omninox-site-toolkit'),
				'title'        => __( 'Run a full audit', 'omninox-site-toolkit'),
				'severity'     => 'notice',
				'value'        => '',
				'advice'       => __( 'Nothing has been scanned yet, so there is nothing specific to suggest. Run a full audit from the dashboard and the tips here will reflect what it finds.', 'omninox-site-toolkit'),
				'fix'          => '',
				'weight'       => 1,
			);
		}

		return array_slice( $tips, 0, $limit );
	}

	/**
	 * Score a finding so the most valuable work floats to the top.
	 *
	 * A one-click fix is ranked above an equally severe problem that needs
	 * manual work, because it is the one someone will actually do today.
	 *
	 * @param string $severity Severity.
	 * @param float  $weight   Check weight.
	 * @param bool   $fixable  Whether a fix action exists.
	 * @return float
	 */
	private static function rank( $severity, $weight, $fixable ) {
		$base = array(
			'critical' => 100,
			'warning'  => 50,
			'notice'   => 15,
		);

		return ( $base[ $severity ] ?? 10 ) * max( 0.5, $weight ) + ( $fixable ? 25 : 0 );
	}

	/**
	 * The action-shaped sentence for a finding.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	private static function advice_for( array $issue ) {
		return ! empty( $issue['description'] ) ? $issue['description'] : __( 'Open the module for the full detail on this check.', 'omninox-site-toolkit');
	}

	/**
	 * Pending updates across core, plugins and themes.
	 *
	 * @return array
	 */
	public static function updates() {
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$items = array();

		$core = function_exists( 'get_core_updates' ) ? get_core_updates() : array();

		if ( ! empty( $core[0] ) && 'latest' !== ( $core[0]->response ?? 'latest' ) ) {
			$items[] = array(
				'type'    => 'core',
				'name'    => 'WordPress',
				'current' => get_bloginfo( 'version' ),
				'latest'  => $core[0]->current ?? '',
				'urgent'  => true,
			);
		}

		foreach ( (array) get_plugin_updates() as $file => $plugin ) {
			$items[] = array(
				'type'    => 'plugin',
				'name'    => $plugin->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				'current' => $plugin->Version, // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				'latest'  => $plugin->update->new_version ?? '',
				'urgent'  => false,
			);
		}

		foreach ( (array) get_theme_updates() as $theme ) {
			$items[] = array(
				'type'    => 'theme',
				'name'    => $theme->get( 'Name' ),
				'current' => $theme->get( 'Version' ),
				'latest'  => $theme->update['new_version'] ?? '',
				'urgent'  => false,
			);
		}

		return array(
			'items'   => $items,
			'total'   => count( $items ),
			'checked' => current_time( 'mysql' ),
			'message' => $items
				? sprintf(
					/* translators: %d: number of updates */
					_n( '%d update is waiting.', '%d updates are waiting.', count( $items ), 'omninox-site-toolkit'),
					count( $items )
				)
				: __( 'Everything is up to date.', 'omninox-site-toolkit'),
		);
	}

	/**
	 * Rough server resource picture for the dashboard widgets.
	 *
	 * @return array
	 */
	public static function resources() {
		$uploads = wp_upload_dir();
		$storage = Utils::dir_size( $uploads['basedir'], 30000 );

		$peak  = memory_get_peak_usage( true );
		$limit = Utils::to_bytes( ini_get( 'memory_limit' ) );

		$load = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : array();

		return array(
			'storage_used'  => Utils::format_bytes( $storage['size'] ),
			'storage_files' => $storage['files'],
			'disk_free'     => Utils::format_bytes( (int) @disk_free_space( Utils::get_home_path() ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
			'memory_peak'   => Utils::format_bytes( $peak ),
			'memory_limit'  => ini_get( 'memory_limit' ),
			'memory_pct'    => $limit ? (int) round( ( $peak / $limit ) * 100 ) : 0,
			'load_1'        => isset( $load[0] ) ? round( (float) $load[0], 2 ) : null,
			'load_note'     => isset( $load[0] )
				? __( 'One-minute load average for the whole server, not this site alone.', 'omninox-site-toolkit')
				: __( 'Load average is not exposed by this host.', 'omninox-site-toolkit'),
		);
	}
}
