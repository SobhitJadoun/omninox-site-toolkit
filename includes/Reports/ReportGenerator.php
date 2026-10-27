<?php
/**
 * One-click audit reports in HTML, CSV and PDF.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Reports;

use OmninoxSiteToolkit\Core\ModuleRegistry;
use OmninoxSiteToolkit\Database\ScanRepository;
use OmninoxSiteToolkit\Services\ScoreCalculator;
use OmninoxSiteToolkit\Modules\WhiteLabel\WhiteLabel;
use OmninoxSiteToolkit\License\LicenseManager;

defined( 'ABSPATH' ) || exit;

class ReportGenerator {

	/**
	 * Build a report and return a download URL.
	 *
	 * @param string $format  html|csv|pdf.
	 * @param array  $modules Module ids, empty for all.
	 * @return array|\WP_Error
	 */
	public static function generate( $format = 'html', array $modules = array() ) {
		$registry = ModuleRegistry::instance();
		$ids      = $modules ?: array_keys( $registry->all() );
		$results  = array();

		foreach ( $ids as $id ) {
			$module = $registry->get( $id );

			if ( ! $module || is_wp_error( LicenseManager::instance()->gate( $module ) ) ) {
				continue;
			}

			$result = ScanRepository::latest( $id );

			if ( ! $result ) {
				$result = $module->scan( array( 'report' => true ) );
			}

			$results[ $id ] = $result;
		}

		if ( empty( $results ) ) {
			return new \WP_Error( 'omninox_no_results', __( 'Run at least one scan before generating a report.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		switch ( $format ) {
			case 'json':
				return self::write(
					wp_json_encode(
						array(
							'site'      => home_url(),
							'generated' => current_time( 'mysql' ),
							'plugin'    => 'Omninox Site Toolkit ' . OMNINOX_SITE_TOOLKIT_VERSION,
							'modules'   => $results,
						),
						JSON_PRETTY_PRINT
					),
					'json'
				);

			case 'csv':
				return self::write( self::to_csv( $results ), 'csv' );

			case 'pdf':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'reports', 'pdf' ) ) {
					return new \WP_Error(
						'omninox_pro_required',
						__( 'Executive PDF audit reports require Omninox Pro.', 'omninox-site-toolkit' ),
						array( 'status' => 403 )
					);
				}
				return self::to_pdf( $results );

			default:
				return self::write( self::to_html( $results ), 'html' );
		}
	}

	/**
	 * Save the file into a protected uploads subfolder.
	 *
	 * @param string $contents File contents.
	 * @param string $extension Extension.
	 * @return array|\WP_Error
	 */
	private static function write( $contents, $extension ) {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'omninox-site-toolkit/reports';

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'omninox_report_dir', __( 'The reports folder could not be created inside uploads.', 'omninox-site-toolkit' ), array( 'status' => 500 ) );
		}

		// Keep reports out of directory listings and off the public index.
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$name = sprintf( 'audit-%s-%s.%s', gmdate( 'Y-m-d-His' ), wp_generate_password( 8, false ), $extension );
		$path = $dir . '/' . $name;

		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'file' => $name,
			'url'  => trailingslashit( $uploads['baseurl'] ) . 'omninox-site-toolkit/reports/' . $name,
			'size' => size_format( filesize( $path ) ),
		);
	}

	/**
	 * Flatten results into CSV rows.
	 *
	 * @param array $results Results.
	 * @return string
	 */
	private static function to_csv( array $results ) {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fputcsv( $handle, array( 'Module', 'Score', 'Check', 'Severity', 'Value', 'Expected', 'Notes' ) );

		foreach ( $results as $id => $result ) {
			foreach ( (array) ( $result['issues'] ?? array() ) as $issue ) {
				fputcsv(
					$handle,
					array(
						$result['label'] ?? $id,
						$result['score'] ?? '',
						$issue['label'] ?? '',
						$issue['severity'] ?? '',
						is_scalar( $issue['value'] ?? '' ) ? $issue['value'] : wp_json_encode( $issue['value'] ),
						$issue['expected'] ?? '',
						wp_strip_all_tags( (string) ( $issue['description'] ?? '' ) ),
					)
				);
			}
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	/**
	 * Render the branded HTML report.
	 *
	 * @param array $results Results.
	 * @return string
	 */
	private static function to_html( array $results ) {
		$branding = WhiteLabel::branding();
		$scores   = array();

		foreach ( $results as $id => $result ) {
			$scores[ $id ] = (int) ( $result['score'] ?? 0 );
		}

		$overall = ScoreCalculator::overall( $scores );

		wp_register_style(
			'omninox-site-toolkit-report',
			OMNINOX_SITE_TOOLKIT_URL . 'assets/css/report.css',
			array(),
			OMNINOX_SITE_TOOLKIT_VERSION
		);

		$accent = sanitize_hex_color( $branding['accent'] ?? '#2271b1' );
		if ( ! $accent ) {
			$accent = '#2271b1';
		}
		wp_add_inline_style( 'omninox-site-toolkit-report', ':root { --accent: ' . $accent . '; }' );

		ob_start();
		include OMNINOX_SITE_TOOLKIT_PATH . 'templates/report.php';

		return (string) ob_get_clean();
	}

	/**
	 * PDF export. Uses Dompdf when available, otherwise returns a
	 * print-ready HTML file and says so.
	 *
	 * @param array $results Results.
	 * @return array|\WP_Error
	 */
	private static function to_pdf( array $results ) {
		$html = self::to_html( $results );

		if ( ! class_exists( '\Dompdf\Dompdf' ) && file_exists( OMNINOX_SITE_TOOLKIT_PATH . 'vendor/autoload.php' ) ) {
			require_once OMNINOX_SITE_TOOLKIT_PATH . 'vendor/autoload.php';
		}

		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			$result = self::write( $html, 'html' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['notice'] = __( 'Dompdf is not installed, so a print-ready HTML file was created instead. Open it and use "Save as PDF" in the browser, or run composer install to enable native PDF export.', 'omninox-site-toolkit');

			return $result;
		}

		$dompdf = new \Dompdf\Dompdf( array( 'isRemoteEnabled' => true ) );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		return self::write( $dompdf->output(), 'pdf' );
	}
}
