<?php
/**
 * Outgoing mail delivery.
 *
 * WordPress sends through PHP's mail() by default. Most shared hosts either
 * block it outright or send from an address that fails SPF and DKIM, so the
 * message is dropped or filed as spam — which is why an escalated ticket can
 * report that it could not be sent.
 *
 * Pointing wp_mail() at a real SMTP mailbox fixes it. This hooks PHPMailer
 * directly, so every email the plugin sends goes the same way, and it records
 * the last delivery failure with the actual SMTP error rather than the bare
 * false that wp_mail() returns.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Notifications;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Config;
use OmninoxSiteToolkit\Database\LogRepository;

defined( 'ABSPATH' ) || exit;

class Mailer {

	use Singleton;

	/**
	 * Where the last failure is kept, so the UI can explain what went wrong.
	 */
	const ERROR_OPTION = 'omninox_mail_last_error';

	/**
	 * Common providers, so nobody has to look up port numbers.
	 *
	 * @return array
	 */
	public static function presets() {
		return apply_filters(
			'omninox_smtp_presets',
			array(
				'gmail'   => array(
					'label'      => __( 'Gmail / Google Workspace', 'omninox-site-toolkit'),
					'host'       => 'smtp.gmail.com',
					'port'       => 587,
					'encryption' => 'tls',
					'note'       => __( 'Requires 2-step verification on the account and a 16-character App Password — your normal Google password will be rejected.', 'omninox-site-toolkit'),
				),
				'outlook' => array(
					'label'      => __( 'Outlook / Microsoft 365', 'omninox-site-toolkit'),
					'host'       => 'smtp-mail.outlook.com',
					'port'       => 587,
					'encryption' => 'tls',
					'note'       => __( 'Microsoft has disabled basic authentication on many tenants. If sign-in fails, your administrator must allow SMTP AUTH for the mailbox.', 'omninox-site-toolkit'),
				),
				'zoho'    => array(
					'label'      => __( 'Zoho Mail', 'omninox-site-toolkit'),
					'host'       => 'smtp.zoho.com',
					'port'       => 587,
					'encryption' => 'tls',
					'note'       => __( 'Use an application-specific password if two-factor authentication is on.', 'omninox-site-toolkit'),
				),
				'sendgrid' => array(
					'label'      => __( 'SendGrid', 'omninox-site-toolkit'),
					'host'       => 'smtp.sendgrid.net',
					'port'       => 587,
					'encryption' => 'tls',
					'note'       => __( 'The username is literally "apikey"; the password is the API key itself.', 'omninox-site-toolkit'),
				),
				'brevo'   => array(
					'label'      => __( 'Brevo (Sendinblue)', 'omninox-site-toolkit'),
					'host'       => 'smtp-relay.brevo.com',
					'port'       => 587,
					'encryption' => 'tls',
					'note'       => __( 'Use the SMTP key from the account, not the login password.', 'omninox-site-toolkit'),
				),
				'mailgun' => array(
					'label'      => __( 'Mailgun', 'omninox-site-toolkit'),
					'host'       => 'smtp.mailgun.org',
					'port'       => 587,
					'encryption' => 'tls',
					'note'       => __( 'Credentials come from the sending domain, not your Mailgun account login.', 'omninox-site-toolkit'),
				),
				'custom'  => array(
					'label'      => __( 'Other / custom host', 'omninox-site-toolkit'),
					'host'       => '',
					'port'       => 587,
					'encryption' => 'tls',
					'note'       => __( 'Your host\'s control panel lists the outgoing server, port and encryption.', 'omninox-site-toolkit'),
				),
			)
		);
	}

	/**
	 * Hook up.
	 */
	public function boot() {
		// Runs on the front end and in cron too, because scheduled scans and
		// ticket forwarding both send mail outside the admin.
		add_action( 'phpmailer_init', array( $this, 'configure' ) );
		add_action( 'wp_mail_failed', array( $this, 'record_failure' ) );

		if ( $this->is_enabled() ) {
			add_filter( 'wp_mail_from', array( $this, 'from_address' ), 20 );
			add_filter( 'wp_mail_from_name', array( $this, 'from_name' ), 20 );
		}
	}

	/**
	 * Is SMTP delivery switched on and filled in?
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( ! Config::flag( 'smtp_enabled' ) ) {
			return false;
		}

		return '' !== Config::text( 'smtp_host' ) && '' !== Config::text( 'smtp_pass' );
	}

	/**
	 * Point PHPMailer at the configured server.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 */
	public function configure( $phpmailer ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host    = Config::text( 'smtp_host' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Port    = Config::number( 'smtp_port' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Timeout = 20; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		$encryption = Config::text( 'smtp_encryption' );

		if ( in_array( $encryption, array( 'tls', 'ssl' ), true ) ) {
			$phpmailer->SMTPSecure = $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		} else {
			$phpmailer->SMTPSecure  = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->SMTPAutoTLS = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		if ( Config::flag( 'smtp_auth' ) ) {
			$phpmailer->SMTPAuth = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Username = Config::text( 'smtp_user' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			// Gmail shows app passwords in groups of four; the server wants them joined up.
			$phpmailer->Password = preg_replace( '/\s+/', '', Config::text( 'smtp_pass' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		} else {
			$phpmailer->SMTPAuth = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		if ( ! Config::flag( 'smtp_verify_ssl' ) ) {
			$phpmailer->SMTPOptions = array(
				'ssl' => array(
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'allow_self_signed' => true,
				),
				'tls' => array(
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'allow_self_signed' => true,
				),
			);
		}

		$from = $this->from_address( '' );

		if ( $from ) {
			// setFrom throws on an address PHPMailer considers invalid, and an
			// exception here would take down whatever was sending the mail.
			try {
				$phpmailer->setFrom( $from, $this->from_name( '' ), false );
			} catch ( \Exception $e ) {
				$this->log( 'Invalid From address: ' . $e->getMessage() );
			}
		}

		/**
		 * Last chance to adjust the mailer before it connects.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
		 */
		do_action( 'omninox_smtp_configured', $phpmailer );
	}

	/**
	 * From address.
	 *
	 * @param string $default Existing value.
	 * @return string
	 */
	public function from_address( $default ) {
		$from = sanitize_email( Config::text( 'smtp_from_email' ) );

		if ( '' === $from ) {
			$from = sanitize_email( Config::text( 'smtp_user' ) );
		}

		return $from ? $from : $default;
	}

	/**
	 * From name.
	 *
	 * @param string $default Existing value.
	 * @return string
	 */
	public function from_name( $default ) {
		$name = Config::text( 'smtp_from_name' );

		return '' !== $name ? $name : ( $default ? $default : get_bloginfo( 'name' ) );
	}

	/* ------------------------------------------------------------------ Failures */

	/**
	 * Capture the reason a send failed.
	 *
	 * wp_mail() only returns false, so without this the UI can say a message
	 * did not go but not why — and the why is almost always the fix.
	 *
	 * @param \WP_Error $error Error from wp_mail.
	 */
	public function record_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$data = $error->get_error_data();

		update_option(
			self::ERROR_OPTION,
			array(
				'message' => $error->get_error_message(),
				'to'      => is_array( $data ) ? implode( ', ', (array) ( $data['to'] ?? array() ) ) : '',
				'subject' => is_array( $data ) ? (string) ( $data['subject'] ?? '' ) : '',
				'at'      => current_time( 'mysql' ),
				'smtp'    => $this->is_enabled(),
			),
			false
		);

		$this->log( $error->get_error_message() );
	}

	/**
	 * The last delivery failure, if there was one.
	 *
	 * @return array|null
	 */
	public static function last_error() {
		$error = get_option( self::ERROR_OPTION, null );

		return is_array( $error ) ? $error : null;
	}

	/**
	 * Forget the last failure — called after a successful test.
	 */
	public static function clear_error() {
		delete_option( self::ERROR_OPTION );
	}

	/**
	 * Write to the plugin error log.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( ! class_exists( '\OmninoxSiteToolkit\Database\LogRepository' ) ) {
			return;
		}

		LogRepository::record(
			array(
				'type'    => 'mail',
				'level'   => 'error',
				'message' => 'Mail delivery failed: ' . $message,
				'source'  => 'omninox/mail',
			)
		);
	}

	/* ------------------------------------------------------------------ Testing */

	/**
	 * Send a test message and report what actually happened.
	 *
	 * @param string $to Recipient. Defaults to the support address.
	 * @return array
	 */
	public function send_test( $to = '' ) {
		$to = sanitize_email( $to );

		if ( '' === $to ) {
			$to = sanitize_email( Config::text( 'support_email' ) );
		}

		if ( '' === $to || ! is_email( $to ) ) {
			return array(
				'sent'    => false,
				'message' => __( 'Enter a valid address to send the test to.', 'omninox-site-toolkit'),
			);
		}

		self::clear_error();

		$debug   = array();
		$capture = function ( $phpmailer ) use ( &$debug ) {
			// Level 1 gives the server conversation without dumping credentials.
			$phpmailer->SMTPDebug   = 1; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Debugoutput = function ( $line ) use ( &$debug ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$debug[] = trim( $line );
			};
		};

		add_action( 'phpmailer_init', $capture, 99 );

		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( 'Test email from %s', 'omninox-site-toolkit'),
				get_bloginfo( 'name' )
			),
			$this->test_body(),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		remove_action( 'phpmailer_init', $capture, 99 );

		$error = self::last_error();

		if ( $sent ) {
			self::clear_error();

			return array(
				'sent'    => true,
				'message' => sprintf(
					/* translators: %s: recipient address. */
					__( 'Sent to %s. Check the inbox, and the spam folder — if it landed in spam, the address is delivering but failing authentication checks.', 'omninox-site-toolkit'),
					$to
				),
				'debug'   => array_slice( $debug, 0, 40 ),
			);
		}

		return array(
			'sent'    => false,
			'message' => $this->explain( $error ),
			'error'   => $error ? $error['message'] : '',
			'debug'   => array_slice( $debug, 0, 40 ),
		);
	}

	/**
	 * Turn an SMTP error into something actionable.
	 *
	 * @param array|null $error Recorded error.
	 * @return string
	 */
	private function explain( $error ) {
		$raw = $error ? strtolower( (string) $error['message'] ) : '';

		if ( ! $this->is_enabled() ) {
			return __( 'The message did not send, and SMTP is switched off — so WordPress tried PHP mail, which this host is almost certainly blocking. Turn on SMTP delivery above and enter a real mailbox.', 'omninox-site-toolkit');
		}

		if ( false !== strpos( $raw, 'could not authenticate' ) || false !== strpos( $raw, 'authenticat' ) || false !== strpos( $raw, '535' ) ) {
			return __( 'The server rejected the username or password. For Gmail this means you need an App Password rather than your normal password, with 2-step verification enabled on the account first.', 'omninox-site-toolkit');
		}

		if ( false !== strpos( $raw, 'could not connect' ) || false !== strpos( $raw, 'connection refused' ) || false !== strpos( $raw, 'timed out' ) ) {
			return __( 'Nothing answered on that host and port. Either the details are wrong, or your host blocks outbound SMTP — many do. Try port 587 with TLS, then 465 with SSL, and if both fail ask your host to open outbound SMTP.', 'omninox-site-toolkit');
		}

		if ( false !== strpos( $raw, 'certificate' ) || false !== strpos( $raw, 'ssl' ) || false !== strpos( $raw, 'tls' ) ) {
			return __( 'The encrypted connection failed. Check that the port matches the encryption — 587 goes with TLS, 465 with SSL. Mixing them produces exactly this error.', 'omninox-site-toolkit');
		}

		if ( false !== strpos( $raw, 'from address' ) || false !== strpos( $raw, 'sender' ) || false !== strpos( $raw, '553' ) || false !== strpos( $raw, '550' ) ) {
			return __( 'The server refused the From address. It usually has to match the mailbox you authenticated with — set the From address to the same account.', 'omninox-site-toolkit');
		}

		if ( $error ) {
			return sprintf(
				/* translators: %s: raw error message from the mail server. */
				__( 'The mail server refused it: %s', 'omninox-site-toolkit'),
				$error['message']
			);
		}

		return __( 'The message did not send and the server gave no reason. Check the host, port and encryption, then try again.', 'omninox-site-toolkit');
	}

	/**
	 * Body of the test message.
	 *
	 * @return string
	 */
	private function test_body() {
		$html  = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;color:#131a20;line-height:1.5;">';
		$html .= '<p><strong>' . esc_html__( 'Mail delivery is working.', 'omninox-site-toolkit') . '</strong></p>';
		$html .= '<p>' . esc_html__( 'This test was sent from the Omninox Site Toolkit settings screen. Support tickets raised from the chat will now reach their destination.', 'omninox-site-toolkit') . '</p>';
		$html .= '<p style="color:#5a6672;font-size:12px;">' . esc_html( get_bloginfo( 'name' ) ) . ' — ' . esc_html( home_url() ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Delivery status for the settings screen.
	 *
	 * @return array
	 */
	public function status() {
		$error = self::last_error();

		return array(
			'smtp_enabled' => $this->is_enabled(),
			'from'         => $this->from_address( '' ),
			'last_error'   => $error,
			'presets'      => self::presets(),
		);
	}
}
