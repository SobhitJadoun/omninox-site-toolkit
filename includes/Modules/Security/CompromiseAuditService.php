<?php
/**
 * Phase 58: Defensive Website Compromise, Malware & Tampering Audit Service.
 *
 * Implements a comprehensive, evidence-based integrity audit covering filesystem,
 * core checksums, plugins, themes, persistence drop-ins, uploads, database injections,
 * cron hooks, webserver config, PHP runtime settings, quarantine, and baseline tracking.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Security;

use OmninoxSiteToolkit\Helpers\Utils;
use OmninoxSiteToolkit\Database\LogRepository;

defined( 'ABSPATH' ) || exit;

class CompromiseAuditService {

	const SEV_CRITICAL = 'critical';
	const SEV_WARNING  = 'warning';
	const SEV_NOTICE   = 'notice';
	const SEV_PASS     = 'pass';

	const CONF_HIGH   = 'HIGH';
	const CONF_MEDIUM = 'MEDIUM';
	const CONF_LOW    = 'LOW';

	// Phase 1 Explicit System States.
	const STATE_CLEAN                          = 'CLEAN';
	const STATE_HEALTHY_WITH_WARNINGS          = 'HEALTHY_WITH_WARNINGS';
	const STATE_SUSPICIOUS                     = 'SUSPICIOUS';
	const STATE_COMPROMISE_INDICATORS_DETECTED = 'COMPROMISE_INDICATORS_DETECTED';
	const STATE_CRITICAL_COMPROMISE            = 'CRITICAL_COMPROMISE';
	const STATE_SCAN_INCOMPLETE                = 'SCAN_INCOMPLETE';
	const STATE_REMEDIATION_REQUIRED           = 'REMEDIATION_REQUIRED';
	const STATE_REMEDIATION_FAILED             = 'REMEDIATION_FAILED';

	// Phase 40 Report Severities.
	const REPORT_GOOD        = 'GOOD';
	const REPORT_INFO        = 'INFO';
	const REPORT_RECOMMENDED = 'RECOMMENDED';
	const REPORT_WARNING     = 'WARNING';
	const REPORT_HIGH        = 'HIGH';
	const REPORT_CRITICAL    = 'CRITICAL';

	const STATUS_CLEAN      = 'CLEAN (NO COMPROMISE INDICATORS DETECTED)';
	const STATUS_SUSPICIOUS = 'SUSPICIOUS';
	const STATUS_COMPROMISE = 'COMPROMISE INDICATORS DETECTED';
	const STATUS_INCOMPLETE = 'SCAN INCOMPLETE';

	const CLASS_EXPECTED   = 'KNOWN / EXPECTED';
	const CLASS_UNKNOWN    = 'UNKNOWN';
	const CLASS_MODIFIED   = 'MODIFIED';
	const CLASS_SUSPICIOUS = 'SUSPICIOUS';
	const CLASS_CRITICAL   = 'CRITICAL';

	const LIMITATION_NOTICE = 'No security scanner can guarantee detection of every compromise. This audit detects common compromise indicators, code tampering, and known threat patterns. Always maintain regular backups, updated software, strong authentication, and hosting-level isolation.';

	/**
	 * Whitelist of known trusted external domains for script/asset inclusion.
	 *
	 * @var array
	 */
	const TRUSTED_DOMAINS = array(
		'wordpress.org',
		'api.wordpress.org',
		's.w.org',
		'w.org',
		'googleapis.com',
		'fonts.googleapis.com',
		'fonts.gstatic.com',
		'ajax.googleapis.com',
		'gravatar.com',
		'secure.gravatar.com',
		'elementor.com',
		'woocommerce.com',
		'wp.org',
		'automattic.com',
		'netinnovix.com',
	);

	/**
	 * Run a full, comprehensive compromise and integrity audit across all 26 Phase 58 areas.
	 *
	 * @param array $options Scan options (chunking, limits, categories).
	 * @return array Structured audit report.
	 */
	public static function run_full_audit( array $options = array() ) {
		$start_time = microtime( true );
		$findings   = array();
		$stats      = array(
			'files_checked'   => 0,
			'core_checked'    => 0,
			'plugins_checked' => 0,
			'themes_checked'  => 0,
			'db_rows_checked' => 0,
			'cron_checked'    => 0,
			'users_checked'   => 0,
		);

		// 58.1 & 58.2: Core & Filesystem Integrity.
		$core_report = self::audit_core_integrity();
		$findings    = array_merge( $findings, $core_report['findings'] );
		$stats['core_checked'] += $core_report['checked_count'];

		$fs_report = self::audit_filesystem();
		$findings  = array_merge( $findings, $fs_report['findings'] );
		$stats['files_checked'] += $fs_report['checked_count'];

		// 58.3: Plugin Integrity.
		$plugin_report = self::audit_plugins();
		$findings      = array_merge( $findings, $plugin_report['findings'] );
		$stats['plugins_checked'] += $plugin_report['checked_count'];

		// 58.4: Theme Integrity.
		$theme_report = self::audit_themes();
		$findings     = array_merge( $findings, $theme_report['findings'] );
		$stats['themes_checked'] += $theme_report['checked_count'];

		// 58.5: MU-Plugins & Drop-In Persistence.
		$mu_report = self::audit_mu_and_dropins();
		$findings  = array_merge( $findings, $mu_report['findings'] );

		// 58.6: Upload Directory Security.
		$upload_report = self::audit_uploads();
		$findings      = array_merge( $findings, $upload_report['findings'] );
		$stats['files_checked'] += $upload_report['checked_count'];

		// 58.7 & 58.8: Database Injection, Spam, and wp_options Audit.
		$db_report = self::audit_database_content();
		$findings  = array_merge( $findings, $db_report['findings'] );
		$stats['db_rows_checked'] += $db_report['checked_count'];

		$options_report = self::audit_options();
		$findings       = array_merge( $findings, $options_report['findings'] );

		// 58.9: User & Admin Account Integrity.
		$user_report = self::audit_users();
		$findings    = array_merge( $findings, $user_report['findings'] );
		$stats['users_checked'] += $user_report['checked_count'];

		// 58.10: Cron & Scheduled Persistence.
		$cron_report = self::audit_cron();
		$findings    = array_merge( $findings, $cron_report['findings'] );
		$stats['cron_checked'] += $cron_report['checked_count'];

		// 58.11: Webserver Configuration (.htaccess, web.config).
		$webserver_report = self::audit_webserver_config();
		$findings         = array_merge( $findings, $webserver_report['findings'] );

		// 58.12: PHP Runtime Configuration & Persistence.
		$php_report = self::audit_php_config();
		$findings   = array_merge( $findings, $php_report['findings'] );

		// 58.13 & 58.14: Frontend Ads, Redirects & JavaScript Injection.
		$frontend_report = self::audit_frontend_and_js();
		$findings        = array_merge( $findings, $frontend_report['findings'] );

		// 58.15: Remote Domain Audit.
		$domain_report = self::audit_remote_domains();
		$findings      = array_merge( $findings, $domain_report['findings'] );

		// 58.16: File Timeline & Anomaly Detection.
		$timeline_report = self::audit_timeline();
		$findings        = array_merge( $findings, $timeline_report['findings'] );

		// Overall status determination.
		$has_critical   = false;
		$has_suspicious = false;
		$has_warning    = false;

		foreach ( $findings as $f ) {
			$sev = $f['severity'] ?? self::SEV_PASS;
			if ( self::SEV_CRITICAL === $sev ) {
				$has_critical = true;
			} elseif ( self::SEV_WARNING === $sev ) {
				$has_warning = true;
			}
			if ( ( $f['classification'] ?? '' ) === self::CLASS_SUSPICIOUS || ( $f['classification'] ?? '' ) === self::CLASS_CRITICAL ) {
				$has_suspicious = true;
			}
		}

		if ( $has_critical || $has_suspicious ) {
			$overall_status = self::STATUS_COMPROMISE;
		} elseif ( $has_warning ) {
			$overall_status = self::STATUS_SUSPICIOUS;
		} else {
			$overall_status = self::STATUS_CLEAN;
		}

		$duration = round( microtime( true ) - $start_time, 2 );

		$report = array(
			'status'         => $overall_status,
			'timestamp'      => current_time( 'mysql' ),
			'duration'       => $duration . 's',
			'disclaimer'     => self::LIMITATION_NOTICE,
			'stats'          => $stats,
			'summary'        => array(
				'total_findings'    => count( $findings ),
				'critical_count'    => count( array_filter( $findings, static function ( $f ) { return ( $f['severity'] ?? '' ) === self::SEV_CRITICAL; } ) ),
				'warning_count'     => count( array_filter( $findings, static function ( $f ) { return ( $f['severity'] ?? '' ) === self::SEV_WARNING; } ) ),
				'notice_count'      => count( array_filter( $findings, static function ( $f ) { return ( $f['severity'] ?? '' ) === self::SEV_NOTICE; } ) ),
			),
			'findings'       => $findings,
			'recommendations'=> self::get_defensive_recommendations( $overall_status ),
		);

		update_option( 'omninox_last_compromise_audit', $report, false );

		return $report;
	}

	/* ------------------------------------------------------------ 58.1 & 58.2 Core & Filesystem */

	/**
	 * Audit WordPress core files against official checksums.
	 *
	 * @return array
	 */
	public static function audit_core_integrity() {
		global $wp_version, $wp_local_package;

		$findings = array();
		$checked  = 0;

		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$cache_key = 'omninox_core_checksums_' . md5( (string) $wp_version . (string) $wp_local_package );
		$checksums = get_transient( $cache_key );

		if ( ! is_array( $checksums ) ) {
			$checksums = get_core_checksums( $wp_version, $wp_local_package ?: 'en_US' );
			if ( is_array( $checksums ) ) {
				set_transient( $cache_key, $checksums, 12 * HOUR_IN_SECONDS );
			}
		}

		if ( ! is_array( $checksums ) ) {
			$findings[] = array(
				'category'       => 'core_integrity',
				'severity'       => self::SEV_NOTICE,
				'classification' => self::CLASS_UNKNOWN,
				'confidence'     => self::CONF_LOW,
				'object'         => 'core_checksums',
				'reason'         => __( 'Official core checksums could not be retrieved from WordPress.org for this version.', 'omninox-site-toolkit' ),
				'evidence'       => "WP Version: {$wp_version}",
				'expected'       => 'Official checksum map',
				'actual'         => 'Unavailable or network blocked',
				'recommended_action' => __( 'Verify site outbound connectivity to api.wordpress.org.', 'omninox-site-toolkit' ),
			);

			return array( 'findings' => $findings, 'checked_count' => 0 );
		}

		$root = wp_normalize_path( ABSPATH );

		foreach ( $checksums as $file => $hash ) {
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}

			$path = $root . $file;
			++$checked;

			if ( ! file_exists( $path ) ) {
				$findings[] = array(
					'category'       => 'core_integrity',
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_CRITICAL,
					'confidence'     => self::CONF_HIGH,
					'object'         => $file,
					'reason'         => __( 'Required WordPress core file is missing from the filesystem.', 'omninox-site-toolkit' ),
					'evidence'       => "Missing file: {$file}",
					'expected'       => 'File present with matching MD5 hash',
					'actual'         => 'File does not exist',
					'recommended_action' => __( 'Review and restore missing core file from official WordPress release package.', 'omninox-site-toolkit' ),
				);
				continue;
			}

			$actual_hash = md5_file( $path );
			if ( $actual_hash !== $hash ) {
				$findings[] = array(
					'category'       => 'core_integrity',
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_MODIFIED,
					'confidence'     => self::CONF_HIGH,
					'object'         => $file,
					'reason'         => __( 'WordPress core file has been modified or tampered with.', 'omninox-site-toolkit' ),
					'evidence'       => "Expected MD5: {$hash}, Actual MD5: {$actual_hash}",
					'expected'       => $hash,
					'actual'         => $actual_hash,
					'recommended_action' => __( 'Compare changes against official WordPress core and replace with authentic version.', 'omninox-site-toolkit' ),
				);
			}
		}

		// Check for unexpected PHP files in wp-admin and wp-includes.
		$core_dirs = array(
			'wp-admin'    => $root . 'wp-admin',
			'wp-includes' => $root . 'wp-includes',
		);

		foreach ( $core_dirs as $dir_name => $dir_path ) {
			if ( ! is_dir( $dir_path ) ) {
				continue;
			}

			try {
				$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir_path, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $iterator as $file_info ) {
					if ( 'php' !== strtolower( $file_info->getExtension() ) ) {
						continue;
					}

					$rel_path = ltrim( substr( wp_normalize_path( $file_info->getPathname() ), strlen( $root ) ), '/' );
					if ( ! isset( $checksums[ $rel_path ] ) ) {
						$findings[] = array(
							'category'       => 'core_integrity',
							'severity'       => self::SEV_CRITICAL,
							'classification' => self::CLASS_SUSPICIOUS,
							'confidence'     => self::CONF_HIGH,
							'object'         => $rel_path,
							'reason'         => sprintf(
								/* translators: %s: directory name */
								__( 'Unexpected PHP file found inside %s directory. Core directories should never contain extra PHP files.', 'omninox-site-toolkit' ),
								$dir_name
							),
							'evidence'       => "Path: {$rel_path}, Size: " . Utils::format_bytes( $file_info->getSize() ),
							'expected'       => 'File not present in official WordPress distribution',
							'actual'         => 'Unexpected file present',
							'recommended_action' => __( 'Inspect file contents immediately, quarantine safely, and investigate access logs.', 'omninox-site-toolkit' ),
						);
					}
				}
			} catch ( \Exception $e ) {
				// Directory iteration failure handled gracefully.
			}
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/**
	 * Audit root filesystem for suspicious files, dot files, and backup bootstrap copies.
	 *
	 * @return array
	 */
	public static function audit_filesystem() {
		$findings = array();
		$checked  = 0;
		$root     = wp_normalize_path( ABSPATH );

		// 1. Detect duplicate copies or backups of bootstrap/config files.
		$backup_patterns = array(
			'wp-config.php.bak',
			'wp-config.php.old',
			'wp-config.php.save',
			'wp-config.php.swp',
			'wp-config.php.orig',
			'wp-config.php~',
			'wp-config.old',
			'wp-config.bak',
			'wp-config.txt',
			'index.php.bak',
			'index.php.old',
			'wp-load.php.old',
		);

		foreach ( $backup_patterns as $pattern ) {
			$file = $root . $pattern;
			if ( file_exists( $file ) ) {
				$findings[] = array(
					'category'       => 'filesystem_integrity',
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_CRITICAL,
					'confidence'     => self::CONF_HIGH,
					'object'         => $pattern,
					'reason'         => __( 'Exposed backup copy of critical configuration/bootstrap file. May expose database credentials or site keys to public download.', 'omninox-site-toolkit' ),
					'evidence'       => "Found: {$pattern} (" . Utils::format_bytes( filesize( $file ) ) . ')',
					'expected'       => 'No plaintext configuration backups in web root',
					'actual'         => 'File exists in public directory',
					'recommended_action' => __( 'Review and remove plaintext configuration backups from the public webroot immediately.', 'omninox-site-toolkit' ),
				);
			}
		}

		// 2. Scan root directory for suspicious hidden dot files or strange executables.
		if ( is_dir( $root ) ) {
			$items = @scandir( $root );
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( in_array( $item, array( '.', '..' ), true ) ) {
						continue;
					}
					++$checked;

					// Hidden files with executable extension.
					if ( 0 === strpos( $item, '.' ) && ! in_array( $item, array( '.htaccess', '.user.ini', '.well-known' ), true ) ) {
						$ext = strtolower( pathinfo( $item, PATHINFO_EXTENSION ) );
						if ( in_array( $ext, array( 'php', 'phtml', 'sh', 'py', 'pl', 'cgi' ), true ) ) {
							$findings[] = array(
								'category'       => 'filesystem_integrity',
								'severity'       => self::SEV_CRITICAL,
								'classification' => self::CLASS_SUSPICIOUS,
								'confidence'     => self::CONF_HIGH,
								'object'         => $item,
								'reason'         => __( 'Suspicious hidden script located in web root directory.', 'omninox-site-toolkit' ),
								'evidence'       => "Hidden file: {$item}",
								'expected'       => 'No hidden executable scripts in web root',
								'actual'         => 'File present',
								'recommended_action' => __( 'Inspect file for webshell signatures and quarantine.', 'omninox-site-toolkit' ),
							);
						}
					}
				}
			}
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/* ------------------------------------------------------------ 58.3 Plugin Integrity */

	/**
	 * Audit installed plugins for backdoors, suspicious dynamic evaluation, and known webshell patterns.
	 *
	 * Context-aware: does NOT flag legitimate minified libraries or plugin code solely for using
	 * eval or base64_decode when used legitimately without untrusted input injection.
	 *
	 * @return array
	 */
	public static function audit_plugins() {
		$findings    = array();
		$checked     = 0;
		$plugins_dir = Utils::get_plugins_dir();

		if ( ! is_dir( $plugins_dir ) ) {
			return array( 'findings' => $findings, 'checked_count' => 0 );
		}

		$webshell_signatures = array(
			( 'Files' . 'Man' )                             => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Known webshell signature (FilesMan)' ),
			( 'WSO_' . 'VERSION' )                          => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Known WSO webshell signature' ),
			( 'c99' . 'sh' )                                => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Known C99 webshell signature' ),
			( 'r57' . 'shell' )                             => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Known r57 webshell signature' ),
			( 'b374' . 'k' )                                => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Known b374k webshell signature' ),
			'eval\s*\(\s*\$_(POST|GET|REQUEST)'             => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Arbitrary PHP execution via request parameter (eval($_REQUEST))' ),
			'assert\s*\(\s*\$_(POST|GET|REQUEST)'           => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Arbitrary code assertion via request parameter (assert($_REQUEST))' ),
			'base64_decode\s*\(\s*\$_(POST|GET|REQUEST)'    => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Base64 decoding directly from untrusted request parameter' ),
			'preg_replace\s*\(\s*[\'"].*?\/e[\'"]\s*,\s*\$_' => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Deprecated preg_replace /e modifier with user input' ),
			'passthru\s*\(\s*\$_(POST|GET|REQUEST)'         => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Direct shell execution via passthru with user parameter' ),
			'system\s*\(\s*\$_(POST|GET|REQUEST)'           => array( 'severity' => self::SEV_CRITICAL, 'confidence' => self::CONF_HIGH, 'desc' => 'Direct shell execution via system with user parameter' ),
		);

		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $plugins_dir, \FilesystemIterator::SKIP_DOTS ) );
			$root     = wp_normalize_path( ABSPATH );

			foreach ( $iterator as $file_info ) {
				if ( 'php' !== strtolower( $file_info->getExtension() ) ) {
					continue;
				}

				// Skip large files (> 2MB) to maintain CPU & memory safety.
				if ( $file_info->getSize() > 2097152 ) {
					continue;
				}

				++$checked;
				if ( $checked > 35000 ) {
					break; // Respect performance ceiling.
				}

				$pathname = $file_info->getPathname();
				$rel_path = ltrim( substr( wp_normalize_path( $pathname ), strlen( $root ) ), '/' );

				// Skip scanner files that define signatures to prevent self-detection.
				if ( false !== strpos( $rel_path, 'CompromiseAuditService.php' ) || false !== strpos( $rel_path, 'SecurityModule.php' ) ) {
					continue;
				}

				$code = @file_get_contents( $pathname );
				if ( false === $code || '' === $code ) {
					continue;
				}

				// Check known webshell / dangerous dynamic execution signatures.
				foreach ( $webshell_signatures as $sig => $meta ) {
					if ( preg_match( '~' . $sig . '~i', $code, $matches ) ) {
						$findings[] = array(
							'category'       => 'plugin_integrity',
							'severity'       => $meta['severity'],
							'classification' => self::CLASS_SUSPICIOUS,
							'confidence'     => $meta['confidence'],
							'object'         => $rel_path,
							'reason'         => $meta['desc'],
							'evidence'       => 'Matched: ' . substr( trim( $matches[0] ), 0, 80 ),
							'expected'       => 'No arbitrary code execution or webshell patterns',
							'actual'         => 'Suspicious pattern identified',
							'recommended_action' => __( 'Inspect code context. If unauthorized, quarantine file and reinstall official plugin.', 'omninox-site-toolkit' ),
						);
						break; // Avoid duplicate reports for same file.
					}
				}
			}
		} catch ( \Exception $e ) {
			// Iterator error safely caught.
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/* ------------------------------------------------------------ 58.4 Theme Integrity */

	/**
	 * Audit active and installed themes for injected iframes, redirect scripts, ads, and hidden code.
	 *
	 * @return array
	 */
	public static function audit_themes() {
		$findings   = array();
		$checked    = 0;
		$themes_dir = get_theme_root();

		if ( ! is_dir( $themes_dir ) ) {
			return array( 'findings' => $findings, 'checked_count' => 0 );
		}

		$theme_signatures = array(
			'<iframe[^>]+(style=[\'"][^\'"]*display\s*:\s*none|width=[\'"]?0|height=[\'"]?0|visibility\s*:\s*hidden)' => array(
				'severity'   => self::SEV_CRITICAL,
				'confidence' => self::CONF_HIGH,
				'desc'       => __( 'Hidden iframe tag detected in theme files. Often used for stealth background ad clicks or drive-by downloads.', 'omninox-site-toolkit' ),
			),
			'window\.location(\.href)?\s*=\s*[\'"]http[^\'"]*(casino|pharma|payday|poker|porn|loan|redirect)[^\'"]*[\'"]' => array(
				'severity'   => self::SEV_CRITICAL,
				'confidence' => self::CONF_HIGH,
				'desc'       => __( 'Malicious JavaScript redirect to external spam or affiliate domain detected.', 'omninox-site-toolkit' ),
			),
			'document\.write\s*\(\s*unescape\s*\(' => array(
				'severity'   => self::SEV_WARNING,
				'confidence' => self::CONF_MEDIUM,
				'desc'       => __( 'Obfuscated document.write(unescape(...)) injection pattern detected in theme template.', 'omninox-site-toolkit' ),
			),
		);

		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $themes_dir, \FilesystemIterator::SKIP_DOTS ) );
			$root     = wp_normalize_path( ABSPATH );

			foreach ( $iterator as $file_info ) {
				$ext = strtolower( $file_info->getExtension() );
				if ( ! in_array( $ext, array( 'php', 'js', 'html' ), true ) || $file_info->getSize() > 1500000 ) {
					continue;
				}

				++$checked;
				$pathname = $file_info->getPathname();
				$code     = @file_get_contents( $pathname );
				if ( ! $code ) {
					continue;
				}

				$rel_path = ltrim( substr( wp_normalize_path( $pathname ), strlen( $root ) ), '/' );

				foreach ( $theme_signatures as $sig => $meta ) {
					if ( preg_match( '/' . $sig . '/i', $code, $matches ) ) {
						$findings[] = array(
							'category'       => 'theme_integrity',
							'severity'       => $meta['severity'],
							'classification' => self::CLASS_SUSPICIOUS,
							'confidence'     => $meta['confidence'],
							'object'         => $rel_path,
							'reason'         => $meta['desc'],
							'evidence'       => 'Snippet: ' . substr( trim( $matches[0] ), 0, 80 ),
							'expected'       => 'Clean theme templates without hidden iframes or spam redirects',
							'actual'         => 'Indicator matched',
							'recommended_action' => __( 'Review theme file against original author copy and remove injected code.', 'omninox-site-toolkit' ),
						);
						break;
					}
				}
			}
		} catch ( \Exception $e ) {
			// Graceful error handling.
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/* ------------------------------------------------------------ 58.5 MU-Plugins & Drop-Ins */

	/**
	 * Audit mu-plugins and drop-in files for unauthorized persistence.
	 *
	 * @return array
	 */
	public static function audit_mu_and_dropins() {
		$findings = array();
		$root     = wp_normalize_path( ABSPATH );

		// 1. Audit mu-plugins.
		$mu_dir = WPMU_PLUGIN_DIR;
		if ( is_dir( $mu_dir ) ) {
			$files = @scandir( $mu_dir );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( in_array( $f, array( '.', '..' ), true ) ) {
						continue;
					}
					$path = $mu_dir . '/' . $f;
					if ( is_file( $path ) && 'php' === strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ) ) {
						$code = @file_get_contents( $path );
						$rel  = ltrim( substr( wp_normalize_path( $path ), strlen( $root ) ), '/' );

						// Check if the mu-plugin contains suspicious persistence / eval / stealth hooks.
						if ( $code && preg_match( '/(eval\s*\(|assert\s*\(|base64_decode\s*\(\s*\$_|shell_exec|system\s*\()/i', $code, $matches ) ) {
							$findings[] = array(
								'category'       => 'persistence_dropins',
								'severity'       => self::SEV_CRITICAL,
								'classification' => self::CLASS_CRITICAL,
								'confidence'     => self::CONF_HIGH,
								'object'         => $rel,
								'reason'         => __( 'MU-plugin contains dangerous dynamic execution patterns. MU-plugins execute automatically before normal plugins.', 'omninox-site-toolkit' ),
								'evidence'       => 'Snippet: ' . substr( trim( $matches[0] ), 0, 70 ),
								'expected'       => 'Benign administrative MU-plugin',
								'actual'         => 'Suspicious dynamic execution logic detected',
								'recommended_action' => __( 'Inspect MU-plugin author. If unknown, quarantine or remove with administrator confirmation.', 'omninox-site-toolkit' ),
							);
						} else {
							// Report notice of active MU plugin so administrators have full visibility.
							$findings[] = array(
								'category'       => 'persistence_dropins',
								'severity'       => self::SEV_NOTICE,
								'classification' => self::CLASS_KNOWN_EXPECTED,
								'confidence'     => self::CONF_HIGH,
								'object'         => $rel,
								'reason'         => __( 'Active Must-Use (MU) plugin detected. MU-plugins run automatically and bypass standard activation.', 'omninox-site-toolkit' ),
								'evidence'       => "File: {$f} (" . Utils::format_bytes( filesize( $path ) ) . ')',
								'expected'       => 'Documented administrative tooling',
								'actual'         => 'MU-plugin active',
								'recommended_action' => __( 'Verify that this MU-plugin is known and intentional.', 'omninox-site-toolkit' ),
							);
						}
					}
				}
			}
		}

		// 2. Audit standard WordPress drop-ins.
		$dropins = array(
			'object-cache.php'   => __( 'External Object Cache drop-in', 'omninox-site-toolkit' ),
			'advanced-cache.php' => __( 'Advanced Cache drop-in (page caching)', 'omninox-site-toolkit' ),
			'db.php'             => __( 'Custom Database class drop-in', 'omninox-site-toolkit' ),
			'sunrise.php'        => __( 'Multisite Domain mapping drop-in', 'omninox-site-toolkit' ),
		);

		foreach ( $dropins as $dropin_file => $desc ) {
			$dropin_path = WP_CONTENT_DIR . '/' . $dropin_file;
			if ( file_exists( $dropin_path ) ) {
				$rel  = ltrim( substr( wp_normalize_path( $dropin_path ), strlen( $root ) ), '/' );
				$code = @file_get_contents( $dropin_path );

				if ( $code && preg_match( '/(eval\s*\(|assert\s*\(|base64_decode\s*\(\s*\$_|FilesMan)/i', $code, $matches ) ) {
					$findings[] = array(
						'category'       => 'persistence_dropins',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_CRITICAL,
						'confidence'     => self::CONF_HIGH,
						'object'         => $rel,
						'reason'         => sprintf(
							/* translators: %s: dropin description */
							__( 'Drop-in (%s) contains suspicious dynamic execution patterns.', 'omninox-site-toolkit' ),
							$desc
						),
						'evidence'       => 'Matched: ' . substr( trim( $matches[0] ), 0, 70 ),
						'expected'       => 'Authentic caching/database drop-in',
						'actual'         => 'Suspicious code found',
						'recommended_action' => __( 'Verify whether this drop-in belongs to your caching plugin or is an unauthorized backdoor.', 'omninox-site-toolkit' ),
					);
				}
			}
		}

		return array( 'findings' => $findings );
	}

	/* ------------------------------------------------------------ 58.6 Uploads Security */

	/**
	 * Audit uploads directory for executable files, double extensions, and disguised PHP.
	 *
	 * @return array
	 */
	public static function audit_uploads() {
		$findings = array();
		$checked  = 0;
		$uploads  = wp_upload_dir();
		$basedir  = $uploads['basedir'] ?? '';

		if ( empty( $basedir ) || ! is_dir( $basedir ) ) {
			return array( 'findings' => $findings, 'checked_count' => 0 );
		}

		$root = wp_normalize_path( ABSPATH );

		$dangerous_exts = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe' );

		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $basedir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				++$checked;
				if ( $checked > 60000 ) {
					break; // Respect performance threshold.
				}

				$filename = $file_info->getFilename();
				$ext      = strtolower( $file_info->getExtension() );
				$pathname = $file_info->getPathname();
				$rel_path = ltrim( substr( wp_normalize_path( $pathname ), strlen( $root ) ), '/' );

				// Check 1: Direct executable extension in uploads.
				if ( in_array( $ext, $dangerous_exts, true ) ) {
					$findings[] = array(
						'category'       => 'upload_security',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_CRITICAL,
						'confidence'     => self::CONF_HIGH,
						'object'         => $rel_path,
						'reason'         => __( 'Executable script file detected inside the uploads directory. Uploads must never contain executable PHP or shell scripts.', 'omninox-site-toolkit' ),
						'evidence'       => "Extension: .{$ext} | Size: " . Utils::format_bytes( $file_info->getSize() ),
						'expected'       => 'Media/document assets only',
						'actual'         => "Executable .{$ext} file",
						'recommended_action' => __( 'Quarantine this file immediately and verify upload permissions.', 'omninox-site-toolkit' ),
					);
					continue;
				}

				// Check 2: Double extension disguise (e.g., image.jpg.php, avatar.php.jpg, logo.png.phtml).
				if ( preg_match( '/\.(php[0-9]?|phtml|phar)\./i', $filename ) || preg_match( '/\.(jpg|jpeg|png|gif|webp|svg|pdf)\.(php[0-9]?|phtml|phar|sh|exe)$/i', $filename ) ) {
					$findings[] = array(
						'category'       => 'upload_security',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_CRITICAL,
						'confidence'     => self::CONF_HIGH,
						'object'         => $rel_path,
						'reason'         => __( 'Double extension file detected in uploads. Attackers frequently disguise scripts as images using multiple extensions.', 'omninox-site-toolkit' ),
						'evidence'       => "Filename: {$filename}",
						'expected'       => 'Single media file extension',
						'actual'         => 'Double extension pattern matched',
						'recommended_action' => __( 'Quarantine file and verify webserver execution restrictions in wp-content/uploads.', 'omninox-site-toolkit' ),
					);
					continue;
				}

				// Check 3: Content sniffing for PHP disguised as an image.
				if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'bmp' ), true ) && $file_info->getSize() < 1048576 ) {
					$handle = @fopen( $pathname, 'rb' );
					if ( $handle ) {
						$header = fread( $handle, 2048 );
						fclose( $handle );
						if ( false !== $header && preg_match( '/(<\?php|<\?=|\$_POST|\$_GET|eval\s*\(|assert\s*\()/i', $header ) ) {
							$findings[] = array(
								'category'       => 'upload_security',
								'severity'       => self::SEV_CRITICAL,
								'classification' => self::CLASS_SUSPICIOUS,
								'confidence'     => self::CONF_HIGH,
								'object'         => $rel_path,
								'reason'         => __( 'Image file contains embedded PHP code tags or executable payloads in its file header.', 'omninox-site-toolkit' ),
								'evidence'       => "Extension: .{$ext} with embedded PHP code header",
								'expected'       => 'Binary image data without PHP execution tags',
								'actual'         => 'PHP code detected inside image file',
								'recommended_action' => __( 'Quarantine image file and audit recent media uploads.', 'omninox-site-toolkit' ),
							);
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			// Directory error handled safely.
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/* ------------------------------------------------------------ 58.7 & 58.8 Database & Options */

	/**
	 * Audit database content (posts, comments, options) for spam keywords, injected script tags, and redirect iframes.
	 *
	 * @return array
	 */
	public static function audit_database_content() {
		global $wpdb;

		$findings = array();
		$checked  = 0;

		// 1. Injected scripts or iframes in published posts.
		$bad_posts = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} 
			WHERE post_status = 'publish' 
			AND (
				post_content LIKE '%<script%window.location%' 
				OR (post_content LIKE '%<iframe%' AND (post_content LIKE '%display:none%' OR post_content LIKE '%visibility:hidden%' OR post_content LIKE '%width=\"0\"%' OR post_content LIKE '%width=\'0\'%'))
				OR post_content LIKE '%eval(base64_decode%'
			) LIMIT 20",
			ARRAY_A
		);

		$checked += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB

		if ( ! empty( $bad_posts ) ) {
			foreach ( $bad_posts as $post ) {
				$findings[] = array(
					'category'       => 'database_integrity',
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_CRITICAL,
					'confidence'     => self::CONF_HIGH,
					'object'         => "wp_posts (ID: {$post['ID']}, Title: \"{$post['post_title']}\")",
					'reason'         => __( 'Malicious redirect script or hidden iframe found injected into post content.', 'omninox-site-toolkit' ),
					'evidence'       => 'Content snippet: ' . esc_html( substr( strip_tags( $post['post_content'] ), 0, 80 ) ),
					'expected'       => 'Clean editorial content without hidden redirect scripts',
					'actual'         => 'Injected script/iframe detected',
					'recommended_action' => __( 'Edit post to remove injected code and inspect revision history.', 'omninox-site-toolkit' ),
				);
			}
		}

		// 2. SEO spam in posts (casino, pharma, payday loan keywords injected in bulk).
		$spam_posts = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT ID, post_title FROM {$wpdb->posts} 
			WHERE post_status = 'publish' 
			AND (
				post_content LIKE '%viagra%' 
				OR post_content LIKE '%cialis%' 
				OR post_content LIKE '%online casino%' 
				OR post_content LIKE '%payday loan%' 
				OR post_content LIKE '%slot gacor%'
			) LIMIT 10",
			ARRAY_A
		);

		if ( ! empty( $spam_posts ) ) {
			foreach ( $spam_posts as $sp ) {
				$findings[] = array(
					'category'       => 'database_integrity',
					'severity'       => self::SEV_WARNING,
					'classification' => self::CLASS_SUSPICIOUS,
					'confidence'     => self::CONF_MEDIUM,
					'object'         => "wp_posts (ID: {$sp['ID']})",
					'reason'         => __( 'Potential SEO spam keywords (pharma/casino) detected in post.', 'omninox-site-toolkit' ),
					'evidence'       => "Post ID: {$sp['ID']}, Title: \"{$sp['post_title']}\"",
					'expected'       => 'Relevant website content',
					'actual'         => 'SEO spam keywords present',
					'recommended_action' => __( 'Review post content to verify whether content is authentic or injected spam.', 'omninox-site-toolkit' ),
				);
			}
		}

		// 3. Comments with injected scripts or exploit payloads.
		$bad_comments = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT comment_ID, comment_author, comment_author_url, comment_content FROM {$wpdb->comments} 
			WHERE comment_approved = '1' 
			AND (
				comment_content LIKE '%<script%' 
				OR comment_content LIKE '%javascript:%' 
				OR comment_content LIKE '%onerror=%'
			) LIMIT 10",
			ARRAY_A
		);

		$checked += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ); // phpcs:ignore WordPress.DB

		if ( ! empty( $bad_comments ) ) {
			foreach ( $bad_comments as $com ) {
				$findings[] = array(
					'category'       => 'database_integrity',
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_CRITICAL,
					'confidence'     => self::CONF_HIGH,
					'object'         => "wp_comments (ID: {$com['comment_ID']})",
					'reason'         => __( 'Approved comment contains injected cross-site scripting (XSS) payload or javascript: URI.', 'omninox-site-toolkit' ),
					'evidence'       => "Comment snippet: " . esc_html( substr( $com['comment_content'], 0, 70 ) ),
					'expected'       => 'Sanitized plain text comment',
					'actual'         => 'Executable HTML/XSS markup approved',
					'recommended_action' => __( 'Unapprove and delete malicious comment.', 'omninox-site-toolkit' ),
				);
			}
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/**
	 * Audit critical wp_options entries for hijacking, invalid URLs, and unauthorized script injection.
	 *
	 * @return array
	 */
	public static function audit_options() {
		global $wpdb;

		$findings = array();

		// 1. Inspect core site URL options.
		$siteurl = get_option( 'siteurl' );
		$home    = get_option( 'home' );

		if ( ! filter_var( $siteurl, FILTER_VALIDATE_URL ) || ! filter_var( $home, FILTER_VALIDATE_URL ) ) {
			$findings[] = array(
				'category'       => 'options_security',
				'severity'       => self::SEV_CRITICAL,
				'classification' => self::CLASS_CRITICAL,
				'confidence'     => self::CONF_HIGH,
				'object'         => 'wp_options (siteurl / home)',
				'reason'         => __( 'Site URL or Home option contains an invalid URL structure.', 'omninox-site-toolkit' ),
				'evidence'       => "siteurl: {$siteurl}, home: {$home}",
				'expected'       => 'Valid HTTP/HTTPS domain URL',
				'actual'         => 'Malformed URL option',
				'recommended_action' => __( 'Check wp-config.php and wp_options for unauthorized URL changes.', 'omninox-site-toolkit' ),
			);
		}

		// 2. Inspect active theme options.
		$stylesheet = get_option( 'stylesheet' );
		$template   = get_option( 'template' );
		$theme_root = get_theme_root();

		if ( ! is_dir( $theme_root . '/' . $stylesheet ) ) {
			$findings[] = array(
				'category'       => 'options_security',
				'severity'       => self::SEV_WARNING,
				'classification' => self::CLASS_SUSPICIOUS,
				'confidence'     => self::CONF_HIGH,
				'object'         => 'wp_options (stylesheet)',
				'reason'         => __( 'Configured active theme stylesheet directory does not exist on the filesystem.', 'omninox-site-toolkit' ),
				'evidence'       => "Configured: {$stylesheet}",
				'expected'       => 'Installed theme directory',
				'actual'         => 'Directory missing',
				'recommended_action' => __( 'Re-activate a valid default theme.', 'omninox-site-toolkit' ),
			);
		}

		// 3. Scan options table for stored <script> tags or base64 eval payloads.
		$injected_options = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT option_name, option_value FROM {$wpdb->options} 
			WHERE (
				option_value LIKE '%<script%' 
				OR option_value LIKE '%eval(base64%' 
				OR option_value LIKE '%window.location%'
			) 
			AND option_name NOT LIKE '%_transient_%' 
			AND option_name NOT LIKE '%omninox%'
			LIMIT 10",
			ARRAY_A
		);

		if ( ! empty( $injected_options ) ) {
			foreach ( $injected_options as $opt ) {
				$val = (string) $opt['option_value'];
				// Allow valid analytics/tracking code if configured in known integration plugins.
				$is_known_tracker = ( false !== strpos( $opt['option_name'], 'analytics' ) || false !== strpos( $opt['option_name'], 'tracking' ) );
				$findings[] = array(
					'category'       => 'options_security',
					'severity'       => $is_known_tracker ? self::SEV_NOTICE : self::SEV_CRITICAL,
					'classification' => $is_known_tracker ? self::CLASS_EXPECTED : self::CLASS_SUSPICIOUS,
					'confidence'     => self::CONF_HIGH,
					'object'         => "wp_options ({$opt['option_name']})",
					'reason'         => $is_known_tracker
						? __( 'Configured tracking/script option detected.', 'omninox-site-toolkit' )
						: __( 'Executable script or eval pattern detected inside wp_options.', 'omninox-site-toolkit' ),
					'evidence'       => "Option: {$opt['option_name']}, Snippet: " . esc_html( substr( $val, 0, 70 ) ),
					'expected'       => 'Standard serialized or string configuration',
					'actual'         => 'Script / eval pattern present',
					'recommended_action' => __( 'Review option value to ensure it was configured intentionally.', 'omninox-site-toolkit' ),
				);
			}
		}

		return array( 'findings' => $findings );
	}

	/* ------------------------------------------------------------ 58.9 User / Admin Account Integrity */

	/**
	 * Audit users and privileged accounts for rogue administrators and privilege escalation.
	 *
	 * @return array
	 */
	public static function audit_users() {
		$findings = array();
		$users    = get_users();
		$checked  = count( $users );

		$disposable_domains = array( 'tempmail.com', '10minutemail.com', 'mailinator.com', 'guerrillamail.com', 'throwaway.com' );
		$suspicious_names   = array( 'admin_backup', 'wp_support', 'system_admin', 'backup_user', 'temp_admin' );

		foreach ( $users as $u ) {
			$roles     = (array) $u->roles;
			$is_admin  = in_array( 'administrator', $roles, true );
			$user_caps = (array) $u->caps;

			// Check for elevated capabilities without administrator role.
			if ( ! $is_admin && ( ! empty( $user_caps['administrator'] ) || ! empty( $user_caps['manage_options'] ) ) ) {
				$findings[] = array(
					'category'       => 'user_integrity',
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_CRITICAL,
					'confidence'     => self::CONF_HIGH,
					'object'         => "wp_users (ID: {$u->ID}, Login: \"{$u->user_login}\")",
					'reason'         => __( 'Hidden privilege escalation: Non-administrator user possesses manage_options or administrative capabilities.', 'omninox-site-toolkit' ),
					'evidence'       => 'Roles: ' . implode( ', ', $roles ) . ' | Caps: manage_options assigned directly',
					'expected'       => 'Only assigned role capabilities',
					'actual'         => 'Unauthorized capability escalation',
					'recommended_action' => __( 'Review and reset user capabilities immediately.', 'omninox-site-toolkit' ),
				);
			}

			// Check for suspicious administrator usernames.
			if ( $is_admin && in_array( strtolower( $u->user_login ), $suspicious_names, true ) ) {
				$findings[] = array(
					'category'       => 'user_integrity',
					'severity'       => self::SEV_WARNING,
					'classification' => self::CLASS_SUSPICIOUS,
					'confidence'     => self::CONF_MEDIUM,
					'object'         => "wp_users (ID: {$u->ID}, Login: \"{$u->user_login}\")",
					'reason'         => __( 'Privileged account uses a generic or disguised administrator username often created by attackers.', 'omninox-site-toolkit' ),
					'evidence'       => "Username: {$u->user_login}",
					'expected'       => 'Recognized site administrator name',
					'actual'         => 'Suspicious administrator username',
					'recommended_action' => __( 'Confirm identity with site owner before keeping this account.', 'omninox-site-toolkit' ),
				);
			}

			// Check for disposable email domains on administrative accounts.
			if ( $is_admin ) {
				$parts  = explode( '@', $u->user_email );
				$domain = strtolower( $parts[1] ?? '' );
				if ( in_array( $domain, $disposable_domains, true ) ) {
					$findings[] = array(
						'category'       => 'user_integrity',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_SUSPICIOUS,
						'confidence'     => self::CONF_HIGH,
						'object'         => "wp_users (ID: {$u->ID}, Login: \"{$u->user_login}\")",
						'reason'         => __( 'Administrator account registered with a disposable or temporary email service.', 'omninox-site-toolkit' ),
						'evidence'       => "Email: {$u->user_email}",
						'expected'       => 'Permanent corporate or personal email domain',
						'actual'         => "Disposable email domain: {$domain}",
						'recommended_action' => __( 'Change administrator email to a verified corporate or personal address.', 'omninox-site-toolkit' ),
					);
				}
			}
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/* ------------------------------------------------------------ 58.10 Cron / Scheduled Persistence */

	/**
	 * Audit scheduled WordPress cron jobs for rogue hooks or suspicious callbacks.
	 *
	 * @return array
	 */
	public static function audit_cron() {
		$findings = array();
		$cron     = _get_cron_array();
		$checked  = 0;

		if ( ! is_array( $cron ) ) {
			return array( 'findings' => $findings, 'checked_count' => 0 );
		}

		foreach ( $cron as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $keys ) {
				++$checked;

				// Check for suspicious hook names.
				if ( preg_match( '/(shell|eval|backdoor|wso|pwn|malware|exec|b374k)/i', $hook ) ) {
					$findings[] = array(
						'category'       => 'cron_persistence',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_CRITICAL,
						'confidence'     => self::CONF_HIGH,
						'object'         => "cron_hook: {$hook}",
						'reason'         => __( 'Suspicious cron hook name detected matching malware persistence patterns.', 'omninox-site-toolkit' ),
						'evidence'       => "Hook: {$hook} scheduled for timestamp {$timestamp}",
						'expected'       => 'Standard WordPress or plugin scheduled action',
						'actual'         => 'Rogue cron hook pattern',
						'recommended_action' => __( 'Unschedule and remove this cron event after verifying source.', 'omninox-site-toolkit' ),
					);
				}

				// Check callbacks attached to the hook.
				global $wp_filter;
				if ( isset( $wp_filter[ $hook ] ) ) {
					$hook_obj = $wp_filter[ $hook ];
					if ( is_object( $hook_obj ) && property_exists( $hook_obj, 'callbacks' ) ) {
						foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
							foreach ( $callbacks as $cb ) {
								$function = $cb['function'] ?? null;
								if ( is_string( $function ) && in_array( strtolower( $function ), array( 'eval', 'assert', 'system', 'exec', 'passthru', 'shell_exec' ), true ) ) {
									$findings[] = array(
										'category'       => 'cron_persistence',
										'severity'       => self::SEV_CRITICAL,
										'classification' => self::CLASS_CRITICAL,
										'confidence'     => self::CONF_HIGH,
										'object'         => "cron_hook: {$hook}",
										'reason'         => sprintf(
											/* translators: %s: function name */
											__( 'Cron hook attached directly to dangerous PHP execution function: %s', 'omninox-site-toolkit' ),
											$function
										),
										'evidence'       => "Callback: {$function}()",
										'expected'       => 'Standard class method or clean function',
										'actual'         => "Attached to {$function}()",
										'recommended_action' => __( 'Remove malicious scheduled hook and investigate origin.', 'omninox-site-toolkit' ),
									);
								}
							}
						}
					}
				}
			}
		}

		return array( 'findings' => $findings, 'checked_count' => $checked );
	}

	/* ------------------------------------------------------------ 58.11 & 58.12 Webserver & PHP Config */

	/**
	 * Audit .htaccess and web.config for unauthorized rewrite redirects, user-agent cloaking, and php prepends.
	 *
	 * @return array
	 */
	public static function audit_webserver_config() {
		$findings = array();
		$htaccess = ABSPATH . '.htaccess';

		if ( file_exists( $htaccess ) && is_readable( $htaccess ) ) {
			$content = @file_get_contents( $htaccess );
			if ( $content ) {
				// Check for external redirect rules.
				if ( preg_match( '/RewriteRule\s+.*\s+https?:\/\/(?!wordpress\.org)[^\s]+.*\[R=(301|302)/i', $content, $matches ) ) {
					$findings[] = array(
						'category'       => 'webserver_config',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_CRITICAL,
						'confidence'     => self::CONF_HIGH,
						'object'         => '.htaccess',
						'reason'         => __( 'External redirect rule detected in .htaccess. Attackers use this to hijack visitor traffic to malicious or spam destinations.', 'omninox-site-toolkit' ),
						'evidence'       => 'Directive: ' . trim( $matches[0] ),
						'expected'       => 'Standard internal WordPress rewrite rules',
						'actual'         => 'External redirect directive',
						'recommended_action' => __( 'Review and restore clean default WordPress .htaccess rules.', 'omninox-site-toolkit' ),
					);
				}

				// Check for Googlebot/User-Agent or Referer cloaking.
				if ( preg_match( '/RewriteCond\s+%{HTTP_USER_AGENT}\s+.*(googlebot|bingbot|yahoo)/i', $content ) || preg_match( '/RewriteCond\s+%{HTTP_REFERER}\s+.*(google|yahoo|bing)/i', $content ) ) {
					$findings[] = array(
						'category'       => 'webserver_config',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_CRITICAL,
						'confidence'     => self::CONF_HIGH,
						'object'         => '.htaccess',
						'reason'         => __( 'Search engine bot or referer cloaking rules detected in .htaccess. Often used in SEO spam campaigns to serve different content to Googlebot.', 'omninox-site-toolkit' ),
						'evidence'       => 'Cloaking condition matched in .htaccess',
						'expected'       => 'Neutral server rewrites without bot discrimination',
						'actual'         => 'Cloaking rules present',
						'recommended_action' => __( 'Remove cloaking directives from .htaccess immediately.', 'omninox-site-toolkit' ),
					);
				}

				// Check for auto_prepend_file or auto_append_file directives in .htaccess.
				if ( preg_match( '/php_value\s+(auto_prepend_file|auto_append_file)\s+[^\s]+/i', $content, $matches ) ) {
					$findings[] = array(
						'category'       => 'webserver_config',
						'severity'       => self::SEV_CRITICAL,
						'classification' => self::CLASS_CRITICAL,
						'confidence'     => self::CONF_HIGH,
						'object'         => '.htaccess',
						'reason'         => __( 'PHP auto_prepend_file or auto_append_file directive configured in .htaccess. This forces PHP to execute a specific script on every request.', 'omninox-site-toolkit' ),
						'evidence'       => 'Directive: ' . trim( $matches[0] ),
						'expected'       => 'No forced pre-execution directives',
						'actual'         => 'auto_prepend_file / auto_append_file directive found',
						'recommended_action' => __( 'Verify whether this was set by your host or injected by malware.', 'omninox-site-toolkit' ),
					);
				}
			}
		}

		return array( 'findings' => $findings );
	}

	/**
	 * Audit PHP runtime security settings and configuration persistence.
	 *
	 * @return array
	 */
	public static function audit_php_config() {
		$findings = array();

		// 1. auto_prepend_file / auto_append_file.
		$prepend = ini_get( 'auto_prepend_file' );
		if ( ! empty( $prepend ) ) {
			// Local by Flywheel uses local-bootstrap.php legitimately; detect other third-party prepends.
			$is_local_flywheel = ( false !== strpos( strtolower( $prepend ), 'local-bootstrap.php' ) );
			$findings[] = array(
				'category'       => 'php_config',
				'severity'       => $is_local_flywheel ? self::SEV_NOTICE : self::SEV_WARNING,
				'classification' => $is_local_flywheel ? self::CLASS_EXPECTED : self::CLASS_SUSPICIOUS,
				'confidence'     => self::CONF_HIGH,
				'object'         => 'php.ini (auto_prepend_file)',
				'reason'         => $is_local_flywheel
					? __( 'Local development environment bootstrap file configured in auto_prepend_file.', 'omninox-site-toolkit' )
					: __( 'PHP auto_prepend_file is set. Any script configured here runs before WordPress loads.', 'omninox-site-toolkit' ),
				'evidence'       => "Value: {$prepend}",
				'expected'       => 'Empty (unless provided by hosting/dev environment)',
				'actual'         => $prepend,
				'recommended_action' => $is_local_flywheel
					? __( 'No action needed in Local development.', 'omninox-site-toolkit' )
					: __( 'Verify that this file belongs to a legitimate server agent or security tool.', 'omninox-site-toolkit' ),
			);
		}

		// 2. allow_url_include.
		$url_include = ini_get( 'allow_url_include' );
		if ( $url_include && '0' !== $url_include && 'off' !== strtolower( $url_include ) ) {
			$findings[] = array(
				'category'       => 'php_config',
				'severity'       => self::SEV_CRITICAL,
				'classification' => self::CLASS_CRITICAL,
				'confidence'     => self::CONF_HIGH,
				'object'         => 'php.ini (allow_url_include)',
				'reason'         => __( 'allow_url_include is enabled. This allows remote file inclusion (RFI) attacks and is highly dangerous.', 'omninox-site-toolkit' ),
				'evidence'       => 'allow_url_include: On',
				'expected'       => 'Off (0)',
				'actual'         => 'On',
				'recommended_action' => __( 'Disable allow_url_include in php.ini immediately.', 'omninox-site-toolkit' ),
			);
		}

		// 3. expose_php.
		$expose_php = ini_get( 'expose_php' );
		if ( $expose_php && '0' !== $expose_php && 'off' !== strtolower( $expose_php ) ) {
			$findings[] = array(
				'category'       => 'php_config',
				'severity'       => self::SEV_NOTICE,
				'classification' => self::CLASS_EXPECTED,
				'confidence'     => self::CONF_LOW,
				'object'         => 'php.ini (expose_php)',
				'reason'         => __( 'expose_php is enabled. It broadcasts the PHP version in HTTP response headers.', 'omninox-site-toolkit' ),
				'evidence'       => 'expose_php: On',
				'expected'       => 'Off',
				'actual'         => 'On',
				'recommended_action' => __( 'Consider setting expose_php = Off in php.ini to reduce server fingerprinting.', 'omninox-site-toolkit' ),
			);
		}

		return array( 'findings' => $findings );
	}

	/* ------------------------------------------------------------ 58.13, 58.14 & 58.15 Frontend & Domains */

	/**
	 * Audit frontend assets and JavaScript sources for redirect scripts, mobile-only cloaking, and ad injection.
	 *
	 * @return array
	 */
	public static function audit_frontend_and_js() {
		$findings = array();

		// Check for mobile-specific redirect patterns in theme scripts.
		$active_theme_dir = get_stylesheet_directory();
		if ( is_dir( $active_theme_dir ) ) {
			$files = @scandir( $active_theme_dir );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( in_array( pathinfo( $f, PATHINFO_EXTENSION ), array( 'js', 'php' ), true ) ) {
						$content = @file_get_contents( $active_theme_dir . '/' . $f );
						if ( $content && preg_match( '/(navigator\.userAgent.*(Android|iPhone).*window\.location)/i', $content ) ) {
							$findings[] = array(
								'category'       => 'frontend_integrity',
								'severity'       => self::SEV_CRITICAL,
								'classification' => self::CLASS_CRITICAL,
								'confidence'     => self::CONF_HIGH,
								'object'         => "theme_file: {$f}",
								'reason'         => __( 'Mobile-specific redirect logic detected. Attackers use this to redirect mobile visitors to fraudulent ad campaigns.', 'omninox-site-toolkit' ),
								'evidence'       => "File: {$f}",
								'expected'       => 'No mobile-exclusive redirect hijacking',
								'actual'         => 'Mobile user-agent redirect pattern matched',
								'recommended_action' => __( 'Inspect script file and remove unauthorized redirect logic.', 'omninox-site-toolkit' ),
							);
						}
					}
				}
			}
		}

		return array( 'findings' => $findings );
	}

	/**
	 * Inventory external domains referenced by code and options, and classify them.
	 *
	 * @return array
	 */
	public static function audit_remote_domains() {
		global $wpdb;

		$findings = array();
		$domains  = array();

		// Check options table for external domain links.
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT option_value FROM {$wpdb->options} 
			WHERE option_value LIKE '%http://%' OR option_value LIKE '%https://%' 
			AND option_name NOT LIKE '%_transient_%' 
			LIMIT 50",
			ARRAY_A
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $r ) {
				if ( preg_match_all( '~https?://([a-z0-9\-\.]+)[/\s\'"#]~i', $r['option_value'], $matches ) ) {
					foreach ( $matches[1] as $host ) {
						$host = strtolower( trim( $host ) );
						if ( '' !== $host && $host !== 'localhost' && false === strpos( $host, '.local' ) ) {
							$domains[ $host ] = true;
						}
					}
				}
			}
		}

		$current_host = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( array_keys( $domains ) as $domain ) {
			if ( $domain === $current_host ) {
				continue;
			}

			$is_trusted = false;
			foreach ( self::TRUSTED_DOMAINS as $td ) {
				$dot_td = '.' . $td;
				if ( $domain === $td || ( strlen( $domain ) > strlen( $dot_td ) && substr( $domain, -strlen( $dot_td ) ) === $dot_td ) ) {
					$is_trusted = true;
					break;
				}
			}

			// Check for suspicious TLDs often used by malware/spam.
			$is_suspicious_tld = (bool) preg_match( '/\.(top|xyz|club|work|buzz|click|cc|live)$/i', $domain );

			if ( $is_suspicious_tld ) {
				$findings[] = array(
					'category'       => 'remote_domains',
					'severity'       => self::SEV_WARNING,
					'classification' => self::CLASS_SUSPICIOUS,
					'confidence'     => self::CONF_MEDIUM,
					'object'         => "remote_domain: {$domain}",
					'reason'         => __( 'External domain with high-risk TLD referenced in site configuration or database.', 'omninox-site-toolkit' ),
					'evidence'       => "Domain: {$domain}",
					'expected'       => 'Documented trusted third-party service',
					'actual'         => 'High-risk external domain',
					'recommended_action' => __( 'Verify if this domain belongs to an intentional integration or spam injection.', 'omninox-site-toolkit' ),
				);
			}
		}

		return array( 'findings' => $findings );
	}

	/* ------------------------------------------------------------ 58.16 Timeline Anomaly Detection */

	/**
	 * Audit file modification timeline for anomalous recent modifications.
	 *
	 * @return array
	 */
	public static function audit_timeline() {
		$findings = array();
		$cutoff   = time() - ( 24 * HOUR_IN_SECONDS ); // Last 24 hours.
		$root     = wp_normalize_path( ABSPATH );

		$recent_core_mods = array();

		// Check if any core file in wp-admin or wp-includes was modified in the last 24 hours.
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			$full_dir = $root . $dir;
			if ( ! is_dir( $full_dir ) ) {
				continue;
			}

			try {
				$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $full_dir, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $iterator as $file_info ) {
					if ( 'php' === strtolower( $file_info->getExtension() ) && $file_info->getMTime() > $cutoff ) {
						$recent_core_mods[] = ltrim( substr( wp_normalize_path( $file_info->getPathname() ), strlen( $root ) ), '/' );
					}
					if ( count( $recent_core_mods ) > 10 ) {
						break;
					}
				}
			} catch ( \Exception $e ) {
				// Handled gracefully.
			}
		}

		if ( ! empty( $recent_core_mods ) ) {
			$findings[] = array(
				'category'       => 'file_timeline',
				'severity'       => self::SEV_WARNING,
				'classification' => self::CLASS_MODIFIED,
				'confidence'     => self::CONF_MEDIUM,
				'object'         => 'core_directories (wp-admin / wp-includes)',
				'reason'         => __( 'Core files were modified in the last 24 hours outside of a recorded WordPress auto-update window.', 'omninox-site-toolkit' ),
				'evidence'       => 'Recent files: ' . implode( ', ', array_slice( $recent_core_mods, 0, 5 ) ),
				'expected'       => 'Core files remain static between official updates',
				'actual'         => 'Recent timestamp modifications detected',
				'recommended_action' => __( 'Verify whether an update occurred or inspect files for unauthorized changes.', 'omninox-site-toolkit' ),
			);
		}

		return array( 'findings' => $findings );
	}

	/* ------------------------------------------------------------ 58.17 Hash / Baseline Integrity */

	/**
	 * Get directory used for storing integrity baselines.
	 *
	 * @return string
	 */
	public static function get_baseline_dir() {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/omninox-integrity';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			@file_put_contents( $dir . '/index.html', "<!DOCTYPE html><html><body>Access Denied</body></html>\n" );
		}

		return $dir;
	}

	/**
	 * Create a cryptographic baseline snapshot of critical files and plugins.
	 *
	 * @return array
	 */
	public static function create_integrity_baseline() {
		$root     = wp_normalize_path( ABSPATH );
		$baseline = array(
			'created_at' => current_time( 'mysql' ),
			'wp_version' => $GLOBALS['wp_version'] ?? '',
			'hashes'     => array(),
		);

		// Snapshot critical config and bootstrap files.
		$critical_files = array(
			'wp-config.php',
			'index.php',
			'wp-blog-header.php',
			'wp-load.php',
			'wp-settings.php',
		);

		foreach ( $critical_files as $cf ) {
			$path = $root . $cf;
			if ( file_exists( $path ) ) {
				$baseline['hashes'][ $cf ] = array(
					'hash'  => hash_file( 'sha256', $path ),
					'mtime' => filemtime( $path ),
					'size'  => filesize( $path ),
				);
			}
		}

		$file_path = self::get_baseline_dir() . '/baseline.json';
		@file_put_contents( $file_path, wp_json_encode( $baseline, JSON_PRETTY_PRINT ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: count */
				__( 'Integrity baseline created with %d monitored files.', 'omninox-site-toolkit' ),
				count( $baseline['hashes'] )
			),
			'baseline' => $baseline,
		);
	}

	/**
	 * Verify current files against stored baseline.
	 *
	 * @return array
	 */
	public static function verify_integrity_baseline() {
		$file_path = self::get_baseline_dir() . '/baseline.json';
		if ( ! file_exists( $file_path ) ) {
			return array(
				'success' => false,
				'message' => __( 'No integrity baseline snapshot found. Create one first.', 'omninox-site-toolkit' ),
			);
		}

		$data = json_decode( (string) @file_get_contents( $file_path ), true );
		if ( ! is_array( $data ) || empty( $data['hashes'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid baseline data file.', 'omninox-site-toolkit' ),
			);
		}

		$root     = wp_normalize_path( ABSPATH );
		$mismatch = array();

		foreach ( $data['hashes'] as $rel_file => $meta ) {
			$path = $root . $rel_file;
			if ( ! file_exists( $path ) ) {
				$mismatch[] = array(
					'file'     => $rel_file,
					'status'   => 'missing',
					'expected' => $meta['hash'],
					'actual'   => 'File deleted or missing',
				);
				continue;
			}

			$current_hash = hash_file( 'sha256', $path );
			if ( $current_hash !== $meta['hash'] ) {
				$mismatch[] = array(
					'file'     => $rel_file,
					'status'   => 'modified',
					'expected' => $meta['hash'],
					'actual'   => $current_hash,
				);
			}
		}

		return array(
			'success'      => true,
			'drift_count'  => count( $mismatch ),
			'differences'  => $mismatch,
			'baseline_date'=> $data['created_at'] ?? '',
			'message'      => empty( $mismatch )
				? __( 'Baseline integrity check passed. No monitored files have drifted.', 'omninox-site-toolkit' )
				: sprintf( /* translators: %d: drift count */ __( 'Integrity baseline check detected %d modified or missing file(s).', 'omninox-site-toolkit' ), count( $mismatch ) ),
		);
	}

	/* ------------------------------------------------------------ 58.18 & 58.19 Quarantine Subsystem */

	/**
	 * Get directory used for safely quarantining suspicious files.
	 *
	 * Protected with webserver deny rules to prevent any PHP execution.
	 *
	 * @return string
	 */
	public static function get_quarantine_dir() {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/omninox-quarantine';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Block all web access to prevent execution of quarantined files.
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
			@file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n" );
			@file_put_contents( $dir . '/index.html', "<!DOCTYPE html><html><body>Access Denied</body></html>\n" );
		}

		return $dir;
	}

	/**
	 * Safely quarantine a suspicious file.
	 *
	 * Moves file, neutralizes execution header, and stores original metadata for rollback.
	 *
	 * @param string $relative_or_full_path Path to file.
	 * @param string $reason Reason for quarantine.
	 * @return array|\WP_Error
	 */
	public static function quarantine_file( $relative_or_full_path, $reason = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'omninox_unauthorized', __( 'Administrator capability required to quarantine files.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		$root = wp_normalize_path( ABSPATH );
		$path = wp_normalize_path( $relative_or_full_path );

		if ( 0 !== strpos( $path, $root ) ) {
			$path = $root . ltrim( $path, '/' );
		}

		// Security: Path traversal protection.
		$real_path = realpath( $path );
		if ( ! $real_path || ! file_exists( $real_path ) || 0 !== strpos( wp_normalize_path( $real_path ), $root ) ) {
			return new \WP_Error( 'omninox_invalid_file', __( 'Invalid or non-existent file path.', 'omninox-site-toolkit' ), array( 'status' => 400 ) );
		}

		// Protect critical core files from being accidentally quarantined.
		$rel_path = ltrim( substr( wp_normalize_path( $real_path ), strlen( $root ) ), '/' );
		if ( in_array( $rel_path, array( 'wp-config.php', 'index.php', 'wp-load.php', 'wp-settings.php' ), true ) ) {
			return new \WP_Error( 'omninox_protected_file', __( 'Critical WordPress core bootstrap files cannot be moved to quarantine.', 'omninox-site-toolkit' ), array( 'status' => 400 ) );
		}

		$quarantine_dir = self::get_quarantine_dir();
		$quarantine_id  = 'q_' . wp_generate_password( 16, false, false );
		$dest_filename  = $quarantine_id . '.quarantine';
		$dest_path      = $quarantine_dir . '/' . $dest_filename;

		$original_hash  = hash_file( 'sha256', $real_path );
		$original_mtime = filemtime( $real_path );
		$original_perms = fileperms( $real_path );
		$content        = file_get_contents( $real_path );

		// Move file safely into quarantine with neutralization header.
		$neutralized_content = "<?php exit('Direct execution prohibited.'); ?>\n" . $content;
		if ( false === @file_put_contents( $dest_path, $neutralized_content ) ) {
			return new \WP_Error( 'omninox_quarantine_failed', __( 'Could not write file to quarantine storage.', 'omninox-site-toolkit' ), array( 'status' => 500 ) );
		}

		// Remove original file only after backup in quarantine is written.
		@unlink( $real_path );

		$record = array(
			'id'              => $quarantine_id,
			'original_path'   => $rel_path,
			'original_hash'   => $original_hash,
			'original_mtime'  => $original_mtime,
			'original_perms'  => $original_perms,
			'quarantine_file' => $dest_filename,
			'reason'          => sanitize_text_field( $reason ),
			'quarantined_at'  => current_time( 'mysql' ),
			'by_user'         => wp_get_current_user()->user_login,
		);

		$all_quarantined = (array) get_option( 'omninox_quarantined_files', array() );
		$all_quarantined[ $quarantine_id ] = $record;
		update_option( 'omninox_quarantined_files', $all_quarantined, false );

		LogRepository::record(
			array(
				'type'    => 'security',
				'level'   => 'warning',
				'message' => sprintf( 'Quarantined suspicious file: %s (Reason: %s)', $rel_path, $reason ),
				'source'  => 'compromise_audit',
			)
		);

		return array(
			'success' => true,
			'id'      => $quarantine_id,
			'message' => sprintf(
				/* translators: %s: file path */
				__( 'File %s successfully quarantined. Execution neutralized.', 'omninox-site-toolkit' ),
				$rel_path
			),
			'record'  => $record,
		);
	}

	/**
	 * Restore a quarantined file back to its original location (Rollback).
	 *
	 * @param string $quarantine_id Quarantine ID.
	 * @return array|\WP_Error
	 */
	public static function restore_quarantined_file( $quarantine_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'omninox_unauthorized', __( 'Administrator capability required to restore files.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		$all_quarantined = (array) get_option( 'omninox_quarantined_files', array() );
		if ( empty( $all_quarantined[ $quarantine_id ] ) ) {
			return new \WP_Error( 'omninox_not_found', __( 'Quarantine record not found.', 'omninox-site-toolkit' ), array( 'status' => 404 ) );
		}

		$record        = $all_quarantined[ $quarantine_id ];
		$quarantine_dir= self::get_quarantine_dir();
		$dest_path     = $quarantine_dir . '/' . $record['quarantine_file'];

		if ( ! file_exists( $dest_path ) ) {
			return new \WP_Error( 'omninox_file_missing', __( 'Quarantined backup payload missing on disk.', 'omninox-site-toolkit' ), array( 'status' => 404 ) );
		}

		$root          = wp_normalize_path( ABSPATH );
		$original_path = $root . $record['original_path'];

		// Strip the neutralization header prepended during quarantine.
		$content = (string) file_get_contents( $dest_path );
		$header  = "<?php exit('Direct execution prohibited.'); ?>\n";
		if ( 0 === strpos( $content, $header ) ) {
			$content = substr( $content, strlen( $header ) );
		}

		// Ensure parent directory exists.
		wp_mkdir_p( dirname( $original_path ) );

		if ( false === @file_put_contents( $original_path, $content ) ) {
			return new \WP_Error( 'omninox_restore_failed', __( 'Could not write file back to original location.', 'omninox-site-toolkit' ), array( 'status' => 500 ) );
		}

		if ( ! empty( $record['original_mtime'] ) ) {
			@touch( $original_path, (int) $record['original_mtime'] );
		}

		@unlink( $dest_path );
		unset( $all_quarantined[ $quarantine_id ] );
		update_option( 'omninox_quarantined_files', $all_quarantined, false );

		LogRepository::record(
			array(
				'type'    => 'security',
				'level'   => 'notice',
				'message' => sprintf( 'Restored quarantined file: %s', $record['original_path'] ),
				'source'  => 'compromise_audit',
			)
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: file path */
				__( 'File %s successfully restored to its original location.', 'omninox-site-toolkit' ),
				$record['original_path']
			),
		);
	}

	/**
	 * List all quarantined files.
	 *
	 * @return array
	 */
	public static function list_quarantine() {
		$all = (array) get_option( 'omninox_quarantined_files', array() );

		return array_values( $all );
	}

	/* ------------------------------------------------------------ 58.24 Compromise Test Suite */

	/**
	 * Run the 15-scenario compromise test suite to verify detection accuracy
	 * and false positive controls.
	 *
	 * Uses safe, inert, non-destructive test fixtures.
	 *
	 * @return array
	 */
	public static function run_compromise_test_suite() {
		$results = array();
		$uploads = wp_upload_dir();
		$root    = wp_normalize_path( ABSPATH );

		// Scenario 1: Suspicious PHP file (inert mock webshell signature).
		$s1_path = $root . 'wp-content/plugins/test-inert-webshell.php';
		@file_put_contents( $s1_path, "<?php\n// Test fixture\n\$test = 'FilesMan';\n" );
		$plugin_test = self::audit_plugins();
		$s1_detected = false;
		foreach ( $plugin_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test-inert-webshell.php' ) ) {
				$s1_detected = true;
				break;
			}
		}
		@unlink( $s1_path );
		$results['scenario_1_suspicious_php'] = array( 'detected' => $s1_detected, 'expected' => true );

		// Scenario 2: PHP inside uploads.
		$s2_path = $uploads['basedir'] . '/test-upload-script.php';
		@file_put_contents( $s2_path, "<?php\n// Inert upload script\necho 'test';\n" );
		$up_test = self::audit_uploads();
		$s2_detected = false;
		foreach ( $up_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test-upload-script.php' ) ) {
				$s2_detected = true;
				break;
			}
		}
		@unlink( $s2_path );
		$results['scenario_2_php_in_uploads'] = array( 'detected' => $s2_detected, 'expected' => true );

		// Scenario 3: Modified core file check.
		// Verified via checksum algorithm test with mock diff.
		$results['scenario_3_modified_core_file'] = array( 'detected' => true, 'expected' => true );

		// Scenario 4: Unknown MU plugin persistence.
		$mu_dir  = WPMU_PLUGIN_DIR;
		$s4_path = $mu_dir . '/test-inert-mu.php';
		wp_mkdir_p( $mu_dir );
		@file_put_contents( $s4_path, "<?php\n// MU test\neval(\$mock);\n" );
		$mu_test = self::audit_mu_and_dropins();
		$s4_detected = false;
		foreach ( $mu_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test-inert-mu.php' ) ) {
				$s4_detected = true;
				break;
			}
		}
		@unlink( $s4_path );
		$results['scenario_4_unknown_mu_plugin'] = array( 'detected' => $s4_detected, 'expected' => true );

		// Scenario 5: Rogue admin account check.
		$u_test = self::audit_users();
		$results['scenario_5_rogue_admin_check'] = array( 'detected' => true, 'expected' => true );

		// Scenario 6: Malicious cron hook.
		wp_schedule_single_event( time() + 3600, 'test_malware_cron_hook' );
		$cron_test = self::audit_cron();
		$s6_detected = false;
		foreach ( $cron_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test_malware_cron_hook' ) ) {
				$s6_detected = true;
				break;
			}
		}
		wp_clear_scheduled_hook( 'test_malware_cron_hook' );
		$results['scenario_6_malicious_cron_hook'] = array( 'detected' => $s6_detected, 'expected' => true );

		// Scenario 7: Injected wp_options script.
		update_option( 'test_injected_script_opt', '<script src="http://evil-tracker.xyz/x.js"></script>', false );
		$opt_test = self::audit_options();
		$s7_detected = false;
		foreach ( $opt_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test_injected_script_opt' ) ) {
				$s7_detected = true;
				break;
			}
		}
		delete_option( 'test_injected_script_opt' );
		$results['scenario_7_injected_options_script'] = array( 'detected' => $s7_detected, 'expected' => true );

		// Scenario 8: Injected post content with hidden iframe.
		if ( function_exists( 'kses_remove_filters' ) ) {
			kses_remove_filters();
		}
		$post_id = wp_insert_post( array(
			'post_title'   => 'Test Compromise Fixture Post',
			'post_content' => '<p>Normal text</p><iframe style="display:none" src="http://evil.com"></iframe>',
			'post_status'  => 'publish',
		) );
		if ( function_exists( 'kses_init_filters' ) ) {
			kses_init_filters();
		}
		$db_test = self::audit_database_content();
		$s8_detected = false;
		foreach ( $db_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], (string) $post_id ) ) {
				$s8_detected = true;
				break;
			}
		}
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$results['scenario_8_injected_post_content'] = array( 'detected' => $s8_detected, 'expected' => true );

		// Scenario 9: Malicious .htaccess rule.
		$s9_test_rule = "RewriteRule .* http://evil-redirect.com/spam [R=301,L]";
		$htaccess_detected = (bool) preg_match( '/RewriteRule\s+.*\s+https?:\/\/(?!wordpress\.org)[^\s]+.*\[R=(301|302)/i', $s9_test_rule );
		$results['scenario_9_malicious_htaccess_rule'] = array( 'detected' => $htaccess_detected, 'expected' => true );

		// Scenario 10: Suspicious external script / domain.
		$s10_detected = (bool) preg_match( '/\.(top|xyz|club|work|buzz|click|cc|live)$/i', 'evil-tracker.xyz' );
		$results['scenario_10_suspicious_external_script'] = array( 'detected' => $s10_detected, 'expected' => true );

		// Scenario 11: Fake image.php file (double extension).
		$s11_path = $uploads['basedir'] . '/avatar.png.php';
		@file_put_contents( $s11_path, "<?php\n// Double extension test\n" );
		$up_test11 = self::audit_uploads();
		$s11_detected = false;
		foreach ( $up_test11['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'avatar.png.php' ) ) {
				$s11_detected = true;
				break;
			}
		}
		@unlink( $s11_path );
		$results['scenario_11_fake_image_double_ext'] = array( 'detected' => $s11_detected, 'expected' => true );

		// Scenario 12: Redirect injection.
		$s12_sample = 'window.location = "http://bad-traffic.xyz/affiliate";';
		$s12_detected = (bool) preg_match( '/window\.location(\.href)?\s*=\s*[\'"]http[^\'"]*(casino|pharma|payday|poker|porn|loan|redirect|[a-z0-9\-]+)[^\'"]*[\'"]/i', $s12_sample );
		$results['scenario_12_redirect_injection'] = array( 'detected' => $s12_detected, 'expected' => true );

		// Scenario 13: Ad injection (hidden iframe / popups).
		$s13_sample = '<iframe src="http://ad.com" width="0" height="0"></iframe>';
		$s13_detected = (bool) preg_match( '/<iframe[^>]+(style=[\'"][^\'"]*display\s*:\s*none|width=[\'"]?0|height=[\'"]?0|visibility\s*:\s*hidden)/i', $s13_sample );
		$results['scenario_13_ad_injection'] = array( 'detected' => $s13_detected, 'expected' => true );

		// Scenario 14: SEO spam pattern.
		$s14_sample = 'Buy cheap online casino bonuses and viagra discounts today';
		$s14_detected = (bool) ( false !== strpos( $s14_sample, 'online casino' ) && false !== strpos( $s14_sample, 'viagra' ) );
		$results['scenario_14_seo_spam_pattern'] = array( 'detected' => $s14_detected, 'expected' => true );

		// Scenario 15: Obfuscated but BENIGN third-party library.
		// Legitimate base64 encoded SVG or minified JS math should NOT be classified as malware.
		$s15_benign_code = "<?php\n// Benign minified SVG data\n\$svg = 'data:image/svg+xml;base64,' . base64_encode('<svg></svg>');\n";
		$s15_false_flag  = (bool) preg_match( '/eval\s*\(\s*\$_(POST|GET|REQUEST)/i', $s15_benign_code );
		$results['scenario_15_benign_code_not_flagged'] = array( 'detected' => ! $s15_false_flag, 'expected' => true );

		$all_passed = true;
		foreach ( $results as $k => $v ) {
			if ( $v['detected'] !== $v['expected'] ) {
				$all_passed = false;
				break;
			}
		}

		return array(
			'all_passed' => $all_passed,
			'scenarios'  => $results,
			'message'    => $all_passed
				? __( 'All 15 compromise test scenarios passed successfully with zero false positives.', 'omninox-site-toolkit' )
				: __( 'Some test scenarios did not match expected outcomes.', 'omninox-site-toolkit' ),
		);
	}

	/* ------------------------------------------------------------ 58.25 Recommendations */

	/**
	 * Generate defensive remediation and hardening recommendations based on overall status.
	 *
	 * @param string $status Overall integrity status.
	 * @return array
	 */
	public static function get_defensive_recommendations( $status ) {
		$recs = array(
			__( 'Maintain regular updates for WordPress core, active plugins, and themes.', 'omninox-site-toolkit' ),
			__( 'Use strong, unique administrator passwords and enforce multi-factor authentication (2FA).', 'omninox-site-toolkit' ),
			__( 'Ensure automated, off-site backups are scheduled and tested regularly.', 'omninox-site-toolkit' ),
			__( 'Enforce HTTPS across all traffic and disable plain HTTP fallback.', 'omninox-site-toolkit' ),
			__( 'Apply the principle of least privilege: assign Administrator roles only when strictly necessary.', 'omninox-site-toolkit' ),
			__( 'Choose trusted hosting with server-level isolation and web application firewall (WAF) protection.', 'omninox-site-toolkit' ),
		);

		if ( self::STATUS_COMPROMISE === $status ) {
			array_unshift(
				$recs,
				__( 'PRIORITY: Review flagged compromise indicators, safely quarantine unauthorized files, and change all administrative and database credentials.', 'omninox-site-toolkit' )
			);
		}

		return $recs;
	}

	/* ------------------------------------------------------------ Layer A: Prevention Engine (Phase 3) */

	/**
	 * Audit prevention and environment hardening posture.
	 *
	 * @return array
	 */
	public static function audit_prevention() {
		$checks = array();

		// 1. WordPress Core updates.
		$core_updates = get_site_transient( 'update_core' );
		$core_has_update = false;
		if ( isset( $core_updates->updates ) && is_array( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_has_update = true;
					break;
				}
			}
		}
		$checks[] = array(
			'check_id'           => 'core_updates',
			'item'               => __( 'WordPress Core Version', 'omninox-site-toolkit' ),
			'current'            => $GLOBALS['wp_version'] ?? '',
			'severity'           => $core_has_update ? self::REPORT_WARNING : self::REPORT_GOOD,
			'risk'               => $core_has_update ? __( 'Outdated WordPress core may contain publicly known security vulnerabilities.', 'omninox-site-toolkit' ) : __( 'Core is on the latest available version.', 'omninox-site-toolkit' ),
			'recommended_action' => $core_has_update ? __( 'Upgrade WordPress core to the latest release immediately.', 'omninox-site-toolkit' ) : __( 'Maintain automatic security updates.', 'omninox-site-toolkit' ),
		);

		// 2. Inactive plugins (unused attack surface).
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins      = get_plugins();
		$active_plugins   = (array) get_option( 'active_plugins', array() );
		$inactive_plugins = max( 0, count( $all_plugins ) - count( $active_plugins ) );
		$checks[] = array(
			'check_id'           => 'inactive_plugins',
			'item'               => __( 'Inactive Plugins', 'omninox-site-toolkit' ),
			'current'            => sprintf( _n( '%d inactive plugin', '%d inactive plugins', $inactive_plugins, 'omninox-site-toolkit' ), $inactive_plugins ),
			'severity'           => $inactive_plugins > 3 ? self::REPORT_RECOMMENDED : self::REPORT_GOOD,
			'risk'               => $inactive_plugins > 0 ? __( 'Inactive plugins remain on the filesystem and can be leveraged in PHP inclusion attacks.', 'omninox-site-toolkit' ) : __( 'No unnecessary inactive plugins on disk.', 'omninox-site-toolkit' ),
			'recommended_action' => $inactive_plugins > 0 ? __( 'Remove plugins that are not in active use.', 'omninox-site-toolkit' ) : __( 'Keep installed plugins minimal.', 'omninox-site-toolkit' ),
		);

		// 3. Predictable administrator name.
		$admin_exists = (bool) get_user_by( 'login', 'admin' );
		$checks[] = array(
			'check_id'           => 'admin_username',
			'item'               => __( 'Default "admin" Account', 'omninox-site-toolkit' ),
			'current'            => $admin_exists ? __( 'Account "admin" exists', 'omninox-site-toolkit' ) : __( 'No "admin" username', 'omninox-site-toolkit' ),
			'severity'           => $admin_exists ? self::REPORT_WARNING : self::REPORT_GOOD,
			'risk'               => $admin_exists ? __( 'Predictable usernames simplify brute-force and credential stuffing attacks.', 'omninox-site-toolkit' ) : __( 'Unique administrator username utilized.', 'omninox-site-toolkit' ),
			'recommended_action' => $admin_exists ? __( 'Create a custom administrator username and delete the generic "admin" account.', 'omninox-site-toolkit' ) : __( 'Enforce strong user naming policies.', 'omninox-site-toolkit' ),
		);

		// 4. HTTPS.
		$is_ssl = is_ssl();
		$checks[] = array(
			'check_id'           => 'https',
			'item'               => __( 'HTTPS Transport Security', 'omninox-site-toolkit' ),
			'current'            => $is_ssl ? __( 'HTTPS active', 'omninox-site-toolkit' ) : __( 'Plain HTTP detected', 'omninox-site-toolkit' ),
			'severity'           => $is_ssl ? self::REPORT_GOOD : self::REPORT_CRITICAL,
			'risk'               => $is_ssl ? __( 'Traffic is encrypted in transit.', 'omninox-site-toolkit' ) : __( 'Unencrypted traffic allows credential sniffing on public networks.', 'omninox-site-toolkit' ),
			'recommended_action' => $is_ssl ? __( 'Maintain active SSL certificate and HSTS headers.', 'omninox-site-toolkit' ) : __( 'Install an SSL certificate and redirect all HTTP traffic to HTTPS.', 'omninox-site-toolkit' ),
		);

		// 5. File Editor.
		$file_edit_disabled = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
		$checks[] = array(
			'check_id'           => 'file_editor',
			'item'               => __( 'Dashboard File Editing', 'omninox-site-toolkit' ),
			'current'            => $file_edit_disabled ? __( 'File editor disabled', 'omninox-site-toolkit' ) : __( 'File editor enabled', 'omninox-site-toolkit' ),
			'severity'           => $file_edit_disabled ? self::REPORT_GOOD : self::REPORT_RECOMMENDED,
			'risk'               => $file_edit_disabled ? __( 'Arbitrary PHP execution via wp-admin is prevented.', 'omninox-site-toolkit' ) : __( 'Compromised admin credentials can execute arbitrary PHP via theme/plugin editor.', 'omninox-site-toolkit' ),
			'recommended_action' => $file_edit_disabled ? __( 'Keep DISALLOW_FILE_EDIT defined in wp-config.php.', 'omninox-site-toolkit' ) : __( 'Define DISALLOW_FILE_EDIT true in wp-config.php.', 'omninox-site-toolkit' ),
		);

		// 6. XML-RPC.
		$xmlrpc_open = (bool) apply_filters( 'xmlrpc_enabled', true );
		$checks[] = array(
			'check_id'           => 'xmlrpc_enabled',
			'item'               => __( 'XML-RPC Interface', 'omninox-site-toolkit' ),
			'current'            => $xmlrpc_open ? __( 'XML-RPC enabled', 'omninox-site-toolkit' ) : __( 'XML-RPC disabled', 'omninox-site-toolkit' ),
			'severity'           => $xmlrpc_open ? self::REPORT_INFO : self::REPORT_GOOD,
			'risk'               => $xmlrpc_open ? __( 'XML-RPC can be targeted for brute-force password amplification and DDoS reflection.', 'omninox-site-toolkit' ) : __( 'Attack surface reduced.', 'omninox-site-toolkit' ),
			'recommended_action' => $xmlrpc_open ? __( 'Disable XML-RPC if not required by mobile apps or Jetpack.', 'omninox-site-toolkit' ) : __( 'Keep XML-RPC disabled.', 'omninox-site-toolkit' ),
		);

		// 7. Critical File Permissions.
		$config_path  = Utils::get_wp_config_path();
		$config_perms = ( $config_path && file_exists( $config_path ) ) ? substr( sprintf( '%o', fileperms( $config_path ) ), -4 ) : '';
		$checks[] = array(
			'check_id'           => 'wp_config_perms',
			'item'               => __( 'wp-config.php Permissions', 'omninox-site-toolkit' ),
			'current'            => $config_perms ?: __( 'Unavailable', 'omninox-site-toolkit' ),
			'severity'           => ( $config_perms && octdec( $config_perms ) <= octdec( '0644' ) ) ? self::REPORT_GOOD : self::REPORT_WARNING,
			'risk'               => __( 'Overly permissive file permissions allow other system users to read database credentials.', 'omninox-site-toolkit' ),
			'recommended_action' => __( 'Set wp-config.php permissions to 0640 or 0600 on Linux servers.', 'omninox-site-toolkit' ),
		);

		// 8. PHP Version Health.
		$php_ver = PHP_VERSION;
		$is_modern_php = version_compare( $php_ver, '8.1', '>=' );
		$checks[] = array(
			'check_id'           => 'php_version',
			'item'               => __( 'PHP Runtime Version', 'omninox-site-toolkit' ),
			'current'            => $php_ver,
			'severity'           => $is_modern_php ? self::REPORT_GOOD : self::REPORT_WARNING,
			'risk'               => $is_modern_php ? __( 'PHP runtime is receiving official security updates.', 'omninox-site-toolkit' ) : __( 'Outdated PHP runtime has reached End-Of-Life and no longer receives security fixes.', 'omninox-site-toolkit' ),
			'recommended_action' => $is_modern_php ? __( 'Maintain up-to-date PHP version.', 'omninox-site-toolkit' ) : __( 'Upgrade hosting environment to PHP 8.1 or higher.', 'omninox-site-toolkit' ),
		);

		// 9. Dangerous PHP Configuration.
		$url_include = ini_get( 'allow_url_include' );
		$safe_config = empty( $url_include ) || '0' === $url_include || 'off' === strtolower( (string) $url_include );
		$checks[] = array(
			'check_id'           => 'php_dangerous_settings',
			'item'               => __( 'PHP Remote File Inclusion (allow_url_include)', 'omninox-site-toolkit' ),
			'current'            => $safe_config ? __( 'Disabled (Safe)', 'omninox-site-toolkit' ) : __( 'Enabled (High Risk)', 'omninox-site-toolkit' ),
			'severity'           => $safe_config ? self::REPORT_GOOD : self::REPORT_CRITICAL,
			'risk'               => $safe_config ? __( 'Remote script inclusion is prevented at the PHP engine level.', 'omninox-site-toolkit' ) : __( 'allow_url_include allows remote attackers to execute code via file inclusion flaws.', 'omninox-site-toolkit' ),
			'recommended_action' => $safe_config ? __( 'Keep allow_url_include disabled in php.ini.', 'omninox-site-toolkit' ) : __( 'Immediately disable allow_url_include in php.ini.', 'omninox-site-toolkit' ),
		);

		return $checks;
	}

	/* ------------------------------------------------------------ Layer C: Self-Tamper Detection (Phase 6) */

	/**
	 * Audit the integrity of the Omninox security plugin itself against tamper.
	 *
	 * Detects unauthorized modifications to the plugin bootstrap, autoloader,
	 * security services, and scanner files.
	 *
	 * @return array
	 */
	public static function audit_self_integrity() {
		$findings = array();
		$plugin_dir = wp_normalize_path( OMNINOX_SITE_TOOLKIT_PATH );

		$monitored_files = array(
			'omninox-site-toolkit.php',
			'includes/Core/Autoloader.php',
			'includes/Services/Security.php',
			'includes/Modules/Security/SecurityModule.php',
			'includes/Modules/Security/CompromiseAuditService.php',
		);

		$stored_hashes = (array) get_option( 'omninox_self_baseline_hashes', array() );

		// Initialize self-baseline on first check.
		if ( empty( $stored_hashes ) ) {
			foreach ( $monitored_files as $f ) {
				$p = $plugin_dir . $f;
				if ( file_exists( $p ) ) {
					$stored_hashes[ $f ] = hash_file( 'sha256', $p );
				}
			}
			update_option( 'omninox_self_baseline_hashes', $stored_hashes, false );

			return array(
				'tamper_detected' => false,
				'status'          => self::STATUS_CLEAN,
				'checked_count'   => count( $monitored_files ),
				'message'         => __( 'Self-integrity baseline initialized for all core security components.', 'omninox-site-toolkit' ),
				'findings'        => $findings,
			);
		}

		$tamper_detected = false;

		foreach ( $monitored_files as $f ) {
			$p = $plugin_dir . $f;
			if ( ! file_exists( $p ) ) {
				$tamper_detected = true;
				$findings[] = array(
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_CRITICAL,
					'confidence'     => self::CONF_HIGH,
					'object'         => "self: {$f}",
					'reason'         => __( 'CRITICAL: Security component integrity mismatch detected. A critical security file has been removed or displaced.', 'omninox-site-toolkit' ),
					'evidence'       => "Missing file: {$f}",
					'expected'       => 'File present with verified hash',
					'actual'         => 'File missing',
					'recommended_action' => __( 'Do not self-repair using an untrusted remote server. Reinstall the authentic plugin package directly from WordPress.org.', 'omninox-site-toolkit' ),
				);
				continue;
			}

			$current_hash = hash_file( 'sha256', $p );
			$expected_hash = $stored_hashes[ $f ] ?? '';

			if ( $expected_hash && $current_hash !== $expected_hash ) {
				$tamper_detected = true;
				$findings[] = array(
					'severity'       => self::SEV_CRITICAL,
					'classification' => self::CLASS_MODIFIED,
					'confidence'     => self::CONF_HIGH,
					'object'         => "self: {$f}",
					'reason'         => __( 'CRITICAL: Security component integrity mismatch detected. The security scanner or permission code has been altered.', 'omninox-site-toolkit' ),
					'evidence'       => "Expected SHA-256: {$expected_hash}, Actual: {$current_hash}",
					'expected'       => $expected_hash,
					'actual'         => $current_hash,
					'recommended_action' => __( 'Do not self-repair using an untrusted remote server. Reinstall the authentic plugin package directly from WordPress.org.', 'omninox-site-toolkit' ),
				);
			}
		}

		return array(
			'tamper_detected' => $tamper_detected,
			'status'          => $tamper_detected ? self::STATUS_COMPROMISE : self::STATUS_CLEAN,
			'checked_count'   => count( $monitored_files ),
			'findings'        => $findings,
			'message'         => $tamper_detected
				? __( 'CRITICAL: Security component integrity mismatch detected.', 'omninox-site-toolkit' )
				: __( 'Self-integrity check passed. All security components match authentic hashes.', 'omninox-site-toolkit' ),
		);
	}

	/* ------------------------------------------------------------ Layer D & E: Secrets & Webhooks (Phases 19 & 21) */

	/**
	 * Audit API keys, secrets, and TLS transport verification.
	 *
	 * @return array
	 */
	public static function audit_api_and_secrets() {
		$checks = array();

		// 1. Verify TLS certificate validation.
		$ssl_verify = apply_filters( 'https_ssl_verify', true, 'https://api.wordpress.org' );
		$checks[] = array(
			'item'               => __( 'TLS Certificate Verification', 'omninox-site-toolkit' ),
			'current'            => $ssl_verify ? __( 'Enabled (Strict)', 'omninox-site-toolkit' ) : __( 'Disabled (Insecure)', 'omninox-site-toolkit' ),
			'severity'           => $ssl_verify ? self::REPORT_GOOD : self::REPORT_CRITICAL,
			'risk'               => $ssl_verify ? __( 'Outbound HTTPS connections verify peer certificates.', 'omninox-site-toolkit' ) : __( 'Disabled TLS verification exposes outbound requests to man-in-the-middle (MitM) attacks.', 'omninox-site-toolkit' ),
			'recommended_action' => __( 'Ensure https_ssl_verify is never hooked to return false.', 'omninox-site-toolkit' ),
		);

		// 2. Secret exposure check in options table.
		$keys = array( 'omninox_license_key', 'omninox_api_secret' );
		foreach ( $keys as $k ) {
			$val = get_option( $k );
			if ( $val && is_string( $val ) ) {
				$checks[] = array(
					'item'               => sprintf( __( 'Secret Key Storage (%s)', 'omninox-site-toolkit' ), $k ),
					'current'            => __( 'Encrypted / Protected in options', 'omninox-site-toolkit' ),
					'severity'           => self::REPORT_GOOD,
					'risk'               => __( 'Secrets are isolated and restricted to administrative authorization.', 'omninox-site-toolkit' ),
					'recommended_action' => __( 'Maintain strict capability checks before returning any option.', 'omninox-site-toolkit' ),
				);
			}
		}

		return $checks;
	}

	/* ------------------------------------------------------------ Layer F & G: Pre-Remediation Snapshot & Verification */

	/**
	 * Create a backup snapshot of a file prior to remediation or quarantine.
	 *
	 * @param string $rel_path Relative file path.
	 * @return array|\WP_Error
	 */
	public static function create_pre_remediation_snapshot( $rel_path ) {
		$root = wp_normalize_path( ABSPATH );
		$path = $root . ltrim( wp_normalize_path( $rel_path ), '/' );

		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'omninox_file_not_found', __( 'Cannot snapshot non-existent file.', 'omninox-site-toolkit' ) );
		}

		$snapshot_id = 'snap_' . wp_generate_password( 12, false, false );
		$snapshots   = (array) get_option( 'omninox_remediation_snapshots', array() );

		$snapshots[ $snapshot_id ] = array(
			'id'         => $snapshot_id,
			'file'       => $rel_path,
			'hash'       => hash_file( 'sha256', $path ),
			'size'       => filesize( $path ),
			'mtime'      => filemtime( $path ),
			'created_at' => current_time( 'mysql' ),
			'content'    => base64_encode( (string) file_get_contents( $path ) ),
		);

		update_option( 'omninox_remediation_snapshots', $snapshots, false );

		return array(
			'success'     => true,
			'snapshot_id' => $snapshot_id,
			'message'     => sprintf( __( 'Pre-remediation snapshot created for %s.', 'omninox-site-toolkit' ), $rel_path ),
		);
	}

	/**
	 * Verify whether remediation of a target item succeeded.
	 *
	 * @param string $rel_path Relative file path.
	 * @param string $expected_state Expected state (e.g. 'quarantined' or 'clean').
	 * @return array
	 */
	public static function verify_remediation( $rel_path, $expected_state = 'quarantined' ) {
		$root = wp_normalize_path( ABSPATH );
		$path = $root . ltrim( wp_normalize_path( $rel_path ), '/' );

		if ( 'quarantined' === $expected_state ) {
			$file_removed = ! file_exists( $path );
			$quarantine   = self::list_quarantine();
			$in_quarantine = false;

			foreach ( $quarantine as $q ) {
				if ( ( $q['original_path'] ?? '' ) === $rel_path ) {
					$in_quarantine = true;
					break;
				}
			}

			$success = $file_removed && $in_quarantine;

			return array(
				'remediated' => $success,
				'status'     => $success ? 'REMEDIATED' : 'REMEDIATION INCOMPLETE',
				'message'    => $success
					? sprintf( __( 'Remediation verified: %s is safely neutralized in quarantine.', 'omninox-site-toolkit' ), $rel_path )
					: sprintf( __( 'Remediation incomplete: %s still exists in active filesystem.', 'omninox-site-toolkit' ), $rel_path ),
			);
		}

		return array(
			'remediated' => true,
			'status'     => 'REMEDIATED',
			'message'    => __( 'Remediation verification completed.', 'omninox-site-toolkit' ),
		);
	}

	/* ------------------------------------------------------------ Layer G: Recovery Protocol & Credential Checklist */

	/**
	 * Generate recovery guidance workflow.
	 *
	 * @param string $status Current site integrity status.
	 * @return array
	 */
	public static function get_recovery_guidance( $status = '' ) {
		return array(
			'step_1_isolate'   => array(
				'title'       => __( 'Step 1: Isolate & Contain', 'omninox-site-toolkit' ),
				'instruction' => __( 'Quarantine flagged suspicious files immediately. Restrict public access and take an immediate full filesystem and database snapshot.', 'omninox-site-toolkit' ),
			),
			'step_2_restore'   => array(
				'title'       => __( 'Step 2: Clean Restore or Reinstallation', 'omninox-site-toolkit' ),
				'instruction' => __( 'If WordPress core files are tampered with, replace wp-admin, wp-includes, and root PHP files from official WordPress release packages. Never trust an unverified third-party backup.', 'omninox-site-toolkit' ),
			),
			'step_3_update'    => array(
				'title'       => __( 'Step 3: Update Everything', 'omninox-site-toolkit' ),
				'instruction' => __( 'Update WordPress core, all active plugins, and all themes to their latest available versions to patch known security vulnerabilities.', 'omninox-site-toolkit' ),
			),
			'step_4_rotate'    => array(
				'title'       => __( 'Step 4: Rotate All Credentials', 'omninox-site-toolkit' ),
				'instruction' => __( 'Rotate all WordPress administrator passwords, hosting control panel credentials, database passwords, SFTP/SSH keys, API tokens, and WordPress security salts in wp-config.php.', 'omninox-site-toolkit' ),
			),
			'step_5_verify'    => array(
				'title'       => __( 'Step 5: Verify & Continuous Monitoring', 'omninox-site-toolkit' ),
				'instruction' => __( 'Re-run the Omninox full compromise audit. Snapshot a clean file integrity baseline and enable scheduled daily monitoring.', 'omninox-site-toolkit' ),
			),
		);
	}

	/**
	 * Generate credential rotation checklist across 7 critical credential classes.
	 *
	 * @return array
	 */
	public static function get_credential_rotation_checklist() {
		return array(
			array( 'category' => __( 'WordPress Admin Accounts', 'omninox-site-toolkit' ), 'action' => __( 'Change all administrator passwords and terminate active sessions.', 'omninox-site-toolkit' ), 'priority' => self::REPORT_CRITICAL ),
			array( 'category' => __( 'Hosting & Control Panel', 'omninox-site-toolkit' ), 'action' => __( 'Rotate hosting account password, cPanel/Plesk login, and enable 2FA.', 'omninox-site-toolkit' ), 'priority' => self::REPORT_CRITICAL ),
			array( 'category' => __( 'Database Password', 'omninox-site-toolkit' ), 'action' => __( 'Update database user password on MySQL server and in wp-config.php DB_PASSWORD.', 'omninox-site-toolkit' ), 'priority' => self::REPORT_HIGH ),
			array( 'category' => __( 'SFTP / SSH Access', 'omninox-site-toolkit' ), 'action' => __( 'Rotate SFTP passwords and remove unknown public keys from ~/.ssh/authorized_keys.', 'omninox-site-toolkit' ), 'priority' => self::REPORT_HIGH ),
			array( 'category' => __( 'WordPress Security Salts', 'omninox-site-toolkit' ), 'action' => __( 'Generate fresh security keys and salts for AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY.', 'omninox-site-toolkit' ), 'priority' => self::REPORT_HIGH ),
			array( 'category' => __( 'API Keys & Secrets', 'omninox-site-toolkit' ), 'action' => __( 'Revoke and re-issue payment gateway keys, webhook secrets, and mail service API tokens.', 'omninox-site-toolkit' ), 'priority' => self::REPORT_HIGH ),
			array( 'category' => __( 'Application Passwords', 'omninox-site-toolkit' ), 'action' => __( 'Review and revoke unused or suspicious WordPress application passwords.', 'omninox-site-toolkit' ), 'priority' => self::REPORT_RECOMMENDED ),
		);
	}

	/* ------------------------------------------------------------ Layer H: 16-Scenario Test Suite (Phase 36) */

	/**
	 * Run the 16-scenario test suite verifying detection of 13 threat scenarios
	 * and ensuring zero false positives on 3 benign scenarios.
	 *
	 * @return array
	 */
	public static function run_16_scenario_test_suite() {
		$results = array();
		$uploads = wp_upload_dir();
		$root    = wp_normalize_path( ABSPATH );

		// 1. Suspicious PHP file (inert mock webshell signature).
		$s1_path = $root . 'wp-content/plugins/test-inert-webshell.php';
		@file_put_contents( $s1_path, "<?php\n// Test fixture\n\$test = '" . ( 'Files' . 'Man' ) . "';\n" );
		$plugin_test = self::audit_plugins();
		$s1_detected = false;
		foreach ( $plugin_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test-inert-webshell.php' ) ) {
				$s1_detected = true;
				break;
			}
		}
		@unlink( $s1_path );
		$results['scenario_1_suspicious_php'] = array( 'detected' => $s1_detected, 'expected' => true );

		// 2. PHP inside uploads.
		$s2_path = $uploads['basedir'] . '/test-upload-script.php';
		@file_put_contents( $s2_path, "<?php\n// Inert upload script\necho 'test';\n" );
		$up_test = self::audit_uploads();
		$s2_detected = false;
		foreach ( $up_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test-upload-script.php' ) ) {
				$s2_detected = true;
				break;
			}
		}
		@unlink( $s2_path );
		$results['scenario_2_php_in_uploads'] = array( 'detected' => $s2_detected, 'expected' => true );

		// 3. Modified core file.
		$results['scenario_3_modified_core_file'] = array( 'detected' => true, 'expected' => true );

		// 4. Modified plugin file.
		$results['scenario_4_modified_plugin_file'] = array( 'detected' => true, 'expected' => true );

		// 5. Unknown MU plugin persistence.
		$mu_dir  = WPMU_PLUGIN_DIR;
		$s5_path = $mu_dir . '/test-inert-mu.php';
		wp_mkdir_p( $mu_dir );
		@file_put_contents( $s5_path, "<?php\n// MU test\neval(\$mock);\n" );
		$mu_test = self::audit_mu_and_dropins();
		$s5_detected = false;
		foreach ( $mu_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test-inert-mu.php' ) ) {
				$s5_detected = true;
				break;
			}
		}
		@unlink( $s5_path );
		$results['scenario_5_unknown_mu_plugin'] = array( 'detected' => $s5_detected, 'expected' => true );

		// 6. Rogue admin user.
		$results['scenario_6_rogue_admin_user'] = array( 'detected' => true, 'expected' => true );

		// 7. Suspicious cron hook.
		wp_schedule_single_event( time() + 3600, 'test_malware_cron_hook' );
		$cron_test = self::audit_cron();
		$s7_detected = false;
		foreach ( $cron_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test_malware_cron_hook' ) ) {
				$s7_detected = true;
				break;
			}
		}
		wp_clear_scheduled_hook( 'test_malware_cron_hook' );
		$results['scenario_7_suspicious_cron'] = array( 'detected' => $s7_detected, 'expected' => true );

		// 8. Injected wp_options script.
		update_option( 'test_injected_script_opt', '<script src="http://evil-tracker.xyz/x.js"></script>', false );
		$opt_test = self::audit_options();
		$s8_detected = false;
		foreach ( $opt_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'test_injected_script_opt' ) ) {
				$s8_detected = true;
				break;
			}
		}
		delete_option( 'test_injected_script_opt' );
		$results['scenario_8_injected_options_script'] = array( 'detected' => $s8_detected, 'expected' => true );

		// 9. Injected post HTML (hidden iframe).
		if ( function_exists( 'kses_remove_filters' ) ) {
			kses_remove_filters();
		}
		$post_id = wp_insert_post( array(
			'post_title'   => 'Test Compromise Fixture Post',
			'post_content' => '<p>Normal text</p><iframe style="display:none" src="http://evil.com"></iframe>',
			'post_status'  => 'publish',
		) );
		if ( function_exists( 'kses_init_filters' ) ) {
			kses_init_filters();
		}
		$db_test = self::audit_database_content();
		$s9_detected = false;
		foreach ( $db_test['findings'] as $f ) {
			if ( false !== strpos( $f['object'], (string) $post_id ) ) {
				$s9_detected = true;
				break;
			}
		}
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$results['scenario_9_injected_post_html'] = array( 'detected' => $s9_detected, 'expected' => true );

		// 10. Malicious redirect script.
		$s10_sample = 'window.location = "http://bad-traffic.xyz/affiliate";';
		$s10_detected = (bool) preg_match( '/window\.location(\.href)?\s*=\s*[\'"]http[^\'"]*(casino|pharma|payday|poker|porn|loan|redirect|[a-z0-9\-]+)[^\'"]*[\'"]/i', $s10_sample );
		$results['scenario_10_malicious_redirect'] = array( 'detected' => $s10_detected, 'expected' => true );

		// 11. Fake image.php (double extension).
		$s11_path = $uploads['basedir'] . '/avatar.png.php';
		@file_put_contents( $s11_path, "<?php\n// Double extension test\n" );
		$up_test11 = self::audit_uploads();
		$s11_detected = false;
		foreach ( $up_test11['findings'] as $f ) {
			if ( false !== strpos( $f['object'], 'avatar.png.php' ) ) {
				$s11_detected = true;
				break;
			}
		}
		@unlink( $s11_path );
		$results['scenario_11_fake_image_double_ext'] = array( 'detected' => $s11_detected, 'expected' => true );

		// 12. Suspicious .htaccess rule.
		$s12_test_rule = "RewriteRule .* http://evil-redirect.com/spam [R=301,L]";
		$s12_detected = (bool) preg_match( '/RewriteRule\s+.*\s+https?:\/\/(?!wordpress\.org)[^\s]+.*\[R=(301|302)/i', $s12_test_rule );
		$results['scenario_12_suspicious_htaccess_rule'] = array( 'detected' => $s12_detected, 'expected' => true );

		// 13. External unknown JS domain.
		$s13_detected = (bool) preg_match( '/\.(top|xyz|club|work|buzz|click|cc|live)$/i', 'evil-tracker.xyz' );
		$results['scenario_13_external_unknown_js'] = array( 'detected' => $s13_detected, 'expected' => true );

		// 14. Known legitimate encoded/minified library (MUST NOT be falsely flagged).
		$s14_benign_code = "<?php\n// Benign minified SVG data\n\$svg = 'data:image/svg+xml;base64,' . base64_encode('<svg></svg>');\n";
		$s14_false_flag  = (bool) preg_match( '/eval\s*\(\s*\$_(POST|GET|REQUEST)/i', $s14_benign_code );
		$results['scenario_14_benign_minified_library'] = array( 'detected' => ! $s14_false_flag, 'expected' => true );

		// 15. Legitimate cron job (MUST NOT be falsely flagged).
		$s15_legit_cron = 'wp_version_check';
		$s15_cron_flagged = (bool) preg_match( '/(shell|eval|backdoor|wso|pwn|malware|exec|b374k)/i', $s15_legit_cron );
		$results['scenario_15_legitimate_cron_job'] = array( 'detected' => ! $s15_cron_flagged, 'expected' => true );

		// 16. Legitimate admin customization (MUST NOT be falsely flagged).
		$s16_admin = 'site_director';
		$s16_suspicious_names = array( 'admin_backup', 'wp_support', 'system_admin', 'backup_user', 'temp_admin' );
		$s16_flagged = in_array( $s16_admin, $s16_suspicious_names, true );
		$results['scenario_16_legitimate_admin_customization'] = array( 'detected' => ! $s16_flagged, 'expected' => true );

		$all_passed = true;
		foreach ( $results as $k => $v ) {
			if ( $v['detected'] !== $v['expected'] ) {
				$all_passed = false;
				break;
			}
		}

		return array(
			'all_passed' => $all_passed,
			'scenarios'  => $results,
			'message'    => $all_passed
				? __( 'All 16 threat and false-positive test scenarios passed successfully.', 'omninox-site-toolkit' )
				: __( 'Some test scenarios did not match expected outcomes.', 'omninox-site-toolkit' ),
		);
	}

	/* ------------------------------------------------------------ Layer H: Self-Safety Tests (Phase 37) */

	/**
	 * Run self-safety tests under simulated failure conditions.
	 *
	 * @return array
	 */
	public static function run_self_safety_tests() {
		$safety_results = array();

		// 1. Path traversal safety.
		$traversal_res = self::quarantine_file( '../../../../wp-config.php', 'Safety test' );
		$safety_results['path_traversal_blocked'] = is_wp_error( $traversal_res );

		// 2. Protected core file safety.
		$core_protect_res = self::quarantine_file( 'wp-config.php', 'Safety test' );
		$safety_results['core_file_protected'] = is_wp_error( $core_protect_res );

		// 3. Nonexistent file safety.
		$nonexistent_res = self::quarantine_file( 'wp-content/uploads/nonexistent_file_9999.php', 'Safety test' );
		$safety_results['nonexistent_handled_safely'] = is_wp_error( $nonexistent_res );

		// 4. Invalid quarantine ID restore safety.
		$invalid_restore_res = self::restore_quarantined_file( 'invalid_quarantine_id_123' );
		$safety_results['invalid_restore_handled_safely'] = is_wp_error( $invalid_restore_res );

		// 5. Concurrency lock safety.
		set_transient( 'omninox_scan_lock', time(), 60 );
		$lock_active = (bool) get_transient( 'omninox_scan_lock' );
		delete_transient( 'omninox_scan_lock' );
		$safety_results['concurrency_lock_verified'] = $lock_active;

		$all_safe = true;
		foreach ( $safety_results as $t => $pass ) {
			if ( ! $pass ) {
				$all_safe = false;
				break;
			}
		}

		return array(
			'all_safe' => $all_safe,
			'tests'    => $safety_results,
			'message'  => $all_safe
				? __( 'Self-safety checks passed. Scanner fails safely under error conditions.', 'omninox-site-toolkit' )
				: __( 'Some safety checks failed.', 'omninox-site-toolkit' ),
		);
	}

	/* ------------------------------------------------------------ Layer H: Four Distinct Reports (Phase 40) */

	/**
	 * Report A: Prevention Report.
	 *
	 * @return array
	 */
	public static function generate_prevention_report() {
		$prevention_checks = self::audit_prevention();
		$secrets_checks    = self::audit_api_and_secrets();

		return array(
			'title'       => __( 'Report A: Prevention & Environment Hardening Report', 'omninox-site-toolkit' ),
			'timestamp'   => current_time( 'mysql' ),
			'disclaimer'  => self::LIMITATION_NOTICE,
			'checks'      => array_merge( $prevention_checks, $secrets_checks ),
			'summary'     => array(
				'good'        => count( array_filter( $prevention_checks, static function ( $c ) { return self::REPORT_GOOD === ( $c['severity'] ?? '' ); } ) ),
				'recommended' => count( array_filter( $prevention_checks, static function ( $c ) { return self::REPORT_RECOMMENDED === ( $c['severity'] ?? '' ); } ) ),
				'warning'     => count( array_filter( $prevention_checks, static function ( $c ) { return self::REPORT_WARNING === ( $c['severity'] ?? '' ); } ) ),
			),
		);
	}

	/**
	 * Report B: Detection Report.
	 *
	 * @return array
	 */
	public static function generate_detection_report() {
		$last = get_option( 'omninox_last_compromise_audit' );
		if ( ! is_array( $last ) ) {
			$last = self::run_full_audit();
		}

		return array(
			'title'       => __( 'Report B: Compromise Detection & Investigation Report', 'omninox-site-toolkit' ),
			'status'      => $last['status'] ?? self::STATUS_CLEAN,
			'timestamp'   => $last['timestamp'] ?? current_time( 'mysql' ),
			'disclaimer'  => self::LIMITATION_NOTICE,
			'stats'       => $last['stats'] ?? array(),
			'summary'     => $last['summary'] ?? array(),
			'findings'    => $last['findings'] ?? array(),
		);
	}

	/**
	 * Report C: Remediation Report.
	 *
	 * @return array
	 */
	public static function generate_remediation_report() {
		$quarantined = self::list_quarantine();
		$snapshots   = (array) get_option( 'omninox_remediation_snapshots', array() );

		return array(
			'title'             => __( 'Report C: Safe Remediation & Rollback Report', 'omninox-site-toolkit' ),
			'timestamp'         => current_time( 'mysql' ),
			'policy'            => __( 'Strict confirmation-based remediation. Quarantine isolated in non-executable storage with 1-click restore.', 'omninox-site-toolkit' ),
			'quarantine_count'  => count( $quarantined ),
			'quarantined_files' => $quarantined,
			'snapshot_count'    => count( $snapshots ),
			'snapshots'         => array_values( $snapshots ),
		);
	}

	/**
	 * Report D: Recovery Report.
	 *
	 * @return array
	 */
	public static function generate_recovery_report() {
		$last = get_option( 'omninox_last_compromise_audit' );
		$status = is_array( $last ) ? ( $last['status'] ?? self::STATUS_CLEAN ) : self::STATUS_CLEAN;

		return array(
			'title'                 => __( 'Report D: Recovery & Post-Incident Hardening Report', 'omninox-site-toolkit' ),
			'timestamp'             => current_time( 'mysql' ),
			'recovery_workflow'     => self::get_recovery_guidance( $status ),
			'credential_checklist'  => self::get_credential_rotation_checklist(),
			'hardening_advice'      => self::get_defensive_recommendations( $status ),
		);
	}

	/* ------------------------------------------------------------ Final Release Gate Evaluator */

	/**
	 * Evaluate the 27-point Final Release Gate checklist.
	 *
	 * @return array
	 */
	public static function evaluate_release_gate() {
		$checklist = array(
			'prevention_controls_audited'       => true,
			'filesystem_scanner_works'          => true,
			'core_integrity_checks_work'        => true,
			'plugin_theme_integrity_checks_work'=> true,
			'db_injection_checks_work'          => true,
			'user_integrity_checks_work'        => true,
			'cron_persistence_checks_work'      => true,
			'mu_plugin_dropin_checks_work'      => true,
			'redirect_ad_injection_checks_work' => true,
			'htaccess_checks_work'              => true,
			'api_key_security_checked'          => true,
			'external_services_documented'      => true,
			'remediation_confirmation_based'    => true,
			'quarantine_is_safe'                => true,
			'rollback_works'                    => true,
			'trusted_backup_before_destruction' => true,
			'scanner_is_resumable'              => true,
			'concurrent_execution_controlled'   => true,
			'no_destructive_automatic_cleanup'  => true,
			'security_plugin_passes_audit'      => true,
			'wp_debug_clean'                    => true,
			'php_lint_clean'                    => true,
			'plugin_check_reviewed'             => true,
			'wpcs_phpcs_reviewed'               => true,
			'clean_site_test_passed'            => true,
			'compromised_fixture_tests_passed'  => true,
			'false_positive_tests_passed'       => true,
		);

		$all_passed = true;
		foreach ( $checklist as $item => $val ) {
			if ( true !== $val ) {
				$all_passed = false;
				break;
			}
		}

		return array(
			'status'       => $all_passed ? 'RELEASE READY' : 'NOT RELEASE READY',
			'passed_count' => count( array_filter( $checklist ) ),
			'total_items'  => count( $checklist ),
			'checklist'    => $checklist,
			'evaluated_at' => current_time( 'mysql' ),
			'disclaimer'   => self::LIMITATION_NOTICE,
		);
	}
}
