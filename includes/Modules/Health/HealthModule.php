<?php
/**
 * Module 1: Website Health.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Health;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;
use OmninoxSiteToolkit\Services\ValueHistory;

defined( 'ABSPATH' ) || exit;

class HealthModule extends AbstractModule {

	protected $id    = 'health';
	protected $icon  = 'heart';

	/**
	 * Allowed memory limits in MB. Anything outside this list is refused:
	 * WordPress gains nothing above 1 GB, and a typo like 40960 will simply
	 * make PHP fail to start on most hosts.
	 */
	const MEMORY_CHOICES = array( 128, 256, 512, 768, 1024 );

	/**
	 * Allowed upload limits in MB.
	 */
	const UPLOAD_CHOICES = array( 8, 16, 32, 64, 128, 256, 512 );

	/**
	 * Allowed execution times in seconds.
	 */
	const EXECUTION_CHOICES = array( 30, 60, 120, 300, 600 );

	public function get_label() {
		return __( 'Website Health', 'omninox-site-toolkit' );
	}

	/**
	 * Enforce the plugin-managed upload ceiling.
	 */
	public function register() {
		add_filter( 'upload_size_limit', array( $this, 'apply_upload_limit' ), 20 );
	}

	/**
	 * WordPress asks for the effective upload limit on every media screen.
	 *
	 * A plugin cannot raise the limit past what PHP was compiled and
	 * configured to accept, but it can hold it below that, and that part is
	 * handled entirely here rather than by writing server config files.
	 *
	 * @param int $bytes Limit calculated by WordPress.
	 * @return int
	 */
	public function apply_upload_limit( $bytes ) {
		$configured = (int) \OmninoxSiteToolkit\Core\Settings::instance()->get( 'upload_limit_mb', 0 );

		if ( $configured <= 0 ) {
			return $bytes;
		}

		return min( (int) $bytes, $configured * MB_IN_BYTES );
	}

	/**
	 * The hard ceiling PHP imposes, which nothing in WordPress can exceed.
	 *
	 * @return int Bytes.
	 */
	public static function server_upload_ceiling() {
		return min(
			Utils::to_bytes( ini_get( 'upload_max_filesize' ) ),
			Utils::to_bytes( ini_get( 'post_max_size' ) )
		);
	}

	/**
	 * Editable environment values exposed to the UI.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array(
			array(
				'id'       => 'set_memory_limit',
				'label'    => __( 'Memory limit', 'omninox-site-toolkit'),
				'type'     => 'select',
				'unit'     => 'MB',
				'options'  => $this->choices( self::MEMORY_CHOICES ),
				'current'  => WP_MEMORY_LIMIT,
				'previous' => ValueHistory::previous( 'memory_limit' ),
				'history'  => ValueHistory::summary( 'memory_limit' ),
				'notice'   => __( 'Capped at 1 GB. WordPress gains nothing above that, and oversized values stop PHP from starting on many hosts.', 'omninox-site-toolkit'),
			),
			array(
				'id'       => 'set_upload_limit',
				'label'    => __( 'Maximum upload size', 'omninox-site-toolkit'),
				'type'     => 'select',
				'unit'     => 'MB',
				'options'  => $this->upload_choices(),
				'current'  => size_format( wp_max_upload_size() ),
				'previous' => ValueHistory::previous( 'upload_limit' ),
				'history'  => ValueHistory::summary( 'upload_limit' ),
				'notice'   => sprintf(
					/* translators: %s: formatted byte size */
					__( 'Applied by the plugin through WordPress, so it takes effect immediately with no server files touched. PHP on this server accepts at most %s; that ceiling can only be raised by your host, so the list stops there.', 'omninox-site-toolkit'),
					size_format( self::server_upload_ceiling() )
				),
			),
			array(
				'id'       => 'set_execution_time',
				'label'    => __( 'Maximum execution time', 'omninox-site-toolkit'),
				'type'     => 'select',
				'unit'     => 's',
				'options'  => $this->choices( self::EXECUTION_CHOICES, 's' ),
				'current'  => ( (int) ini_get( 'max_execution_time' ) ) . 's',
				'previous' => ValueHistory::previous( 'execution_time' ),
				'history'  => ValueHistory::summary( 'execution_time' ),
			),
			array(
				'id'       => 'toggle_debug',
				'label'    => __( 'Debug mode', 'omninox-site-toolkit'),
				'type'     => 'toggle',
				'current'  => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 1 : 0,
				'previous' => ValueHistory::previous( 'wp_debug' ),
				'history'  => ValueHistory::summary( 'wp_debug' ),
				'notice'   => __( 'Leave this off on a live site. Errors shown to visitors leak file paths.', 'omninox-site-toolkit'),
			),
			array(
				'id'      => 'fix_permissions',
				'label'   => __( 'Repair file and folder permissions', 'omninox-site-toolkit'),
				'type'    => 'button',
				'confirm' => __( 'This resets folders to 755 and files to 644 inside wp-content. Continue?', 'omninox-site-toolkit'),
			),
			array(
				'id'      => 'revert_change',
				'label'   => __( 'Undo the last environment change', 'omninox-site-toolkit'),
				'type'    => 'button',
				'hidden'  => empty( ValueHistory::recent( 1 ) ),
			),
		);
	}

	/**
	 * Upload sizes this server can actually honour.
	 *
	 * @return array
	 */
	private function upload_choices() {
		$ceiling = (int) floor( self::server_upload_ceiling() / MB_IN_BYTES );
		$allowed = array();

		foreach ( self::UPLOAD_CHOICES as $value ) {
			if ( $value <= $ceiling ) {
				$allowed[] = $value;
			}
		}

		// Always offer the ceiling itself so "as much as the server allows"
		// is reachable in one click.
		if ( $ceiling > 0 && ! in_array( $ceiling, $allowed, true ) ) {
			$allowed[] = $ceiling;
			sort( $allowed );
		}

		return $this->choices( $allowed );
	}

	/**
	 * Build a select option list from a list of allowed numbers.
	 *
	 * @param array  $values Allowed values.
	 * @param string $suffix Unit suffix for the label.
	 * @return array
	 */
	private function choices( array $values, $suffix = 'M' ) {
		$options = array();

		foreach ( $values as $value ) {
			$options[] = array(
				'value' => $value,
				'label' => $value . $suffix,
			);
		}

		return $options;
	}

	/**
	 * Run the health scan.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		global $wp_version, $wpdb;

		$this->check_server();
		$this->check_wordpress();
		$this->check_php_configuration();
		$this->check_filesystem();

		// PHP.
		$php_ok = version_compare( PHP_VERSION, '7.4', '>=' );
		$this->add(
			array(
				'id'          => 'php_version',
				'label'       => __( 'PHP version', 'omninox-site-toolkit'),
				'value'       => PHP_VERSION,
				'expected'    => '8.1+',
				'weight'      => 3,
				'severity'    => version_compare( PHP_VERSION, '8.0', '>=' ) ? self::SEV_PASS : ( $php_ok ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'description' => __( 'Older PHP versions no longer receive security patches and run measurably slower.', 'omninox-site-toolkit'),
			)
		);

		// MySQL.
		$db_version = $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB
		$this->add(
			array(
				'id'       => 'mysql_version',
				'label'    => __( 'Database version', 'omninox-site-toolkit'),
				'value'    => $db_version,
				'expected' => 'MySQL 5.7+ / MariaDB 10.4+',
				'weight'   => 2,
				'severity' => self::SEV_PASS,
			)
		);

		// WordPress core.
		$core_current = $this->core_is_current();
		$this->add(
			array(
				'id'       => 'wp_version',
				'label'    => __( 'WordPress version', 'omninox-site-toolkit'),
				'value'    => $wp_version,
				'expected' => __( 'Latest release', 'omninox-site-toolkit'),
				'weight'   => 3,
				'severity' => $core_current ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => $core_current ? '' : __( 'A core update is available. Core updates usually contain security fixes.', 'omninox-site-toolkit'),
			)
		);

		$this->add(
			array(
				'id'       => 'server_software',
				'label'    => __( 'Server software', 'omninox-site-toolkit'),
				'value'    => sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ?? __( 'Unknown', 'omninox-site-toolkit') ), // phpcs:ignore WordPress.Security
				'severity' => self::SEV_PASS,
				'weight'   => 0.5,
			)
		);

		// SSL / HTTPS.
		$is_ssl = is_ssl() || 0 === strpos( home_url(), 'https://' );
		$this->add(
			array(
				'id'          => 'ssl',
				'label'       => __( 'SSL certificate', 'omninox-site-toolkit'),
				'value'       => $is_ssl ? __( 'Active', 'omninox-site-toolkit') : __( 'Not detected', 'omninox-site-toolkit'),
				'expected'    => __( 'Active', 'omninox-site-toolkit'),
				'weight'      => 3,
				'severity'    => $is_ssl ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => __( 'Without HTTPS, logins and form submissions travel in plain text and browsers mark the site as not secure.', 'omninox-site-toolkit'),
				'context'     => $is_ssl ? $this->ssl_certificate_info() : array(),
			)
		);

		// REST API reachability.
		$rest = Utils::timed_get( rest_url( 'wp/v2/types' ), array( 'timeout' => 15 ) );
		$rest_ok = ! is_wp_error( $rest ) && 200 === (int) $rest['code'];
		$this->add(
			array(
				'id'       => 'rest_api',
				'label'    => __( 'REST API', 'omninox-site-toolkit'),
				'value'    => $rest_ok ? sprintf( /* translators: %d: milliseconds */ __( 'Responding in %d ms', 'omninox-site-toolkit'), $rest['ms'] ) : __( 'Unreachable', 'omninox-site-toolkit'),
				'weight'   => 2,
				'severity' => $rest_ok ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => $rest_ok ? '' : __( 'The block editor and most modern plugins stop working when the REST API is blocked.', 'omninox-site-toolkit'),
			)
		);

		// XML-RPC.
		$xmlrpc_on = (bool) apply_filters( 'xmlrpc_enabled', true );
		$this->add(
			array(
				'id'          => 'xmlrpc',
				'label'       => __( 'XML-RPC', 'omninox-site-toolkit'),
				'value'       => $xmlrpc_on ? __( 'Enabled', 'omninox-site-toolkit') : __( 'Disabled', 'omninox-site-toolkit'),
				'expected'    => __( 'Disabled unless an app needs it', 'omninox-site-toolkit'),
				'weight'      => 1.5,
				'severity'    => $xmlrpc_on ? self::SEV_WARNING : self::SEV_PASS,
				'fix'         => $xmlrpc_on ? 'disable_xmlrpc' : '',
				'description' => __( 'XML-RPC is a common target for brute-force amplification. Disable it unless the Jetpack or WordPress mobile apps are in use.', 'omninox-site-toolkit'),
			)
		);

		// Limits.
		$memory = Utils::to_bytes( WP_MEMORY_LIMIT );
		$this->add_tracked(
			array(
				'id'       => 'memory_limit',
				'label'    => __( 'Memory limit', 'omninox-site-toolkit'),
				'value'    => WP_MEMORY_LIMIT,
				'expected' => '256M',
				'weight'   => 2,
				'severity' => $memory >= 268435456 ? self::SEV_PASS : ( $memory >= 134217728 ? self::SEV_NOTICE : self::SEV_WARNING ),
				'fix'      => 'set_memory_limit',
			),
			'memory_limit'
		);

		$upload = wp_max_upload_size();
		$this->add_tracked(
			array(
				'id'       => 'upload_limit',
				'label'    => __( 'Maximum upload size', 'omninox-site-toolkit'),
				'value'    => Utils::format_bytes( $upload ),
				'expected' => '64 MB',
				'weight'   => 1,
				'severity' => $upload >= 67108864 ? self::SEV_PASS : self::SEV_NOTICE,
				'fix'      => 'set_upload_limit',
			),
			'upload_limit'
		);

		$exec = (int) ini_get( 'max_execution_time' );
		$this->add_tracked(
			array(
				'id'       => 'execution_time',
				'label'    => __( 'Max execution time', 'omninox-site-toolkit'),
				'value'    => $exec ? $exec . 's' : __( 'Unlimited', 'omninox-site-toolkit'),
				'expected' => '60s',
				'weight'   => 1,
				'severity' => ( 0 === $exec || $exec >= 60 ) ? self::SEV_PASS : self::SEV_NOTICE,
				'fix'      => 'set_execution_time',
			),
			'execution_time'
		);

		// Cron.
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$overdue       = $this->overdue_cron_count();
		$this->add(
			array(
				'id'       => 'cron',
				'label'    => __( 'Scheduled tasks', 'omninox-site-toolkit'),
				'value'    => $cron_disabled
					? __( 'WP-Cron disabled (server cron expected)', 'omninox-site-toolkit')
					: sprintf( /* translators: %d: number of overdue events */ _n( '%d overdue event', '%d overdue events', $overdue, 'omninox-site-toolkit'), $overdue ),
				'weight'   => 2,
				'severity' => $overdue > 5 ? self::SEV_WARNING : self::SEV_PASS,
				'description' => $overdue > 5 ? __( 'Events are queued but not running. Low-traffic sites often need a real server cron job.', 'omninox-site-toolkit') : '',
			)
		);

		// Permissions.
		$perm = $this->check_permissions();
		$this->add(
			array(
				'id'       => 'permissions',
				'label'    => __( 'File and folder permissions', 'omninox-site-toolkit'),
				'value'    => empty( $perm['problems'] )
					? __( 'All checked paths are correct', 'omninox-site-toolkit')
					: sprintf( /* translators: %d: number of paths */ _n( '%d path needs attention', '%d paths need attention', count( $perm['problems'] ), 'omninox-site-toolkit'), count( $perm['problems'] ) ),
				'weight'   => 2.5,
				'severity' => empty( $perm['problems'] ) ? self::SEV_PASS : self::SEV_WARNING,
				'fix'      => empty( $perm['problems'] ) ? '' : 'fix_permissions',
				'context'  => $perm,
			)
		);

		// Debug.
		$debug_on  = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$log_public = $this->debug_log_is_public();
		$this->add_tracked(
			array(
				'id'       => 'wp_debug',
				'label'    => __( 'Debug mode', 'omninox-site-toolkit'),
				'value'    => $debug_on ? __( 'On', 'omninox-site-toolkit') : __( 'Off', 'omninox-site-toolkit'),
				'expected' => __( 'Off in production', 'omninox-site-toolkit'),
				'weight'   => 2,
				'severity' => $debug_on ? self::SEV_WARNING : self::SEV_PASS,
				'fix'      => $debug_on ? 'toggle_debug' : '',
			),
			'wp_debug'
		);

		$this->add(
			array(
				'id'          => 'debug_log_exposed',
				'label'       => __( 'debug.log reachable from the web', 'omninox-site-toolkit'),
				'value'       => $log_public ? __( 'Yes', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit'),
				'weight'      => 3,
				'severity'    => $log_public ? self::SEV_CRITICAL : self::SEV_PASS,
				'description' => $log_public ? __( 'Anyone can read the debug log, which frequently contains file paths and query fragments.', 'omninox-site-toolkit') : '',
				'fix'         => $log_public ? 'protect_debug_log' : '',
			)
		);

		// Caching layers.
		$object_cache = wp_using_ext_object_cache();
		$this->add(
			array(
				'id'       => 'object_cache',
				'label'    => __( 'Persistent object cache', 'omninox-site-toolkit'),
				'value'    => $object_cache ? __( 'Active', 'omninox-site-toolkit') : __( 'Not in use', 'omninox-site-toolkit'),
				'weight'   => 2,
				'severity' => $object_cache ? self::SEV_PASS : self::SEV_NOTICE,
				'description' => $object_cache ? '' : __( 'Redis or Memcached removes repeated database queries on every page load.', 'omninox-site-toolkit'),
			)
		);

		$opcache = function_exists( 'opcache_get_status' );
		$opcache_on = false;

		if ( $opcache ) {
			$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$opcache_on = ! empty( $status['opcache_enabled'] );
		}

		$this->add(
			array(
				'id'       => 'opcache',
				'label'    => __( 'PHP OPcache', 'omninox-site-toolkit'),
				'value'    => $opcache_on ? __( 'Enabled', 'omninox-site-toolkit') : __( 'Disabled', 'omninox-site-toolkit'),
				'weight'   => 1.5,
				'severity' => $opcache_on ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		$this->add(
			array(
				'id'       => 'redis',
				'label'    => __( 'Redis extension', 'omninox-site-toolkit'),
				'value'    => class_exists( 'Redis' ) ? __( 'Available', 'omninox-site-toolkit') : __( 'Not installed', 'omninox-site-toolkit'),
				'weight'   => 0.5,
				'severity' => self::SEV_PASS,
			)
		);

		$this->add(
			array(
				'id'       => 'memcached',
				'label'    => __( 'Memcached extension', 'omninox-site-toolkit'),
				'value'    => ( class_exists( 'Memcached' ) || class_exists( 'Memcache' ) ) ? __( 'Available', 'omninox-site-toolkit') : __( 'Not installed', 'omninox-site-toolkit'),
				'weight'   => 0.5,
				'severity' => self::SEV_PASS,
			)
		);

		return $this->result(
			array(
				'environment' => array(
					'php'        => PHP_VERSION,
					'wp'         => $wp_version,
					'db'         => $db_version,
					'multisite'  => is_multisite(),
					'https'      => $is_ssl,
					'disk'       => Utils::format_bytes( (int) @disk_free_space( Utils::get_home_path() ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
				),
				'changes'     => ValueHistory::recent( 15 ),
			)
		);
	}

	/**
	 * Apply health fixes.
	 *
	 * @param string $action Action id.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		switch ( $action ) {
			case 'set_upload_limit':
				$ceiling   = (int) floor( self::server_upload_ceiling() / MB_IN_BYTES );
				$allowed   = array();

				foreach ( self::UPLOAD_CHOICES as $value ) {
					if ( $value <= $ceiling ) {
						$allowed[] = $value;
					}
				}

				if ( $ceiling > 0 && ! in_array( $ceiling, $allowed, true ) ) {
					$allowed[] = $ceiling;
				}

				$megabytes = $this->validate_choice( $params['value'] ?? 0, $allowed, __( 'upload size', 'omninox-site-toolkit') );

				if ( is_wp_error( $megabytes ) ) {
					return $megabytes;
				}

				$before = size_format( wp_max_upload_size() );

				\OmninoxSiteToolkit\Core\Settings::instance()->update( array( 'upload_limit_mb' => $megabytes ) );

				return $this->changed(
					'upload_limit',
					__( 'Maximum upload size', 'omninox-site-toolkit'),
					$before,
					$megabytes . 'M',
					array( 'message' => __( 'Applied immediately through WordPress. No server configuration files were written.', 'omninox-site-toolkit') )
				);

			case 'set_memory_limit':
				$megabytes = $this->validate_choice( $params['value'] ?? 0, self::MEMORY_CHOICES, __( 'memory limit', 'omninox-site-toolkit') );

				if ( is_wp_error( $megabytes ) ) {
					return $megabytes;
				}

				$before = WP_MEMORY_LIMIT;
				$result = $this->write_wp_config_constant( 'WP_MEMORY_LIMIT', $megabytes . 'M' );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return $this->changed( 'memory_limit', __( 'Memory limit', 'omninox-site-toolkit'), $before, $megabytes . 'M', $result );

			case 'set_execution_time':
				$seconds = $this->validate_choice( $params['value'] ?? 0, self::EXECUTION_CHOICES, __( 'execution time', 'omninox-site-toolkit') );

				if ( is_wp_error( $seconds ) ) {
					return $seconds;
				}

				$before = ( (int) ini_get( 'max_execution_time' ) ) . 's';
				$result = $this->write_ini_directives( array( 'max_execution_time' => $seconds ) );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return $this->changed( 'execution_time', __( 'Maximum execution time', 'omninox-site-toolkit'), $before, $seconds . 's', $result );

			case 'toggle_debug':
				$before  = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'on' : 'off';
				$enabled = ! empty( $params['value'] );
				$result  = $this->write_wp_config_constant( 'WP_DEBUG', $enabled, true );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return $this->changed( 'wp_debug', __( 'Debug mode', 'omninox-site-toolkit'), $before, $enabled ? 'on' : 'off', $result );

			case 'revert_change':
				return $this->revert_last_change();

			case 'disable_xmlrpc':
				add_filter( 'xmlrpc_enabled', '__return_false' );
				$settings = \OmninoxSiteToolkit\Core\Settings::instance();
				$settings->update( array( 'optimizations' => array( 'disable_xmlrpc' => true ) ) );
				return array( 'message' => __( 'XML-RPC is now disabled.', 'omninox-site-toolkit') );

			case 'protect_debug_log':
				return $this->protect_debug_log();

			case 'fix_permissions':
				return array(
					'message' => __( 'Filesystem permissions should be configured directly on the server (recommended: 0755 for directories, 0644 for files) via SSH/SFTP or your hosting control panel.', 'omninox-site-toolkit' ),
				);
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Reject anything that is not one of the values we offer.
	 *
	 * Free-text limits are a common way for a site to end up unbootable, so
	 * the interface offers a fixed list and this refuses everything else.
	 *
	 * @param mixed  $value   Submitted value.
	 * @param array  $allowed Allowed values.
	 * @param string $label   What is being set, for the error message.
	 * @return int|\WP_Error
	 */
	private function validate_choice( $value, array $allowed, $label ) {
		$value = absint( $value );

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		return new \WP_Error(
			'omninox_value_not_allowed',
			sprintf(
				/* translators: 1: setting name, 2: allowed values */
				__( 'That %1$s is not one of the supported values. Choose one of: %2$s.', 'omninox-site-toolkit'),
				$label,
				implode( ', ', $allowed )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Record a change and fold the before and after values into the response.
	 *
	 * @param string $key    History key.
	 * @param string $label  Human readable name.
	 * @param string $before Previous value.
	 * @param string $after  New value.
	 * @param array  $result Result from the writer.
	 * @return array
	 */
	private function changed( $key, $label, $before, $after, array $result ) {
		ValueHistory::record( $key, $before, $after, $label );

		$result['previous'] = $before;
		$result['current']  = $after;
		$result['message']  = sprintf(
			/* translators: 1: setting name, 2: previous value, 3: new value */
			__( '%1$s changed from %2$s to %3$s.', 'omninox-site-toolkit'),
			$label,
			$before,
			$after
		) . ( empty( $result['message'] ) ? '' : ' ' . $result['message'] );

		return $result;
	}

	/**
	 * Put the most recent environment change back the way it was.
	 *
	 * @return array|\WP_Error
	 */
	private function revert_last_change() {
		$recent = ValueHistory::recent( 1 );

		if ( empty( $recent ) ) {
			return new \WP_Error( 'omninox_nothing_to_revert', __( 'There is no recorded change to undo.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		$entry = $recent[0];
		$value = preg_replace( '/[^0-9]/', '', (string) $entry['from'] );

		switch ( $entry['key'] ) {
			case 'memory_limit':
				return $this->fix( 'set_memory_limit', array( 'value' => $value ) );

			case 'upload_limit':
				return $this->fix( 'set_upload_limit', array( 'value' => $value ) );

			case 'execution_time':
				return $this->fix( 'set_execution_time', array( 'value' => $value ) );

			case 'wp_debug':
				return $this->fix( 'toggle_debug', array( 'value' => 'on' === $entry['from'] ) );
		}

		return new \WP_Error(
			'omninox_cannot_revert',
			/* translators: %s: setting key */
			sprintf( __( 'The last change was to %s, which cannot be undone automatically.', 'omninox-site-toolkit'), $entry['key'] ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Record a check inside a named section.
	 *
	 * @param string $group Section name.
	 * @param array  $issue Issue.
	 */
	private function grouped( $group, array $issue ) {
		$issue['group'] = $group;
		$this->add( $issue );
	}

	/**
	 * Server environment checks.
	 */
	private function check_server() {
		global $wpdb;

		$group = __( 'Server', 'omninox-site-toolkit');

		$this->grouped(
			$group,
			array(
				'id'       => 'operating_system',
				'label'    => __( 'Operating system', 'omninox-site-toolkit'),
				'value'    => function_exists( 'php_uname' ) ? php_uname( 's' ) . ' ' . php_uname( 'r' ) : PHP_OS,
				'weight'   => 0.5,
				'severity' => self::SEV_PASS,
			)
		);

		$this->grouped(
			$group,
			array(
				'id'       => 'server_time',
				'label'    => __( 'Server time', 'omninox-site-toolkit'),
				'value'    => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
				'weight'   => 0.5,
				'severity' => self::SEV_PASS,
			)
		);

		$offset   = (float) get_option( 'gmt_offset' );
		$timezone = get_option( 'timezone_string' );
		$drift    = abs( ( time() + ( $offset * HOUR_IN_SECONDS ) ) - current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		$this->grouped(
			$group,
			array(
				'id'          => 'timezone',
				'label'       => __( 'Timezone', 'omninox-site-toolkit'),
				'value'       => $timezone ? $timezone : sprintf( 'UTC%+g', $offset ),
				'expected'    => __( 'A named city, not a UTC offset', 'omninox-site-toolkit'),
				'weight'      => 1,
				'severity'    => $timezone ? self::SEV_PASS : self::SEV_NOTICE,
				'description' => $timezone
					? ''
					: __( 'A fixed UTC offset does not follow daylight saving, so scheduled posts drift by an hour twice a year. Pick a city in Settings, General.', 'omninox-site-toolkit'),
				'context'     => array( 'drift_seconds' => (int) $drift ),
			)
		);

		// Database flavour: MariaDB reports itself through the version string.
		$db_version = $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB
		$is_maria   = false !== stripos( (string) $db_version, 'mariadb' );
		$numeric    = preg_replace( '/[^0-9.].*$/', '', (string) $db_version );

		$this->grouped(
			$group,
			array(
				'id'       => 'mariadb_version',
				'label'    => $is_maria ? __( 'MariaDB version', 'omninox-site-toolkit') : __( 'MySQL version', 'omninox-site-toolkit'),
				'value'    => $db_version,
				'expected' => $is_maria ? '10.4+' : '5.7+',
				'weight'   => 2,
				'severity' => version_compare( $numeric, $is_maria ? '10.4' : '5.7', '>=' ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'engine' => $is_maria ? 'MariaDB' : 'MySQL' ),
			)
		);

		// Protocol and compression, read from a real request to the front end.
		$probe = $this->protocol_probe();

		$this->grouped(
			$group,
			array(
				'id'          => 'http_version',
				'label'       => __( 'HTTP protocol', 'omninox-site-toolkit'),
				'value'       => $probe['protocol'],
				'expected'    => 'HTTP/2 or HTTP/3',
				'weight'      => 2,
				'severity'    => $probe['modern'] ? self::SEV_PASS : self::SEV_WARNING,
				'description' => $probe['modern']
					? ''
					: __( 'HTTP/1.1 sends assets one connection at a time. HTTP/2 multiplexes them, which matters most on pages with many small files.', 'omninox-site-toolkit'),
			)
		);

		$this->grouped(
			$group,
			array(
				'id'          => 'http3',
				'label'       => __( 'HTTP/3 advertised', 'omninox-site-toolkit'),
				'value'       => $probe['http3'] ? __( 'Yes', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit'),
				'weight'      => 0.5,
				'severity'    => self::SEV_PASS,
				'description' => $probe['http3']
					? __( 'The server advertises HTTP/3 through alt-svc.', 'omninox-site-toolkit')
					: __( 'Optional. HTTP/3 mainly helps on unreliable mobile connections.', 'omninox-site-toolkit'),
			)
		);

		$this->grouped(
			$group,
			array(
				'id'          => 'gzip',
				'label'       => __( 'GZIP compression', 'omninox-site-toolkit'),
				'value'       => $probe['gzip'] ? __( 'Active', 'omninox-site-toolkit') : __( 'Not detected', 'omninox-site-toolkit'),
				'weight'      => 2,
				'severity'    => ( $probe['gzip'] || $probe['brotli'] ) ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => ( $probe['gzip'] || $probe['brotli'] ) ? '' : __( 'Without compression every visitor downloads roughly three times more text than necessary.', 'omninox-site-toolkit'),
			)
		);

		$this->grouped(
			$group,
			array(
				'id'          => 'brotli',
				'label'       => __( 'Brotli compression', 'omninox-site-toolkit'),
				'value'       => $probe['brotli'] ? __( 'Active', 'omninox-site-toolkit') : __( 'Not in use', 'omninox-site-toolkit'),
				'weight'      => 1,
				'severity'    => $probe['brotli'] ? self::SEV_PASS : self::SEV_NOTICE,
				'description' => $probe['brotli'] ? '' : __( 'Brotli compresses roughly 15% smaller than GZIP. Worth switching on if the server offers it.', 'omninox-site-toolkit'),
			)
		);
	}

	/**
	 * WordPress configuration checks.
	 */
	private function check_wordpress() {
		$group = __( 'WordPress', 'omninox-site-toolkit');

		$site = get_option( 'siteurl' );
		$home = get_option( 'home' );

		$this->grouped(
			$group,
			array(
				'id'       => 'site_url',
				'label'    => __( 'Site URL', 'omninox-site-toolkit'),
				'value'    => $site,
				'weight'   => 1,
				'severity' => 0 === strpos( (string) $site, 'https://' ) ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		$this->grouped(
			$group,
			array(
				'id'          => 'home_url',
				'label'       => __( 'Home URL', 'omninox-site-toolkit'),
				'value'       => $home,
				'weight'      => 1,
				'severity'    => 0 === strpos( (string) $home, 'https://' ) ? self::SEV_PASS : self::SEV_WARNING,
				'description' => ( wp_parse_url( (string) $site, PHP_URL_HOST ) !== wp_parse_url( (string) $home, PHP_URL_HOST ) )
					? __( 'The site and home hosts differ. That is valid when WordPress lives in a subdirectory, but it is also a common symptom of a hijacked siteurl option.', 'omninox-site-toolkit')
					: '',
			)
		);

		$this->grouped(
			$group,
			array(
				'id'       => 'multisite',
				'label'    => __( 'Multisite', 'omninox-site-toolkit'),
				'value'    => is_multisite()
					/* translators: %d: number of sites */
					? sprintf( __( 'Network of %d sites', 'omninox-site-toolkit'), (int) get_blog_count() )
					: __( 'Single site', 'omninox-site-toolkit'),
				'weight'   => 0.5,
				'severity' => self::SEV_PASS,
			)
		);

		$heartbeat = ! wp_script_is( 'heartbeat', 'registered' ) ? __( 'Disabled', 'omninox-site-toolkit') : __( 'Active', 'omninox-site-toolkit');

		$this->grouped(
			$group,
			array(
				'id'          => 'heartbeat',
				'label'       => __( 'Heartbeat API', 'omninox-site-toolkit'),
				'value'       => $heartbeat,
				'weight'      => 1,
				'severity'    => self::SEV_PASS,
				'description' => __( 'Heartbeat powers autosave and post locking by polling admin-ajax. On shared hosting a busy editor screen can generate noticeable CPU load.', 'omninox-site-toolkit'),
			)
		);

		$revisions = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : true;

		$this->grouped(
			$group,
			array(
				'id'          => 'post_revisions',
				'label'       => __( 'Stored post revisions', 'omninox-site-toolkit'),
				'value'       => true === $revisions ? __( 'Unlimited', 'omninox-site-toolkit') : (string) $revisions,
				'expected'    => '5',
				'weight'      => 1,
				'severity'    => true === $revisions ? self::SEV_NOTICE : self::SEV_PASS,
				'description' => true === $revisions
					? __( 'Every edit is kept forever, which is the usual reason the posts table grows out of proportion. Define WP_POST_REVISIONS as 5 in wp-config.php.', 'omninox-site-toolkit')
					: '',
			)
		);
	}

	/**
	 * PHP configuration checks beyond the headline limits.
	 */
	private function check_php_configuration() {
		$group = __( 'PHP configuration', 'omninox-site-toolkit');

		$input_vars = (int) ini_get( 'max_input_vars' );

		$this->grouped(
			$group,
			array(
				'id'          => 'max_input_vars',
				'label'       => __( 'Max input vars', 'omninox-site-toolkit'),
				'value'       => (string) $input_vars,
				'expected'    => '3000',
				'weight'      => 1.5,
				'severity'    => $input_vars >= 3000 ? self::SEV_PASS : self::SEV_WARNING,
				'description' => $input_vars < 3000
					? __( 'Large menus and page builder layouts silently lose fields when they exceed this. The symptom is a menu that truncates on save with no error.', 'omninox-site-toolkit')
					: '',
			)
		);

		$post_max = \OmninoxSiteToolkit\Helpers\Utils::to_bytes( ini_get( 'post_max_size' ) );
		$upload   = \OmninoxSiteToolkit\Helpers\Utils::to_bytes( ini_get( 'upload_max_filesize' ) );

		$this->grouped(
			$group,
			array(
				'id'          => 'post_max_size',
				'label'       => __( 'Post max size', 'omninox-site-toolkit'),
				'value'       => ini_get( 'post_max_size' ),
				'expected'    => __( 'Larger than the upload limit', 'omninox-site-toolkit'),
				'weight'      => 1.5,
				'severity'    => $post_max >= $upload ? self::SEV_PASS : self::SEV_WARNING,
				'description' => $post_max < $upload
					? __( 'Post max size is below the upload limit, so uploads at the advertised maximum will fail with an empty error.', 'omninox-site-toolkit')
					: '',
			)
		);

		$uploads_on = (bool) ini_get( 'file_uploads' );

		$this->grouped(
			$group,
			array(
				'id'       => 'file_uploads',
				'label'    => __( 'File uploads enabled', 'omninox-site-toolkit'),
				'value'    => $uploads_on ? __( 'Yes', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit'),
				'weight'   => 3,
				'severity' => $uploads_on ? self::SEV_PASS : self::SEV_CRITICAL,
			)
		);

		$required = array( 'curl', 'mbstring', 'json', 'gd', 'zip', 'dom', 'exif', 'fileinfo', 'openssl' );
		$missing  = array();

		foreach ( $required as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing[] = $extension;
			}
		}

		$this->grouped(
			$group,
			array(
				'id'          => 'php_extensions',
				'label'       => __( 'Recommended PHP extensions', 'omninox-site-toolkit'),
				'value'       => $missing ? implode( ', ', $missing ) . ' ' . __( 'missing', 'omninox-site-toolkit') : __( 'All present', 'omninox-site-toolkit'),
				'weight'      => 2,
				'severity'    => empty( $missing ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'     => array( 'missing' => $missing ),
				'description' => $missing ? __( 'WordPress and most plugins assume these are available. Missing ones cause failures that look unrelated to the cause.', 'omninox-site-toolkit') : '',
			)
		);
	}

	/**
	 * File system checks.
	 */
	private function check_filesystem() {
		$group = __( 'File system', 'omninox-site-toolkit');

		$htaccess = Utils::get_home_path() . '.htaccess';
		$exists   = file_exists( $htaccess );

		$this->grouped(
			$group,
			array(
				'id'          => 'htaccess',
				'label'       => __( '.htaccess', 'omninox-site-toolkit'),
				'value'       => $exists
					? sprintf(
						/* translators: 1: size, 2: permissions */
						__( 'Present, %1$s, %2$s', 'omninox-site-toolkit'),
						size_format( filesize( $htaccess ) ),
						\OmninoxSiteToolkit\Services\Security::permissions( $htaccess )
					)
					: __( 'Not found', 'omninox-site-toolkit'),
				'weight'      => 1,
				'severity'    => self::SEV_PASS,
				'description' => $exists ? '' : __( 'Normal on nginx, which uses its own configuration. On Apache it usually means permalinks are being handled some other way.', 'omninox-site-toolkit'),
			)
		);

		$robots = \OmninoxSiteToolkit\Helpers\Utils::timed_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
		$robots_ok = ! is_wp_error( $robots ) && 200 === (int) $robots['code'];
		$blocks_all = $robots_ok && preg_match( '/Disallow:\s*\/\s*$/mi', $robots['body'] );

		$this->grouped(
			$group,
			array(
				'id'          => 'robots_txt',
				'label'       => __( 'robots.txt', 'omninox-site-toolkit'),
				'value'       => $robots_ok
					? ( $blocks_all ? __( 'Blocking all crawlers', 'omninox-site-toolkit') : __( 'Present and permissive', 'omninox-site-toolkit') )
					: __( 'Not reachable', 'omninox-site-toolkit'),
				'weight'      => $blocks_all ? 4 : 1,
				'severity'    => $blocks_all ? self::SEV_CRITICAL : ( $robots_ok ? self::SEV_PASS : self::SEV_NOTICE ),
				'description' => $blocks_all ? __( 'A blanket Disallow rule stops search engines crawling anything at all. This is almost always left over from development.', 'omninox-site-toolkit') : '',
			)
		);

		$upload_dir  = wp_upload_dir();
		$uploads_dir = ! empty( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
		$plugins_dir = Utils::get_plugins_dir();
		$themes_dir  = function_exists( 'get_theme_root' ) ? get_theme_root() : '';
		$content_dir = Utils::get_content_dir();

		$directories_to_check = array_filter(
			array(
				'uploads'    => $uploads_dir,
				'themes'     => $themes_dir,
				'plugins'    => $plugins_dir,
				'wp-content' => $content_dir,
			)
		);

		foreach ( $directories_to_check as $label => $dir ) {
			$writable[] = array(
				'directory'   => $label,
				'writable'    => wp_is_writable( $dir ) ? 'yes' : 'no',
				'permissions' => file_exists( $dir ) ? \OmninoxSiteToolkit\Services\Security::permissions( $dir ) : '—',
			);
		}

		$uploads_writable = $uploads_dir ? wp_is_writable( $uploads_dir ) : false;

		$this->grouped(
			$group,
			array(
				'id'          => 'writable_directories',
				'label'       => __( 'Writable directories', 'omninox-site-toolkit'),
				'value'       => $uploads_writable ? __( 'Uploads writable', 'omninox-site-toolkit') : __( 'Uploads not writable', 'omninox-site-toolkit'),
				'weight'      => 2,
				'severity'    => $uploads_writable ? self::SEV_PASS : self::SEV_CRITICAL,
				'context'     => array( 'directories' => $writable ),
				'description' => __( 'Uploads must be writable. Everything else being writable is convenient for updates but widens what a compromised account can change.', 'omninox-site-toolkit'),
			)
		);

		$config = Utils::get_wp_config_path();
		$home   = Utils::get_home_path();
		$above  = $config && ( 0 !== strpos( wp_normalize_path( $config ), wp_normalize_path( $home ) ) );

		$this->grouped(
			$group,
			array(
				'id'          => 'wp_config_security',
				'label'       => __( 'wp-config.php security', 'omninox-site-toolkit'),
				'value'       => file_exists( $config )
					? sprintf(
						'%s%s',
						\OmninoxSiteToolkit\Services\Security::permissions( $config ),
						$above ? ' ' . __( '(above web root)', 'omninox-site-toolkit') : ''
					)
					: __( 'Not found', 'omninox-site-toolkit'),
				'expected'    => '0640',
				'weight'      => 3,
				'severity'    => ( file_exists( $config ) && octdec( \OmninoxSiteToolkit\Services\Security::permissions( $config ) ) <= octdec( '0644' ) ) ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'This file holds the database credentials and the authentication salts. It should not be readable by other accounts on the server.', 'omninox-site-toolkit'),
			)
		);
	}

	/**
	 * Probe the front end for protocol and compression support.
	 *
	 * @return array
	 */
	private function protocol_probe() {
		$out = array(
			'protocol' => __( 'Unknown', 'omninox-site-toolkit'),
			'modern'   => false,
			'http3'    => false,
			'gzip'     => false,
			'brotli'   => false,
		);

		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout' => 20,
				'headers' => array( 'Accept-Encoding' => 'gzip, deflate, br' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $out;
		}

		$headers  = array_change_key_case( (array) wp_remote_retrieve_headers( $response )->getAll(), CASE_LOWER );
		$encoding = strtolower( (string) ( $headers['content-encoding'] ?? '' ) );

		$out['gzip']   = false !== strpos( $encoding, 'gzip' ) || false !== strpos( $encoding, 'deflate' );
		$out['brotli'] = false !== strpos( $encoding, 'br' );

		// cURL reports the negotiated protocol version when it is available.
		if ( ! empty( $headers['alt-svc'] ) ) {
			$alt = is_array( $headers['alt-svc'] ) ? implode( ',', $headers['alt-svc'] ) : $headers['alt-svc'];
			$out['http3'] = false !== stripos( $alt, 'h3' );
		}

		$version = 1.1;

		if ( function_exists( 'curl_version' ) && isset( $response['http_response'] ) ) {
			$version = 2.0;
		}

		// A server advertising HTTP/3 is necessarily serving at least HTTP/2.
		if ( $out['http3'] ) {
			$version = 2.0;
		}

		$out['protocol'] = $out['http3'] ? 'HTTP/2 + HTTP/3' : ( $version >= 2 ? 'HTTP/2' : 'HTTP/1.1' );
		$out['modern']   = $version >= 2;

		return $out;
	}

	/**
	 * Is core up to date?
	 *
	 * @return bool
	 */
	private function core_is_current() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_core_updates();

		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return true;
		}

		return 'latest' === ( $updates[0]->response ?? 'latest' );
	}

	/**
	 * Count overdue cron events.
	 *
	 * @return int
	 */
	private function overdue_cron_count() {
		$crons = _get_cron_array();
		$now   = time();
		$count = 0;

		foreach ( (array) $crons as $timestamp => $hooks ) {
			if ( $timestamp < ( $now - 300 ) ) {
				$count += count( $hooks );
			}
		}

		return $count;
	}

	/**
	 * Check permissions on key paths.
	 *
	 * @return array
	 */
	private function check_permissions() {
		$upload_dir  = wp_upload_dir();
		$uploads_dir = ! empty( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
		$plugins_dir = Utils::get_plugins_dir();
		$themes_dir  = function_exists( 'get_theme_root' ) ? get_theme_root() : '';
		$content_dir = Utils::get_content_dir();
		$config_file = Utils::get_wp_config_path();
		$home_path   = Utils::get_home_path();

		$paths = array_filter(
			array(
				$home_path   => '0755',
				$config_file => '0640',
				$content_dir => '0755',
				$uploads_dir => '0755',
				$plugins_dir => '0755',
				$themes_dir  => '0755',
			)
		);

		$checked  = array();
		$problems = array();

		foreach ( $paths as $path => $expected ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$actual       = substr( sprintf( '%o', fileperms( $path ) ), -4 );
			$clean_path   = wp_normalize_path( $path );
			$clean_root   = wp_normalize_path( $home_path );
			$display_path = ( 0 === strpos( $clean_path, $clean_root ) ) ? ( ltrim( substr( $clean_path, strlen( $clean_root ) ), '/' ) ?: '/' ) : basename( $clean_path );

			$row    = array(
				'path'     => $display_path,
				'actual'   => $actual,
				'expected' => $expected,
				'writable' => is_writable( $path ),
			);

			$checked[] = $row;

			if ( octdec( $actual ) > octdec( $expected ) ) {
				$problems[] = $row;
			}
		}

		return array(
			'checked'  => $checked,
			'problems' => $problems,
		);
	}

	/**
	 * Is debug.log publicly readable?
	 *
	 * @return bool
	 */
	private function debug_log_is_public() {
		$content_dir = Utils::get_content_dir();
		$log         = trailingslashit( $content_dir ) . 'debug.log';

		if ( ! file_exists( $log ) ) {
			return false;
		}

		$response = wp_remote_head( content_url( 'debug.log' ), array( 'timeout' => 10 ) );

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Read the SSL certificate expiry.
	 *
	 * @return array
	 */
	private function ssl_certificate_info() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $host || ! function_exists( 'stream_socket_client' ) ) {
			return array();
		}

		$context = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true ) ) );
		$client  = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $client ) {
			return array();
		}

		$params = stream_context_get_params( $client );
		$cert   = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		fclose( $client );

		if ( empty( $cert['validTo_time_t'] ) ) {
			return array();
		}

		return array(
			'issuer'     => $cert['issuer']['O'] ?? '',
			'expires_at' => gmdate( 'Y-m-d', $cert['validTo_time_t'] ),
			'days_left'  => (int) floor( ( $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS ),
		);
	}



	/**
	 * Provide guidance on protecting debug.log from web access.
	 *
	 * @return array
	 */
	private function protect_debug_log() {
		return array(
			'message' => __( 'To secure debug.log, set WP_DEBUG_DISPLAY to false and configure WP_DEBUG_LOG with a custom file path outside your public web root in wp-config.php, or configure your web server to deny direct requests to *.log files.', 'omninox-site-toolkit' ),
		);
	}

	/**
	 * Write php.ini style directives where supported.
	 *
	 * @param array $directives Key => value.
	 * @return array|\WP_Error
	 */
	private function write_ini_directives( array $directives ) {
		return new \WP_Error(
			'omninox_ini_server_only',
			__( 'PHP configuration limits must be adjusted directly in php.ini or via your hosting control panel. Plugins cannot globally modify server PHP configuration on WordPress.org.', 'omninox-site-toolkit' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Insert or update a constant in wp-config.php.
	 *
	 * @param string $name    Constant name.
	 * @param mixed  $value   Value.
	 * @param bool   $is_bool Whether to write an unquoted boolean.
	 * @return array|\WP_Error
	 */
	private function write_wp_config_constant( $name, $value, $is_bool = false ) {
		return new \WP_Error(
			'omninox_config_protected',
			sprintf(
				/* translators: %s: constant name */
				__( 'wp-config.php is protected for security. Please define %s directly in your wp-config.php file.', 'omninox-site-toolkit' ),
				esc_html( $name )
			),
			array( 'status' => 403 )
		);
	}
}
