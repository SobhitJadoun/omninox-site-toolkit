<?php
/**
 * Security guards for the plugin's privileged operations.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Services;

use OmninoxSiteToolkit\Database\LogRepository;

defined( 'ABSPATH' ) || exit;

class Security {

	/**
	 * Extensions that must never be written, uploaded or renamed into place
	 * through this plugin.
	 *
	 * @var array
	 */
	const BLOCKED_WRITE = array(
		'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
		'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'so', 'jsp', 'asp',
		'aspx', 'htaccess', 'htpasswd', 'ini', 'conf',
	);

	/**
	 * Paths and sensitive configuration files that are never exposed.
	 *
	 * @var array
	 */
	const NEVER_EXPOSE = array(
		'wp-config.php',
		'wp-config-sample.php',
		'.env',
		'.env.local',
		'.env.production',
		'.env.development',
		'.git',
		'.svn',
		'.ssh',
		'id_rsa',
		'id_ed25519',
		'.htpasswd',
		'.htaccess',
		'web.config',
	);

	/**
	 * Maximum privileged operations per user per minute for standard UI clicks.
	 */
	const RATE_LIMIT = 300;

	/**
	 * Higher allowance for batch-driven operations.
	 */
	const RATE_LIMIT_BATCH = 6000;

	/**
	 * Operations that run as request batches.
	 *
	 * @var array
	 */
	const BATCH_OPERATIONS = array(
		'backup/backup_database',
		'backup/backup_full',
		'backup/restore_full',
		'backup/restore_database',
		'backup/restore_status',
		'backup/restore_rollback',
		'backup/restore_cancel',
		'backup/import',
		'backup/import_backup',
		'files/upload',
		'media/bulk_convert_webp',
		'media/bulk_convert_avif',
		'media/bulk_compress',
		'media/regenerate_thumbnails',
		'media/replace_original',
		'media/replace_originals',
		'media/replace_originals_with_webp',
		'media/replace_originals_with_avif',
		'media/restore_original',
		'media/restore_originals',
		'media/restore_replaced_originals',
		'media/url_replacement_start',
		'media/url_replacement_step',
		'media/url_replacement_pause',
		'media/url_replacement_resume',
		'media/url_replacement_cancel',
		'media/url_replacement_rollback',
		'media/url_replacement_summary',
		'media/url_replacement_export_csv',
		'media/unused_scan_start',
		'media/unused_scan_step',
		'media/unused_scan_pause',
		'media/unused_scan_resume',
		'media/unused_scan_cancel',
		'media/unused_get_results',
		'media/unused_quarantine',
		'media/unused_restore',
		'media/unused_delete',
		'media/unused_set_protection',
		'media/unused_get_quarantined',
		'media/unused_get_history',
		'database/search_replace',
		'search/search_replace',
	);

	/**
	 * Determine whether an operation qualifies for the higher batch rate limit.
	 *
	 * @param string $operation Operation identifier.
	 * @return bool
	 */
	public static function is_batch_operation( $operation ) {
		if ( in_array( $operation, self::BATCH_OPERATIONS, true ) ) {
			return true;
		}

		$op = strtolower( (string) $operation );
		$tokens = array(
			'step',
			'batch',
			'chunk',
			'url_replacement',
			'unused_',
			'backup',
			'restore',
			'convert',
			'compress',
			'replace',
			'regenerate',
			'search_replace',
			'clean_',
			'optimize_',
		);

		foreach ( $tokens as $token ) {
			if ( false !== strpos( $op, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Assert the current user may run a privileged operation, or verify dedicated restore continuation token.
	 *
	 * @param string $operation Operation name, for the audit trail.
	 * @param string $continuation_token Optional restore continuation token.
	 * @return true|\WP_Error
	 */
	public static function guard( $operation = '', $continuation_token = '' ) {
		if ( current_user_can( 'manage_options' ) ) {
			if ( is_multisite() && ! current_user_can( 'manage_network_options' ) && self::is_network_sensitive( $operation ) ) {
				return self::deny( 'omninox_network_only', __( 'This operation is restricted to network administrators on a multisite install.', 'omninox-site-toolkit' ), 403, $operation );
			}

			$limited = self::rate_limit( self::is_batch_operation( $operation ) );

			if ( is_wp_error( $limited ) ) {
				return $limited;
			}

			return true;
		}

		// Dedicated restore continuation token check (when operator session cookies are invalidated by database replacement)
		$restore_ops = array(
			'backup/restore_full',
			'backup/restore_database',
			'backup/restore_status',
			'backup/restore_rollback',
			'backup/restore_cancel',
		);

		if ( in_array( $operation, $restore_ops, true ) && ! empty( $continuation_token ) ) {
			$backup_module = \OmninoxSiteToolkit\Core\ModuleRegistry::instance()->get( 'backup' );
			if ( $backup_module && method_exists( $backup_module, 'verify_continuation_token' ) ) {
				$action = substr( $operation, strlen( 'backup/' ) );
				$valid  = $backup_module->verify_continuation_token( $continuation_token, $action );
				if ( true === $valid ) {
					return true;
				}
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
		}

		if ( ! is_user_logged_in() ) {
			return self::deny( 'omninox_not_logged_in', __( 'You must be signed in.', 'omninox-site-toolkit' ), 401, $operation );
		}

		return self::deny( 'omninox_forbidden', __( 'Your account cannot manage this toolkit.', 'omninox-site-toolkit' ), 403, $operation );
	}

	/**
	 * Operations that touch shared multisite resources.
	 *
	 * @param string $operation Operation name.
	 * @return bool
	 */
	private static function is_network_sensitive( $operation ) {
		foreach ( array( 'file', 'backup', 'restore', 'database', 'import' ) as $needle ) {
			if ( false !== strpos( (string) $operation, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Throttle privileged calls per user.
	 *
	 * @param bool $batch Whether this is a batch-driven operation.
	 * @return true|\WP_Error
	 */
	private static function rate_limit( $batch = false ) {
		$key    = 'omninox_rl_' . ( $batch ? 'b_' : '' ) . get_current_user_id();
		$limit  = $batch ? self::RATE_LIMIT_BATCH : self::RATE_LIMIT;
		$window = (array) get_transient( $key );
		$now    = time();

		$window = array_filter(
			$window,
			static function ( $stamp ) use ( $now ) {
				return $stamp > ( $now - MINUTE_IN_SECONDS );
			}
		);

		if ( count( $window) >= $limit ) {
			return new \WP_Error(
				'omninox_rate_limited',
				__( 'Too many operations in a short time. Wait a minute and try again.', 'omninox-site-toolkit' ),
				array( 'status' => 429 )
			);
		}

		$window[] = $now;
		set_transient( $key, $window, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Record and return a denial.
	 *
	 * @param string $code      Error code.
	 * @param string $message   Message.
	 * @param int    $status    HTTP status.
	 * @param string $operation Operation.
	 * @return \WP_Error
	 */
	private static function deny( $code, $message, $status, $operation ) {
		self::audit( 'denied', $operation, $message );

		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Resolve a user-supplied path inside an allowed root.
	 *
	 * Returns an error rather than a path for anything that escapes the root,
	 * follows a symlink out of it, or names a protected file.
	 *
	 * @param string $relative Path relative to the root.
	 * @param string $root     Absolute root directory.
	 * @param bool   $must_exist Whether the path has to exist already.
	 * @return string|\WP_Error Absolute path.
	 */
	public static function resolve_path( $relative, $root, $must_exist = true ) {
		$root_real = realpath( $root );

		if ( ! $root_real ) {
			return new \WP_Error( 'omninox_bad_root', __( 'The base directory does not exist.', 'omninox-site-toolkit' ), array( 'status' => 500 ) );
		}

		$root          = wp_normalize_path( $root_real );
		$relative      = str_replace( array( "\0", '..' ), '', (string) $relative );
		$relative      = ltrim( str_replace( '\\', '/', $relative ), '/' );
		$candidate     = $relative ? $root . '/' . $relative : $root;
		$resolved_real = realpath( $candidate );

		if ( ! $resolved_real ) {
			if ( $must_exist ) {
				return new \WP_Error( 'omninox_path_missing', __( 'That path does not exist.', 'omninox-site-toolkit' ), array( 'status' => 404 ) );
			}

			// For a path being created, the parent must resolve inside the root.
			$parent_real = realpath( dirname( $candidate ) );

			if ( ! $parent_real ) {
				return new \WP_Error( 'omninox_path_escape', __( 'That location is outside the allowed directory.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
			}

			$parent = wp_normalize_path( $parent_real );

			if ( 0 !== strpos( $parent . '/', $root . '/' ) && $parent !== $root ) {
				return new \WP_Error( 'omninox_path_escape', __( 'That location is outside the allowed directory.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
			}

			$resolved = $parent . '/' . basename( $candidate );
		} else {
			$resolved = wp_normalize_path( $resolved_real );
		}

		if ( 0 !== strpos( $resolved . '/', $root . '/' ) && $resolved !== $root ) {
			return new \WP_Error( 'omninox_path_escape', __( 'That location is outside the allowed directory.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		foreach ( self::NEVER_EXPOSE as $protected ) {
			if ( false !== stripos( $resolved, '/' . $protected ) || false !== stripos( basename( $resolved ), $protected ) ) {
				return new \WP_Error(
					'omninox_path_protected',
					__( 'That file is protected and cannot be read or changed from here.', 'omninox-site-toolkit' ),
					array( 'status' => 403 )
				);
			}
		}

		return $resolved;
	}

	/**
	 * Is this filename safe to write?
	 *
	 * @param string $filename Filename.
	 * @return true|\WP_Error
	 */
	public static function check_writable_name( $filename ) {
		$filename = basename( (string) $filename );

		if ( '' === $filename || '.' === $filename[0] ) {
			return new \WP_Error( 'omninox_bad_filename', __( 'Give the file a normal name that does not start with a dot.', 'omninox-site-toolkit' ), array( 'status' => 400 ) );
		}

		foreach ( self::NEVER_EXPOSE as $protected ) {
			if ( 0 === strcasecmp( $filename, $protected ) ) {
				return new \WP_Error( 'omninox_blocked_filename', __( 'That file name is protected and cannot be written.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
			}
		}

		$parts     = explode( '.', strtolower( $filename ) );
		$extension = end( $parts );

		$blocked = (array) apply_filters( 'omninox_blocked_write_extensions', self::BLOCKED_WRITE );

		// Guard against double extensions such as shell.php.txt.
		foreach ( $parts as $part ) {
			if ( in_array( $part, $blocked, true ) ) {
				return new \WP_Error(
					'omninox_blocked_extension',
					sprintf(
						/* translators: %s: file extension */
						__( 'Files with a .%s part cannot be written through this plugin, because it would allow code execution from the browser.', 'omninox-site-toolkit' ),
						$part
					),
					array( 'status' => 403 )
				);
			}
		}

		if ( in_array( $extension, $blocked, true ) ) {
			return new \WP_Error( 'omninox_blocked_extension', __( 'That file type cannot be written through this plugin.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Issue a short-lived, single-purpose download token.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	public static function issue_token( $path ) {
		$token = wp_generate_password( 32, false );

		set_transient(
			'omninox_dl_' . $token,
			array(
				'path' => $path,
				'user' => get_current_user_id(),
			),
			5 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	/**
	 * Build a secure, nonce-protected download URL for a token.
	 *
	 * Uses the dedicated 'omninox_download' nonce action.
	 *
	 * @param string $token Single-use download token.
	 * @return string Nonce-protected download URL.
	 */
	public static function generate_download_url( $token ) {
		return add_query_arg(
			array(
				'action'   => 'omninox_download',
				'token'    => (string) $token,
				'_wpnonce' => wp_create_nonce( 'omninox_download' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Redeem a download token.
	 *
	 * Validates token existence, expiry, single-use, and user binding/ownership.
	 *
	 * @param string $token Token.
	 * @return string|\WP_Error Absolute path.
	 */
	public static function redeem_token( $token ) {
		$clean_token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( empty( $clean_token ) ) {
			return new \WP_Error( 'omninox_bad_token', __( 'Invalid download token.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		$key  = 'omninox_dl_' . $clean_token;
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['path'] ) ) {
			return new \WP_Error( 'omninox_bad_token', __( 'That download link has expired. Generate a new one.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		// Token binding/ownership validation.
		$current_user_id = get_current_user_id();
		if ( ! empty( $data['user'] ) && (int) $data['user'] !== (int) $current_user_id ) {
			return new \WP_Error( 'omninox_token_unauthorized', __( 'You are not authorized to use this download token.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
		}

		delete_transient( $key );

		return $data['path'];
	}

	/**
	 * Write an entry to the audit trail.
	 *
	 * @param string $outcome   allowed|denied|performed.
	 * @param string $operation Operation name.
	 * @param string $detail    Extra detail.
	 */
	public static function audit( $outcome, $operation, $detail = '' ) {
		$user = wp_get_current_user();

		LogRepository::record(
			array(
				'type'    => 'security',
				'level'   => 'denied' === $outcome ? 'warning' : 'info',
				'message' => sprintf(
					/* translators: 1: outcome, 2: operation, 3: user, 4: detail */
					__( '%1$s: %2$s by %3$s. %4$s', 'omninox-site-toolkit' ),
					ucfirst( $outcome ),
					$operation,
					( is_object( $user ) && method_exists( $user, 'exists' ) && $user->exists() ) ? $user->user_login : __( 'system', 'omninox-site-toolkit' ),
					$detail
				),
				'source'  => 'omninox/' . $operation,
			)
		);
	}

	/**
	 * Human readable file permissions.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function permissions( $path ) {
		if ( ! file_exists( $path ) ) {
			return '0000';
		}
		return substr( sprintf( '%o', fileperms( $path ) ), -4 );
	}
}
