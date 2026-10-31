<?php

/**
 * Module 9: Security Center.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Security;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;

defined('ABSPATH') || exit;

class SecurityModule extends AbstractModule
{

	protected $id   = 'security';
	protected $icon = 'shield';

	public function get_label()
	{
		return __('Security', 'omninox-site-toolkit');
	}

	/**
	 * Track failed logins and apply any hardening flags.
	 */
	public function register()
	{
		add_action('wp_login_failed', array($this, 'record_failed_login'));
		add_action('wp_login', array($this, 'record_last_login'), 10, 2);

		$hardening = (array) \OmninoxSiteToolkit\Core\Settings::instance()->get('hardening', array());

		if (! empty($hardening['disable_xmlrpc'])) {
			add_filter('xmlrpc_enabled', '__return_false');
			add_filter('wp_headers', array($this, 'remove_pingback_header'));
		}

		// Pro-only hooks are attached only when the licence permits them, so a
		// stale settings flag can never activate a Pro protection on free.
		$can = static function ($feature) {
			return \OmninoxSiteToolkit\License\LicenseManager::can_access('security', $feature);
		};

		if (! empty($hardening['block_user_enumeration']) && $can('block_user_enumeration')) {
			add_filter('rest_endpoints', array($this, 'block_user_endpoints'));
			add_action('template_redirect', array($this, 'block_author_scan'));
		}

		if (! empty($hardening['disable_file_editor'])) {
			add_filter('map_meta_cap', array($this, 'disable_file_editor_map_meta_cap'), 10, 4);
			add_filter('user_has_cap', array($this, 'disable_file_editor_user_has_cap'), 10, 4);
		}

		if (! empty($hardening['hide_wp_version'])) {
			remove_action('wp_head', 'wp_generator');
			add_filter('the_generator', '__return_empty_string');
		}

		if (! empty($hardening['limit_logins']) && $can('login_limit')) {
			add_filter('authenticate', array($this, 'block_locked_out'), 30, 3);
			add_action('wp_login_failed', array($this, 'count_failure'), 20);
			add_action('wp_login', array($this, 'clear_failures'), 10, 2);
		}

		if (! empty($hardening['block_ips']) && $can('block_ip')) {
			add_action('init', array($this, 'enforce_ip_blocks'), 1);
		}

		if (! empty($hardening['two_factor']) && $can('two_factor')) {
			add_filter('authenticate', array($this, 'two_factor_challenge'), 40, 3);
			add_action('login_form', array($this, 'two_factor_field'));
		}

		if (! empty($hardening['honeypot']) && $can('honeypot')) {
			add_action('login_form', array($this, 'honeypot_field'));
			add_filter('authenticate', array($this, 'check_honeypot'), 20, 3);
		}
	}

	/* ------------------------------------------------------------ Login guard */

	/**
	 * The visitor's IP.
	 *
	 * REMOTE_ADDR is the only value the client cannot forge. Proxy
	 * headers (Cloudflare, X-Real-IP, X-Forwarded-For) are consulted
	 * ONLY when the site owner has said they are behind such a proxy —
	 * via the omninox_trusted_proxy setting or the OMNINOX_TRUSTED_PROXY
	 * constant. Trusting them unconditionally lets any attacker evade
	 * IP blocks and login lockouts by sending a made-up header.
	 *
	 * @return string
	 */
	private function client_ip()
	{
		$remote = isset($_SERVER['REMOTE_ADDR'])
			? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		$remote = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

		$trust_proxy = defined('OMNINOX_TRUSTED_PROXY')
			? (bool) OMNINOX_TRUSTED_PROXY
			: (bool) \OmninoxSiteToolkit\Core\Settings::instance()->get('trusted_proxy', false);

		if (! $trust_proxy) {
			return $remote;
		}

		foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR') as $key) {
			if (empty($_SERVER[$key])) {
				continue;
			}

			$value = sanitize_text_field(wp_unslash($_SERVER[$key])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			// X-Forwarded-For may be a chain; the left-most entry is
			// the original client as reported by the trusted proxy.
			$first = trim(explode(',', $value)[0]);

			if (filter_var($first, FILTER_VALIDATE_IP)) {
				return $first;
			}
		}

		return $remote;
	}

	/**
	 * Refuse a login while the address is locked out.
	 *
	 * @param mixed  $user     User or error.
	 * @param string $username Username.
	 * @param string $password Password.
	 * @return mixed
	 */
	public function block_locked_out($user, $username = '', $password = '')
	{
		if ('' === $username) {
			return $user;
		}

		$lock = get_transient('omninox_lock_' . md5($this->client_ip()));

		if ($lock) {
			return new \WP_Error(
				'omninox_locked_out',
				sprintf(
					/* translators: %s: relative time */
					__('Too many failed attempts from this address. Try again in %s.', 'omninox-site-toolkit'),
					human_time_diff(time(), (int) $lock)
				)
			);
		}

		return $user;
	}

	/**
	 * Count a failure and lock out after the threshold.
	 *
	 * @param string $username Attempted username.
	 */
	public function count_failure($username)
	{
		$settings  = \OmninoxSiteToolkit\Core\Settings::instance();
		$threshold = max(3, (int) $settings->get('login_attempts', 5));
		$minutes   = max(5, (int) $settings->get('lockout_minutes', 20));

		$key   = 'omninox_fails_' . md5($this->client_ip());
		$count = (int) get_transient($key) + 1;

		set_transient($key, $count, HOUR_IN_SECONDS);

		if ($count >= $threshold) {
			set_transient('omninox_lock_' . md5($this->client_ip()), time() + ($minutes * MINUTE_IN_SECONDS), $minutes * MINUTE_IN_SECONDS);
			delete_transient($key);

			\OmninoxSiteToolkit\Services\Security::audit(
				'denied',
				'login/lockout',
				sprintf(
					/* translators: 1: ip address, 2: username */
					__('Locked out %1$s after repeated failures for "%2$s"', 'omninox-site-toolkit'),
					$this->client_ip(),
					sanitize_user($username)
				)
			);
		}
	}

	/**
	 * Clear the counter after a successful sign-in.
	 *
	 * @param string    $login User login.
	 * @param \WP_User $user  User.
	 */
	public function clear_failures($login, $user = null)
	{
		delete_transient('omninox_fails_' . md5($this->client_ip()));
	}

	/**
	 * Stop blocked addresses before anything else runs.
	 */
	public function enforce_ip_blocks()
	{
		$blocked = (array) get_option('omninox_blocked_ips', array());

		if (empty($blocked)) {
			return;
		}

		$ip = $this->client_ip();

		foreach ($blocked as $entry) {
			$pattern = is_array($entry) ? ($entry['ip'] ?? '') : $entry;

			if ('' === $pattern) {
				continue;
			}

			// Exact match, or a trailing wildcard such as 203.0.113.*
			$matched = (false !== strpos($pattern, '*'))
				? (bool) preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/', $ip)
				: ($pattern === $ip);

			if ($matched) {
				status_header(403);
				nocache_headers();
				wp_die(
					esc_html__('Access from this address has been blocked.', 'omninox-site-toolkit'),
					esc_html__('Blocked', 'omninox-site-toolkit'),
					array('response' => 403)
				);
			}
		}
	}

	/**
	 * Email a one-time code and require it on the login form.
	 *
	 * @param mixed  $user     User or error.
	 * @param string $username Username.
	 * @param string $password Password.
	 * @return mixed
	 */
	public function two_factor_challenge($user, $username = '', $password = '')
	{
		if (! $user instanceof \WP_User || ! user_can($user, 'omninox_manage')) {
			return $user;
		}

		$key      = 'omninox_2fa_' . $user->ID;
		$supplied = isset($_POST['omninox_2fa_code']) ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['omninox_2fa_code'])) : ''; // phpcs:ignore WordPress.Security
		$expected = get_transient($key);

		if ($expected && $supplied && hash_equals((string) $expected, (string) $supplied)) {
			delete_transient($key);

			return $user;
		}

		if (! $expected) {
			$code = (string) wp_rand(100000, 999999);
			set_transient($key, $code, 10 * MINUTE_IN_SECONDS);

			wp_mail(
				$user->user_email,
				__('Your sign-in code', 'omninox-site-toolkit'),
				sprintf(
					/* translators: 1: code, 2: site name */
					__("Your code for %2\$s is: %1\$s

It expires in ten minutes. If you did not try to sign in, change your password.", 'omninox-site-toolkit'),
					$code,
					get_bloginfo('name')
				)
			);
		}

		return new \WP_Error(
			'omninox_2fa_required',
			$supplied
				? __('That code was not correct. Check the most recent email and try again.', 'omninox-site-toolkit')
				: __('A sign-in code has been emailed to you. Enter it below to continue.', 'omninox-site-toolkit')
		);
	}

	/**
	 * Render the code field on the login form.
	 */
	public function two_factor_field()
	{
?>
		<p>
			<label for="omninox_2fa_code"><?php esc_html_e('Emailed sign-in code', 'omninox-site-toolkit'); ?><br>
				<input type="text" name="omninox_2fa_code" id="omninox_2fa_code" class="input" value="" size="20" autocomplete="one-time-code" inputmode="numeric">
			</label>
			<span class="description"><?php esc_html_e('Leave blank on the first attempt; a code will be sent.', 'omninox-site-toolkit'); ?></span>
		</p>
<?php
	}

	/**
	 * A field real people never fill in.
	 */
	public function honeypot_field()
	{
		echo '<p style="position:absolute;left:-9999px;" aria-hidden="true"><label>' . esc_html__('Leave this field empty', 'omninox-site-toolkit') . '<input type="text" name="omninox_hp" value="" tabindex="-1" autocomplete="off"></label></p>';
	}

	/**
	 * Reject a submission that filled the honeypot.
	 *
	 * @param mixed  $user     User or error.
	 * @param string $username Username.
	 * @param string $password Password.
	 * @return mixed
	 */
	public function check_honeypot($user, $username = '', $password = '')
	{
		if (! empty($_POST['omninox_hp'])) { // phpcs:ignore WordPress.Security
			\OmninoxSiteToolkit\Services\Security::audit('denied', 'login/honeypot', $this->client_ip());

			return new \WP_Error('omninox_bot', __('Sign-in refused.', 'omninox-site-toolkit'));
		}

		return $user;
	}

	/**
	 * Remember when each user last signed in.
	 *
	 * @param string   $login User login.
	 * @param \WP_User $user  User object.
	 */
	public function record_last_login($login, $user = null)
	{
		if ($user instanceof \WP_User) {
			update_user_meta($user->ID, 'omninox_last_login', time());
		}
	}

	/**
	 * Drop the pingback header.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	public function remove_pingback_header($headers)
	{
		unset($headers['X-Pingback']);

		return $headers;
	}

	/**
	 * Require authentication for the users endpoints.
	 *
	 * @param array $endpoints REST endpoints.
	 * @return array
	 */
	public function block_user_endpoints($endpoints)
	{
		if (is_user_logged_in()) {
			return $endpoints;
		}

		unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);

		return $endpoints;
	}

	/**
	 * Stop ?author=1 enumeration.
	 */
	public function block_author_scan()
	{
		if (is_admin() || is_user_logged_in()) {
			return;
		}

		if (isset($_GET['author']) && ! is_author()) {
			$author = sanitize_text_field(wp_unslash($_GET['author']));
			if ('' !== $author) {
				wp_safe_redirect(home_url('/'), 301);
				exit;
			}
		}
	}

	/**
	 * Store a failed login attempt.
	 *
	 * @param string $username Attempted username.
	 */
	public function record_failed_login($username)
	{
		\OmninoxSiteToolkit\Database\LogRepository::record(
			array(
				'type'    => 'login',
				'level'   => 'warning',
				'message' => sprintf( /* translators: %s: username */__('Failed login for "%s"', 'omninox-site-toolkit'), sanitize_user($username)),
				'source'  => 'auth',
			)
		);
	}

	/**
	 * Login protection controls.
	 *
	 * @return array
	 */
	public function get_actions()
	{
		$settings  = \OmninoxSiteToolkit\Core\Settings::instance();
		$hardening = (array) $settings->get('hardening', array());

		$toggles = array(
			'limit_logins'           => array(__('Limit login attempts', 'omninox-site-toolkit'), __('Locks an address out after repeated failures. The single most effective control against password guessing.', 'omninox-site-toolkit')),
			'honeypot'               => array(__('Bot honeypot on the login form', 'omninox-site-toolkit'), __('Adds a hidden field that only automated scripts fill in. Invisible to real visitors and to screen readers.', 'omninox-site-toolkit')),
			'two_factor'             => array(__('Email a sign-in code to administrators', 'omninox-site-toolkit'), __('Test this in a private window before relying on it. If mail delivery is broken on this site, you will lock yourself out.', 'omninox-site-toolkit')),
			'block_ips'             => array(__('Enforce the IP block list', 'omninox-site-toolkit'), __('Blocked addresses are refused before WordPress loads.', 'omninox-site-toolkit')),
			'disable_xmlrpc'         => array(__('Disable XML-RPC', 'omninox-site-toolkit'), __('Leave on unless the WordPress mobile app or Jetpack needs it.', 'omninox-site-toolkit')),
			'block_user_enumeration' => array(__('Block username enumeration', 'omninox-site-toolkit'), __('Requires authentication for the REST users endpoint and stops ?author=1 scanning.', 'omninox-site-toolkit')),
			'disable_file_editor'    => array(__('Disable the theme and plugin editor', 'omninox-site-toolkit'), __('Stops a compromised administrator account from running arbitrary PHP.', 'omninox-site-toolkit')),
			'hide_wp_version'        => array(__('Hide the WordPress version', 'omninox-site-toolkit'), __('Marginal on its own, since the version is inferable other ways.', 'omninox-site-toolkit')),
		);

		$actions = array();

		foreach ($toggles as $id => $meta) {
			$actions[] = array(
				'id'      => $id,
				'label'   => $meta[0],
				'type'    => 'toggle',
				'current' => ! empty($hardening[$id]) ? 1 : 0,
				'notice'  => $meta[1],
			);
		}

		$actions[] = array(
			'id'      => 'set_login_limits',
			'label'   => __('Attempts before lockout', 'omninox-site-toolkit'),
			'type'    => 'select',
			'current' => (string) $settings->get('login_attempts', 5),
			'options' => array(
				array('value' => 3, 'label' => '3'),
				array('value' => 5, 'label' => '5'),
				array('value' => 10, 'label' => '10'),
			),
		);

		$actions[] = array(
			'id'      => 'trusted_proxy',
			'label'   => __('Site is behind a proxy or CDN (Cloudflare, load balancer)', 'omninox-site-toolkit'),
			'type'    => 'toggle',
			'current' => $settings->get('trusted_proxy', false) ? 1 : 0,
			'notice'  => __('Only enable this when traffic genuinely arrives through a proxy. It makes the plugin read the visitor IP from the proxy headers; enabled on a site NOT behind a proxy, those headers can be forged and IP blocking becomes ineffective.', 'omninox-site-toolkit'),
		);

		$actions[] = array(
			'id'      => 'set_lockout_minutes',
			'label'   => __('Lockout length', 'omninox-site-toolkit'),
			'type'    => 'select',
			'current' => (string) $settings->get('lockout_minutes', 20),
			'options' => array(
				array('value' => 15, 'label' => __('15 minutes', 'omninox-site-toolkit')),
				array('value' => 20, 'label' => __('20 minutes', 'omninox-site-toolkit')),
				array('value' => 60, 'label' => __('1 hour', 'omninox-site-toolkit')),
				array('value' => 1440, 'label' => __('24 hours', 'omninox-site-toolkit')),
			),
		);

		$actions[] = array(
			'id'     => 'block_ip',
			'label'  => __('Block an IP address', 'omninox-site-toolkit'),
			'type'   => 'form',
			'submit' => __('Block', 'omninox-site-toolkit'),
			'notice' => __('A trailing wildcard is allowed, such as 203.0.113.*. Take care not to block your own address.', 'omninox-site-toolkit'),
			'fields' => array(
				array('id' => 'ip', 'label' => __('IP address or pattern', 'omninox-site-toolkit'), 'type' => 'text', 'placeholder' => '203.0.113.42'),
				array('id' => 'note', 'label' => __('Note', 'omninox-site-toolkit'), 'type' => 'text'),
			),
		);

		$actions[] = array(
			'id'     => 'unblock_ip',
			'label'  => __('Unblock an IP address', 'omninox-site-toolkit'),
			'type'   => 'form',
			'submit' => __('Unblock', 'omninox-site-toolkit'),
			'fields' => array(array('id' => 'ip', 'label' => __('IP address', 'omninox-site-toolkit'), 'type' => 'text')),
		);

		$actions[] = array('id' => 'list_blocks', 'label' => __('Blocked addresses', 'omninox-site-toolkit'), 'type' => 'inspector');
		$actions[] = array('id' => 'login_log', 'label' => __('Login attempt log', 'omninox-site-toolkit'), 'type' => 'inspector');
		$actions[] = array('id' => 'clear_lockouts', 'label' => __('Clear all current lockouts', 'omninox-site-toolkit'), 'type' => 'button');

		// Phase 58: Website Compromise & Tampering Audit Actions.
		$actions[] = array(
			'id'     => 'run_compromise_audit',
			'label'  => __('Run deep compromise & tampering audit', 'omninox-site-toolkit'),
			'type'   => 'button',
			'notice' => __('Performs a defensive audit of core checksums, plugins, themes, uploads, persistence drop-ins, database spam, and server configuration.', 'omninox-site-toolkit'),
		);

		$actions[] = array(
			'id'    => 'view_compromise_report',
			'label' => __('Compromise audit report', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		$actions[] = array(
			'id'     => 'create_integrity_baseline',
			'label'  => __('Snapshot file integrity baseline', 'omninox-site-toolkit'),
			'type'   => 'button',
			'notice' => __('Generates cryptographic SHA-256 hashes of critical files to detect unauthorized drift or modifications.', 'omninox-site-toolkit'),
		);

		$actions[] = array(
			'id'    => 'verify_integrity_baseline',
			'label' => __('Check baseline drift', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		$actions[] = array(
			'id'     => 'quarantine_file',
			'label'  => __('Quarantine suspicious file', 'omninox-site-toolkit'),
			'type'   => 'form',
			'submit' => __('Quarantine', 'omninox-site-toolkit'),
			'notice' => __('Moves file to a non-executable, access-blocked quarantine directory with rollback capability.', 'omninox-site-toolkit'),
			'fields' => array(
				array('id' => 'path', 'label' => __('Relative file path (e.g. wp-content/uploads/suspicious.php)', 'omninox-site-toolkit'), 'type' => 'text'),
				array('id' => 'reason', 'label' => __('Reason for quarantine', 'omninox-site-toolkit'), 'type' => 'text'),
			),
		);

		$actions[] = array(
			'id'     => 'restore_quarantined_file',
			'label'  => __('Restore quarantined file (Rollback)', 'omninox-site-toolkit'),
			'type'   => 'form',
			'submit' => __('Restore', 'omninox-site-toolkit'),
			'fields' => array(
				array('id' => 'quarantine_id', 'label' => __('Quarantine ID', 'omninox-site-toolkit'), 'type' => 'text'),
			),
		);

		$actions[] = array(
			'id'    => 'list_quarantine',
			'label' => __('Quarantine storage', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		$actions[] = array(
			'id'     => 'run_compromise_test_suite',
			'label'  => __('Run 16-scenario threat & false positive test suite', 'omninox-site-toolkit'),
			'type'   => 'button',
			'notice' => __('Executes safe, non-destructive test fixtures to verify scanner detection accuracy and false positive controls.', 'omninox-site-toolkit'),
		);

		$actions[] = array(
			'id'    => 'view_prevention_report',
			'label' => __('Report A: Prevention & hardening report', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		$actions[] = array(
			'id'    => 'view_detection_report',
			'label' => __('Report B: Detection & indicators report', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		$actions[] = array(
			'id'    => 'view_remediation_report',
			'label' => __('Report C: Remediation & quarantine report', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		$actions[] = array(
			'id'    => 'view_recovery_report',
			'label' => __('Report D: Recovery & rotation report', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		$actions[] = array(
			'id'     => 'run_self_tamper_check',
			'label'  => __('Check security module self-integrity', 'omninox-site-toolkit'),
			'type'   => 'button',
			'notice' => __('Verifies SHA-256 cryptographic hashes of Omninox security components to detect attacker tampering.', 'omninox-site-toolkit'),
		);

		$actions[] = array(
			'id'     => 'run_self_safety_tests',
			'label'  => __('Run self-safety edge-case tests', 'omninox-site-toolkit'),
			'type'   => 'button',
			'notice' => __('Validates that the scanner fails safely against traversal attacks, core protection, and lock concurrency.', 'omninox-site-toolkit'),
		);

		$actions[] = array(
			'id'    => 'evaluate_release_gate',
			'label' => __('Final Release Gate 27-point evaluator', 'omninox-site-toolkit'),
			'type'  => 'inspector',
		);

		return $actions;
	}

	/**
	 * Run the security scan.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan(array $args = array())
	{
		// Default admin username.
		$admin_user = get_user_by('login', 'admin');
		$this->add(
			array(
				'id'          => 'default_admin',
				'label'       => __('Default "admin" username', 'omninox-site-toolkit'),
				'value'       => $admin_user ? __('In use', 'omninox-site-toolkit') : __('Not in use', 'omninox-site-toolkit'),
				'weight'      => 2,
				'severity'    => $admin_user ? self::SEV_WARNING : self::SEV_PASS,
				'description' => $admin_user ? __('Automated attacks try "admin" first. Create a new administrator with a different name and remove this one.', 'omninox-site-toolkit') : '',
			)
		);

		// Users with weak or reused credentials indicators.
		$weak = $this->weak_password_candidates();
		$this->add(
			array(
				'id'       => 'weak_passwords',
				'label'    => __('Accounts at risk of weak passwords', 'omninox-site-toolkit'),
				'value'    => (string) count($weak),
				'weight'   => 2.5,
				'severity' => empty($weak) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array('users' => $weak),
				'description' => __('Passwords are hashed, so they cannot be read. These accounts are flagged because they are administrators that have never changed their password or have no recent activity.', 'omninox-site-toolkit'),
				'fix'      => empty($weak) ? '' : 'force_password_reset',
			)
		);

		// Too many administrators.
		$admins = get_users(array('role' => 'administrator', 'fields' => array('ID', 'user_login', 'user_email')));
		$this->add(
			array(
				'id'       => 'admin_count',
				'label'    => __('Administrator accounts', 'omninox-site-toolkit'),
				'value'    => (string) count($admins),
				'expected' => '< 4',
				'weight'   => 1.5,
				'severity' => count($admins) < 4 ? self::SEV_PASS : self::SEV_NOTICE,
				'context'  => array('users' => $admins),
			)
		);

		// XML-RPC.
		$xmlrpc = (bool) apply_filters('xmlrpc_enabled', true);
		$this->add(
			array(
				'id'       => 'xmlrpc_enabled',
				'label'    => __('XML-RPC endpoint', 'omninox-site-toolkit'),
				'value'    => $xmlrpc ? __('Open', 'omninox-site-toolkit') : __('Closed', 'omninox-site-toolkit'),
				'weight'   => 2,
				'severity' => $xmlrpc ? self::SEV_WARNING : self::SEV_PASS,
				'fix'      => $xmlrpc ? 'disable_xmlrpc' : '',
			)
		);

		// User enumeration through the REST API.
		$users_exposed = $this->rest_users_exposed();
		$this->add(
			array(
				'id'          => 'rest_user_enumeration',
				'label'       => __('User list exposed via REST API', 'omninox-site-toolkit'),
				'value'       => $users_exposed ? __('Yes', 'omninox-site-toolkit') : __('No', 'omninox-site-toolkit'),
				'weight'      => 2,
				'severity'    => $users_exposed ? self::SEV_WARNING : self::SEV_PASS,
				'fix'         => $users_exposed ? 'block_user_enumeration' : '',
				'description' => $users_exposed ? __('Anonymous visitors can list usernames, which turns a password guess into a targeted attack.', 'omninox-site-toolkit') : '',
			)
		);

		// Debug in production.
		$debug = defined('WP_DEBUG') && WP_DEBUG && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
		$this->add(
			array(
				'id'       => 'debug_display',
				'label'    => __('Errors displayed to visitors', 'omninox-site-toolkit'),
				'value'    => $debug ? __('Yes', 'omninox-site-toolkit') : __('No', 'omninox-site-toolkit'),
				'weight'   => 3,
				'severity' => $debug ? self::SEV_CRITICAL : self::SEV_PASS,
			)
		);

		// Core file integrity.
		$modified = $this->modified_core_files();
		$this->add(
			array(
				'id'       => 'core_integrity',
				'label'    => __('Modified core files', 'omninox-site-toolkit'),
				'value'    => is_wp_error($modified) ? __('Could not verify', 'omninox-site-toolkit') : (string) count($modified),
				'weight'   => 3,
				'severity' => (is_wp_error($modified) || empty($modified)) ? self::SEV_PASS : self::SEV_CRITICAL,
				'context'  => is_wp_error($modified) ? array() : array('files' => array_slice($modified, 0, 40)),
				'description' => __('Core files are compared against the official checksums published by WordPress.org.', 'omninox-site-toolkit'),
			)
		);

		// Suspicious files in uploads.
		$suspicious = $this->suspicious_files();
		$this->add(
			array(
				'id'          => 'suspicious_files',
				'label'       => __('Executable files inside uploads', 'omninox-site-toolkit'),
				'value'       => (string) count($suspicious),
				'weight'      => 3,
				'severity'    => empty($suspicious) ? self::SEV_PASS : self::SEV_CRITICAL,
				'context'     => array('files' => array_slice($suspicious, 0, 40)),
				'description' => __('PHP files in the uploads folder are a classic backdoor. Review each one before deleting.', 'omninox-site-toolkit'),
			)
		);

		// Malware-pattern heuristics.
		$patterns = $this->malware_patterns();
		$this->add(
			array(
				'id'          => 'malware_patterns',
				'label'       => __('Files matching malware patterns', 'omninox-site-toolkit'),
				'value'       => (string) count($patterns),
				'weight'      => 3,
				'severity'    => empty($patterns) ? self::SEV_PASS : self::SEV_WARNING,
				'context'     => array('files' => array_slice($patterns, 0, 30)),
				'description' => __('Heuristic matches on obfuscation techniques. Legitimate plugins occasionally trigger these, so confirm before acting.', 'omninox-site-toolkit'),
			)
		);

		// wp-config permissions.
		$config       = Utils::get_wp_config_path();
		$config_perms = ($config && file_exists($config)) ? substr(sprintf('%o', fileperms($config)), -4) : '';
		$this->add(
			array(
				'id'       => 'wp_config_permissions',
				'label'    => __('wp-config.php permissions', 'omninox-site-toolkit'),
				'value'    => $config_perms ?: __('Not found', 'omninox-site-toolkit'),
				'expected' => '0640',
				'weight'   => 2,
				'severity' => ($config_perms && octdec($config_perms) <= octdec('0644')) ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		// File editing.
		$is_file_edit_disabled = (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) || ! empty($hardening['disable_file_editor']);
		$this->add(
			array(
				'id'          => 'file_editor',
				'label'       => __('Theme and plugin file editor', 'omninox-site-toolkit'),
				'value'       => $is_file_edit_disabled ? __('Disabled', 'omninox-site-toolkit') : __('Enabled', 'omninox-site-toolkit'),
				'weight'      => 2,
				'severity'    => $is_file_edit_disabled ? self::SEV_PASS : self::SEV_WARNING,
				'fix'         => $is_file_edit_disabled ? '' : 'disable_file_editor',
				'description' => $is_file_edit_disabled ? '' : __('If an administrator account is compromised, the built-in editor lets the attacker run arbitrary PHP.', 'omninox-site-toolkit'),
			)
		);

		// Directory listing.
		$listing = $this->directory_listing_open();
		$this->add(
			array(
				'id'       => 'directory_listing',
				'label'    => __('Directory listing on uploads', 'omninox-site-toolkit'),
				'value'    => $listing ? __('Open', 'omninox-site-toolkit') : __('Blocked', 'omninox-site-toolkit'),
				'weight'   => 1.5,
				'severity' => $listing ? self::SEV_WARNING : self::SEV_PASS,
			)
		);

		// Vulnerable plugins and themes.
		$vulnerable = $this->vulnerability_check();
		$this->add(
			array(
				'id'       => 'vulnerable_extensions',
				'label'    => __('Plugins and themes with known advisories', 'omninox-site-toolkit'),
				'value'    => (string) count($vulnerable),
				'weight'   => 3,
				'severity' => empty($vulnerable) ? self::SEV_PASS : self::SEV_CRITICAL,
				'context'  => array('items' => $vulnerable),
			)
		);

		// Failed logins in the last 24h.
		$failed = $this->recent_failed_logins();
		$this->add(
			array(
				'id'       => 'failed_logins',
				'label'    => __('Failed logins in the last 24 hours', 'omninox-site-toolkit'),
				'value'    => (string) $failed,
				'expected' => '< 20',
				'weight'   => 1.5,
				'severity' => $failed < 20 ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		// Firewall presence.
		$firewall = $this->detected_firewall();
		$this->add(
			array(
				'id'       => 'firewall',
				'label'    => __('Firewall or WAF', 'omninox-site-toolkit'),
				'value'    => $firewall ?: __('Not detected', 'omninox-site-toolkit'),
				'weight'   => 1.5,
				'severity' => $firewall ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		// Phase 58: Website Compromise & Tampering Audit.
		$last_audit = get_option('omninox_last_compromise_audit');
		$compromise_status = is_array($last_audit) && ! empty($last_audit['status'])
			? $last_audit['status']
			: CompromiseAuditService::STATUS_CLEAN;

		$sev = CompromiseAuditService::STATUS_COMPROMISE === $compromise_status
			? self::SEV_CRITICAL
			: (CompromiseAuditService::STATUS_SUSPICIOUS === $compromise_status ? self::SEV_WARNING : self::SEV_PASS);

		$this->add(
			array(
				'id'          => 'compromise_indicators',
				'label'       => __('Website compromise & tampering audit', 'omninox-site-toolkit'),
				'value'       => $compromise_status,
				'weight'      => 3.5,
				'severity'    => $sev,
				'context'     => is_array($last_audit) ? array('summary' => $last_audit['summary'] ?? array(), 'disclaimer' => CompromiseAuditService::LIMITATION_NOTICE) : array(),
				'description' => CompromiseAuditService::LIMITATION_NOTICE,
				'fix'         => 'run_compromise_audit',
			)
		);

		return $this->result();
	}

	/**
	 * Apply a hardening fix.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix($action, array $params = array())
	{
		$settings = \OmninoxSiteToolkit\Core\Settings::instance();

		switch ($action) {
			case 'block_user_enumeration':
				if (! \OmninoxSiteToolkit\License\FeatureGate::can_use('security', 'block_user_enumeration')) {
					return new \WP_Error('omninox_pro_required', __('Blocking username enumeration requires Omninox Pro.', 'omninox-site-toolkit'), array('status' => 403));
				}
				$enabled   = isset($params['value']) ? ! empty($params['value']) : true;
				$hardening = (array) $settings->get('hardening', array());
				$hardening['block_user_enumeration'] = $enabled;
				$settings->update(array('hardening' => $hardening));

				return array(
					'message' => $enabled
						? __('Enabled. Username enumeration is blocked.', 'omninox-site-toolkit')
						: __('Disabled. Feature is turned off.', 'omninox-site-toolkit'),
				);

			case 'disable_xmlrpc':
			case 'disable_file_editor':
			case 'limit_logins':
			case 'honeypot':
			case 'two_factor':
			case 'block_ips':
			case 'hide_wp_version':
				$enabled   = isset($params['value']) ? ! empty($params['value']) : true;
				$hardening = (array) $settings->get('hardening', array());
				$hardening[$action] = $enabled;
				$settings->update(array('hardening' => $hardening));

				return array(
					'message' => $enabled
						? __('Enabled. Feature is active on your site.', 'omninox-site-toolkit')
						: __('Disabled. Feature is turned off.', 'omninox-site-toolkit'),
				);

			case 'set_login_limits':
				if (! \OmninoxSiteToolkit\License\FeatureGate::can_use('security', 'set_login_limits')) {
					return new \WP_Error('omninox_pro_required', __('Configuring login attempt limits requires Omninox Pro.', 'omninox-site-toolkit'), array('status' => 403));
				}
				$value = max(3, min(10, absint($params['value'] ?? 5)));
				$settings->update(array('login_attempts' => $value));

				/* translators: %d: number of attempts */
				return array('message' => sprintf(__('Lockout now triggers after %d failed attempts.', 'omninox-site-toolkit'), $value));

			case 'trusted_proxy':
				$enabled = ! empty($params['value']);
				$settings->update(array('trusted_proxy' => $enabled));

				return array(
					'message' => $enabled
						? __('Visitor IPs are now read from proxy headers. Verify the login log shows real visitor addresses, not your CDN\'s.', 'omninox-site-toolkit')
						: __('Visitor IPs are now read from the direct connection only.', 'omninox-site-toolkit'),
				);

			case 'set_lockout_minutes':
				$value = max(5, min(1440, absint($params['value'] ?? 20)));
				$settings->update(array('lockout_minutes' => $value));

				/* translators: %d: minutes */
				return array('message' => sprintf(__('Lockouts now last %d minutes.', 'omninox-site-toolkit'), $value));

			case 'block_ip':
				if (! \OmninoxSiteToolkit\License\FeatureGate::can_use('security', 'block_ip')) {
					return new \WP_Error('omninox_pro_required', __('Blocking IP addresses requires Omninox Pro.', 'omninox-site-toolkit'), array('status' => 403));
				}
				return $this->block_ip($params['ip'] ?? '', $params['note'] ?? '');

			case 'unblock_ip':
				if (! \OmninoxSiteToolkit\License\FeatureGate::can_use('security', 'unblock_ip')) {
					return new \WP_Error('omninox_pro_required', __('Unblocking IP addresses requires Omninox Pro.', 'omninox-site-toolkit'), array('status' => 403));
				}
				return $this->unblock_ip($params['ip'] ?? '');

			case 'list_blocks':
				return array('rows' => $this->blocked_list());

			case 'login_log':
				return array('rows' => $this->login_log());

			case 'clear_lockouts':
				if (! \OmninoxSiteToolkit\License\FeatureGate::can_use('security', 'clear_lockouts')) {
					return new \WP_Error('omninox_pro_required', __('Clearing lockouts requires Omninox Pro.', 'omninox-site-toolkit'), array('status' => 403));
				}
				return $this->clear_lockouts();

			case 'force_password_reset':
				$ids   = array_map('absint', (array) ($params['user_ids'] ?? array()));
				$sent  = 0;

				foreach ($ids as $id) {
					$user = get_userdata($id);

					if ($user && retrieve_password($user->user_login) === true) {
						++$sent;
					}
				}

				return array(
					'sent'    => $sent,
					/* translators: %d: number of users */
					'message' => sprintf(_n('Sent a reset link to %d user.', 'Sent reset links to %d users.', $sent, 'omninox-site-toolkit'), $sent),
				);

			case 'run_compromise_audit':
				$report = CompromiseAuditService::run_full_audit();
				return array(
					'message' => sprintf(
						/* translators: 1: status, 2: findings count */
						__('Compromise audit completed with status: %1$s (%2$d finding(s) identified).', 'omninox-site-toolkit'),
						$report['status'],
						$report['summary']['total_findings'] ?? 0
					),
					'report'  => $report,
				);

			case 'view_compromise_report':
				$last = get_option('omninox_last_compromise_audit');
				if (! is_array($last)) {
					$last = CompromiseAuditService::run_full_audit();
				}
				$rows = array();
				foreach ((array) ($last['findings'] ?? array()) as $f) {
					$rows[] = array(
						'severity'       => strtoupper($f['severity'] ?? 'pass'),
						'category'       => $f['category'] ?? '',
						'object'         => $f['object'] ?? '',
						'classification' => $f['classification'] ?? '',
						'confidence'     => $f['confidence'] ?? '',
						'reason'         => $f['reason'] ?? '',
						'action'         => $f['recommended_action'] ?? '',
					);
				}
				return array(
					'status'          => $last['status'] ?? CompromiseAuditService::STATUS_CLEAN,
					'disclaimer'      => CompromiseAuditService::LIMITATION_NOTICE,
					'recommendations' => $last['recommendations'] ?? array(),
					'rows'            => $rows,
				);

			case 'create_integrity_baseline':
				return CompromiseAuditService::create_integrity_baseline();

			case 'verify_integrity_baseline':
				$check = CompromiseAuditService::verify_integrity_baseline();
				$rows  = array();
				foreach ((array) ($check['differences'] ?? array()) as $d) {
					$rows[] = array(
						'file'     => $d['file'] ?? '',
						'status'   => strtoupper($d['status'] ?? ''),
						'expected' => substr($d['expected'] ?? '', 0, 16) . '...',
						'actual'   => is_string($d['actual']) ? substr($d['actual'], 0, 16) . '...' : '',
					);
				}
				$check['rows'] = $rows;
				return $check;

			case 'quarantine_file':
				return CompromiseAuditService::quarantine_file($params['path'] ?? '', $params['reason'] ?? '');

			case 'restore_quarantined_file':
				return CompromiseAuditService::restore_quarantined_file($params['quarantine_id'] ?? '');

			case 'list_quarantine':
				$quarantined = CompromiseAuditService::list_quarantine();
				$rows        = array();
				foreach ($quarantined as $q) {
					$rows[] = array(
						'id'             => $q['id'] ?? '',
						'original_path'  => $q['original_path'] ?? '',
						'reason'         => $q['reason'] ?? '',
						'quarantined_at' => $q['quarantined_at'] ?? '',
						'by_user'        => $q['by_user'] ?? '',
					);
				}
				return array('rows' => $rows);

			case 'run_compromise_test_suite':
				return CompromiseAuditService::run_16_scenario_test_suite();

			case 'view_prevention_report':
				return CompromiseAuditService::generate_prevention_report();

			case 'view_detection_report':
				return CompromiseAuditService::generate_detection_report();

			case 'view_remediation_report':
				return CompromiseAuditService::generate_remediation_report();

			case 'view_recovery_report':
				return CompromiseAuditService::generate_recovery_report();

			case 'run_self_tamper_check':
				return CompromiseAuditService::audit_self_integrity();

			case 'run_self_safety_tests':
				return CompromiseAuditService::run_self_safety_tests();

			case 'evaluate_release_gate':
				return CompromiseAuditService::evaluate_release_gate();
		}

		return parent::fix($action, $params);
	}

	/**
	 * Add an address to the block list.
	 *
	 * @param string $ip   Address or wildcard pattern.
	 * @param string $note Reason.
	 * @return array|\WP_Error
	 */
	private function block_ip($ip, $note)
	{
		$ip = trim(sanitize_text_field($ip));

		if ('' === $ip) {
			return new \WP_Error('omninox_no_ip', __('Enter an address to block.', 'omninox-site-toolkit'), array('status' => 400));
		}

		if (! filter_var($ip, FILTER_VALIDATE_IP) && ! preg_match('/^[0-9a-f:.]+\*$/i', $ip)) {
			return new \WP_Error('omninox_bad_ip', __('That is not a valid address or wildcard pattern.', 'omninox-site-toolkit'), array('status' => 400));
		}

		// Refuse to lock the current administrator out of their own site.
		if ($ip === $this->client_ip()) {
			return new \WP_Error(
				'omninox_self_block',
				__('That is the address you are connecting from. Blocking it would lock you out immediately.', 'omninox-site-toolkit'),
				array('status' => 400)
			);
		}

		$blocked = (array) get_option('omninox_blocked_ips', array());

		foreach ($blocked as $entry) {
			if ((is_array($entry) ? ($entry['ip'] ?? '') : $entry) === $ip) {
				return new \WP_Error('omninox_already_blocked', __('That address is already blocked.', 'omninox-site-toolkit'), array('status' => 409));
			}
		}

		$blocked[] = array(
			'ip'      => $ip,
			'note'    => sanitize_text_field($note),
			'added'   => current_time('mysql'),
			'by'      => wp_get_current_user()->user_login,
		);

		update_option('omninox_blocked_ips', $blocked, false);
		\OmninoxSiteToolkit\Services\Security::audit('performed', 'security/block_ip', $ip);

		/* translators: %s: ip address */
		return array('message' => sprintf(__('%s is now blocked.', 'omninox-site-toolkit'), $ip), 'rows' => $this->blocked_list());
	}

	/**
	 * Remove an address from the block list.
	 *
	 * @param string $ip Address.
	 * @return array|\WP_Error
	 */
	private function unblock_ip($ip)
	{
		$ip      = trim(sanitize_text_field($ip));
		$blocked = (array) get_option('omninox_blocked_ips', array());
		$kept    = array();

		foreach ($blocked as $entry) {
			if ((is_array($entry) ? ($entry['ip'] ?? '') : $entry) !== $ip) {
				$kept[] = $entry;
			}
		}

		if (count($kept) === count($blocked)) {
			return new \WP_Error('omninox_not_blocked', __('That address is not on the list.', 'omninox-site-toolkit'), array('status' => 404));
		}

		update_option('omninox_blocked_ips', $kept, false);

		/* translators: %s: ip address */
		return array('message' => sprintf(__('%s unblocked.', 'omninox-site-toolkit'), $ip), 'rows' => $this->blocked_list());
	}

	/**
	 * The block list as table rows.
	 *
	 * @return array
	 */
	private function blocked_list()
	{
		$rows = array();

		foreach ((array) get_option('omninox_blocked_ips', array()) as $entry) {
			$rows[] = is_array($entry)
				? array(
					'ip'    => $entry['ip'] ?? '',
					'note'  => $entry['note'] ?? '',
					'added' => $entry['added'] ?? '',
					'by'    => $entry['by'] ?? '',
				)
				: array('ip' => $entry, 'note' => '', 'added' => '', 'by' => '');
		}

		return $rows;
	}

	/**
	 * Recent login attempts and lockouts.
	 *
	 * @return array
	 */
	private function login_log()
	{
		global $wpdb;

		$table = \OmninoxSiteToolkit\Database\Schema::table('logs');

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT created_at, message, hits, source FROM {$table} WHERE type IN ('login','security') AND source LIKE %s OR type = 'login' ORDER BY created_at DESC LIMIT 100",
				'%login%'
			),
			ARRAY_A
		);

		return array_map(
			static function ($row) {
				return array(
					'when'    => $row['created_at'],
					'event'   => $row['message'],
					'repeats' => (int) $row['hits'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Release every active lockout.
	 *
	 * @return array
	 */
	private function clear_lockouts()
	{
		global $wpdb;

		// Sites without a persistent object cache keep transients in the
		// options table; clear them there.
		$cleared = (int) $wpdb->query( // phpcs:ignore WordPress.DB
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_omninox_lock_%' OR option_name LIKE '_transient_timeout_omninox_lock_%' OR option_name LIKE '_transient_omninox_fails_%' OR option_name LIKE '_transient_timeout_omninox_fails_%'"
		);

		// Sites WITH a persistent object cache (Redis, Memcached) keep
		// transients in the cache, where the SQL above finds nothing.
		// The lockout keys are derived from IPs we do not know, so the
		// only reliable way to release every lockout is a group flush.
		if (wp_using_ext_object_cache()) {
			wp_cache_flush();
			$note = __(' The object cache was flushed to release cached lockouts.', 'omninox-site-toolkit');
		} else {
			$note = '';
		}

		return array(
			'message' => sprintf(
				/* translators: 1: number of records, 2: extra note */
				__('Cleared %1$d lockout records. Anyone currently locked out can try again immediately.%2$s', 'omninox-site-toolkit'),
				$cleared,
				$note
			),
		);
	}

	/**
	 * Administrators that look like stale credentials.
	 *
	 * @return array
	 */
	private function weak_password_candidates()
	{
		$users = get_users(array('role' => 'administrator', 'number' => 50));
		$risky = array();

		foreach ($users as $user) {
			$registered = strtotime($user->user_registered);
			$last_seen  = (int) get_user_meta($user->ID, 'omninox_last_login', true);
			$reasons    = array();

			if (in_array(strtolower($user->user_login), array('admin', 'administrator', 'root', 'test', 'demo'), true)) {
				$reasons[] = __('Predictable username', 'omninox-site-toolkit');
			}

			if ($last_seen && (time() - $last_seen) > YEAR_IN_SECONDS) {
				$reasons[] = __('No login in over a year', 'omninox-site-toolkit');
			}

			if (! $last_seen && $registered && (time() - $registered) > YEAR_IN_SECONDS) {
				$reasons[] = __('Never signed in', 'omninox-site-toolkit');
			}

			if ($reasons) {
				$risky[] = array(
					'id'       => $user->ID,
					'login'    => $user->user_login,
					'reasons'  => $reasons,
				);
			}
		}

		return $risky;
	}

	/**
	 * Is /wp/v2/users readable anonymously?
	 *
	 * @return bool
	 */
	private function rest_users_exposed()
	{
		$response = wp_remote_get(rest_url('wp/v2/users'), array('timeout' => 15, 'cookies' => array()));

		if (is_wp_error($response)) {
			return false;
		}

		if (200 !== (int) wp_remote_retrieve_response_code($response)) {
			return false;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		return is_array($body) && ! empty($body);
	}

	/**
	 * Compare core files against official checksums.
	 *
	 * @return array|\WP_Error
	 */
	private function modified_core_files()
	{
		global $wp_version, $wp_local_package;

		if (! function_exists('get_core_checksums')) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$checksums = get_core_checksums($wp_version, $wp_local_package ?: 'en_US');

		if (! is_array($checksums)) {
			return new \WP_Error('omninox_no_checksums', __('Checksums are unavailable for this WordPress version.', 'omninox-site-toolkit'));
		}

		$modified = array();

		foreach ($checksums as $file => $hash) {
			if (0 === strpos($file, 'wp-content/')) {
				continue;
			}

			$path = Utils::get_home_path() . $file;

			if (! file_exists($path)) {
				$modified[] = array('file' => $file, 'status' => 'missing');
				continue;
			}

			if (md5_file($path) !== $hash) {
				$modified[] = array('file' => $file, 'status' => 'modified');
			}
		}

		return $modified;
	}

	/**
	 * PHP or executable files inside uploads.
	 *
	 * @return array
	 */
	private function suspicious_files()
	{
		$uploads = wp_upload_dir();
		$found   = array();

		if (empty($uploads['basedir']) || ! is_dir($uploads['basedir'])) {
			return $found;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads['basedir'], \FilesystemIterator::SKIP_DOTS));
			$checked  = 0;

			foreach ($iterator as $file) {
				if (++$checked > 60000) {
					break;
				}

				$ext = strtolower($file->getExtension());

				if (in_array($ext, array('php', 'phtml', 'php5', 'phar', 'sh', 'pl'), true)) {
					$found[] = array(
						'path'     => str_replace(ABSPATH, '', $file->getPathname()),
						'size'     => Utils::format_bytes($file->getSize()),
						'modified' => gmdate('Y-m-d H:i', $file->getMTime()),
					);
				}
			}
		} catch (\Exception $e) {
			return $found;
		}

		return $found;
	}

	/**
	 * Heuristic scan of recently modified PHP inside wp-content.
	 *
	 * @return array
	 */
	private function malware_patterns()
	{
		$signatures = array(
			'eval\s*\(\s*base64_decode',
			'eval\s*\(\s*gzinflate',
			'eval\s*\(\s*str_rot13',
			'preg_replace\s*\(\s*[\'"].*?\/e[\'"]',
			'\$_(POST|GET|REQUEST)\s*\[[^\]]+\]\s*\(',
			'assert\s*\(\s*\$_',
			'Files' . 'Man',
			'shell_exec\s*\(\s*\$',
		);

		$pattern = '#(' . implode('|', $signatures) . ')#i';
		$found   = array();
		$cutoff  = time() - (60 * DAY_IN_SECONDS);

		try {
			$content_dir = Utils::get_content_dir();
			$iterator    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($content_dir, \FilesystemIterator::SKIP_DOTS));
			$checked  = 0;

			foreach ($iterator as $file) {
				if (++$checked > 40000 || count($found) > 100) {
					break;
				}

				if ('php' !== strtolower($file->getExtension()) || $file->getMTime() < $cutoff || $file->getSize() > 2097152) {
					continue;
				}

				$clean_file = wp_normalize_path($file->getPathname());
				if (false !== strpos($clean_file, 'SecurityModule.php') || false !== strpos($clean_file, 'CompromiseAuditService.php')) {
					continue;
				}

				$code = @file_get_contents($file->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

				if ($code && preg_match($pattern, $code, $matches)) {
					$clean_root   = wp_normalize_path(Utils::get_home_path());
					$display_path = (0 === strpos($clean_file, $clean_root)) ? ltrim(substr($clean_file, strlen($clean_root)), '/') : basename($clean_file);

					$found[] = array(
						'path'      => $display_path,
						'signature' => substr($matches[0], 0, 60),
						'modified'  => gmdate('Y-m-d H:i', $file->getMTime()),
					);
				}
			}
		} catch (\Exception $e) {
			return $found;
		}

		return $found;
	}

	/**
	 * Can the uploads folder be browsed?
	 *
	 * @return bool
	 */
	private function directory_listing_open()
	{
		$uploads  = wp_upload_dir();
		$response = wp_remote_get(trailingslashit($uploads['baseurl']), array('timeout' => 10));

		if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
			return false;
		}

		return false !== stripos(wp_remote_retrieve_body($response), 'Index of');
	}

	/**
	 * Check installed extensions against the WordPress.org advisory feed.
	 *
	 * @return array
	 */
	private function vulnerability_check()
	{
		$cached = get_transient('omninox_vuln_report');

		if (is_array($cached)) {
			return $cached;
		}

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$found   = array();
		$updates = get_site_transient('update_plugins');

		// A pending security release is the strongest signal available without a paid feed.
		foreach ((array) ($updates->response ?? array()) as $file => $data) {
			$plugins_dir = Utils::get_plugins_dir();
			$plugin      = get_plugin_data(trailingslashit($plugins_dir) . $file, false, false);

			$found[] = array(
				'type'      => 'plugin',
				'name'      => $plugin['Name'] ?? dirname($file),
				'installed' => $plugin['Version'] ?? '',
				'fixed_in'  => $data->new_version ?? '',
				'source'    => 'wordpress.org',
			);
		}

		/**
		 * Filter the vulnerability list so a premium feed (WPScan, Patchstack)
		 * can be plugged in without touching core code.
		 *
		 * @param array $found Vulnerability rows.
		 */
		$found = apply_filters('omninox_vulnerability_report', $found);

		set_transient('omninox_vuln_report', $found, 6 * HOUR_IN_SECONDS);

		return $found;
	}

	/**
	 * Failed logins recorded in the last day.
	 *
	 * @return int
	 */
	private function recent_failed_logins()
	{
		global $wpdb;

		$table = \OmninoxSiteToolkit\Database\Schema::table('logs');

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COALESCE(SUM(hits), 0) FROM {$table} WHERE type = 'login' AND created_at >= %s",
				gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
			)
		);
	}

	/**
	 * Which firewall is present?
	 *
	 * @return string
	 */
	private function detected_firewall()
	{
		$known = array(
			'wordfence/wordfence.php'   => 'Wordfence',
			'sucuri-scanner/sucuri.php' => 'Sucuri',
			'better-wp-security/better-wp-security.php' => 'Solid Security',
			'ninjafirewall/ninjafirewall.php' => 'NinjaFirewall',
		);

		foreach ($known as $file => $name) {
			if (Utils::plugin_active($file)) {
				return $name;
			}
		}

		$headers = wp_remote_head(home_url('/'), array('timeout' => 10));

		if (! is_wp_error($headers) && wp_remote_retrieve_header($headers, 'cf-ray')) {
			return 'Cloudflare';
		}

		return '';
	}

	/**
	 * Map meta capabilities to prevent file editing without defining global runtime constants.
	 *
	 * @param array  $caps    Required primitive capabilities.
	 * @param string $cap     Capability name.
	 * @param int    $user_id User ID.
	 * @param array  $args    Arguments.
	 * @return array
	 */
	public function disable_file_editor_map_meta_cap($caps, $cap, $user_id, $args)
	{
		if (in_array($cap, array('edit_plugins', 'edit_themes', 'edit_files'), true)) {
			return array('do_not_allow');
		}
		return $caps;
	}

	/**
	 * Filter user capabilities to remove file editing permissions.
	 *
	 * @param array    $allcaps All capabilities of the user.
	 * @param array    $caps    Requested primitive capabilities.
	 * @param array    $args    Arguments.
	 * @param \WP_User $user    User object.
	 * @return array
	 */
	public function disable_file_editor_user_has_cap($allcaps, $caps, $args, $user)
	{
		if (isset($args[0]) && in_array($args[0], array('edit_plugins', 'edit_themes', 'edit_files'), true)) {
			$allcaps[$args[0]] = false;
		}
		return $allcaps;
	}
}
