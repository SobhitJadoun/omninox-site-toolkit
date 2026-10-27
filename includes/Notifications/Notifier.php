<?php
/**
 * Email and webhook notifications after a scheduled scan.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Notifications;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\License\LicenseManager;
use OmninoxSiteToolkit\Modules\WhiteLabel\WhiteLabel;

defined( 'ABSPATH' ) || exit;

class Notifier {

	use Singleton;

	/**
	 * Send alerts when a scan surfaces something worth acting on.
	 *
	 * @param array $results Results keyed by module.
	 */
	public function maybe_notify( array $results ) {
		$settings = Settings::instance();
		$levels   = (array) $settings->get( 'notify_on', array( 'critical' ) );
		$findings = array();

		foreach ( $results as $id => $result ) {
			foreach ( (array) ( $result['issues'] ?? array() ) as $issue ) {
				if ( in_array( $issue['severity'] ?? '', $levels, true ) ) {
					$findings[] = array(
						'module'   => $result['label'] ?? $id,
						'label'    => $issue['label'] ?? '',
						'severity' => $issue['severity'],
						'value'    => is_scalar( $issue['value'] ?? '' ) ? $issue['value'] : '',
					);
				}
			}
		}

		if ( empty( $findings ) ) {
			return;
		}

		$this->send_email( $findings );

		if ( LicenseManager::instance()->is_premium() ) {
			$this->send_webhooks( $findings );
		}
	}

	/**
	 * Email summary to the configured address.
	 *
	 * @param array $findings Findings.
	 */
	private function send_email( array $findings ) {
		$to = Settings::instance()->get( 'notify_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$branding = WhiteLabel::branding();

		$subject = sprintf(
			/* translators: 1: number of findings, 2: site name */
			_n( '%1$d issue found on %2$s', '%1$d issues found on %2$s', count( $findings ), 'omninox-site-toolkit'),
			count( $findings ),
			get_bloginfo( 'name' )
		);

		$lines = array(
			sprintf( /* translators: %s: site url */ __( 'Scan results for %s', 'omninox-site-toolkit'), home_url() ),
			'',
		);

		foreach ( array_slice( $findings, 0, 25 ) as $finding ) {
			$lines[] = sprintf( '[%s] %s — %s', strtoupper( $finding['severity'] ), $finding['module'], $finding['label'] );
		}

		if ( count( $findings ) > 25 ) {
			/* translators: %d: number of additional findings */
			$lines[] = sprintf( __( '...and %d more.', 'omninox-site-toolkit'), count( $findings ) - 25 );
		}

		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=omninox-site-toolkit' );

		if ( ! $branding['hide_credit'] ) {
			$lines[] = '';
			$lines[] = $branding['plugin_name'];
		}

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Post to Slack, Discord and Teams webhooks.
	 *
	 * @param array $findings Findings.
	 */
	private function send_webhooks( array $findings ) {
		$hooks = (array) Settings::instance()->get( 'webhooks', array() );

		$text = sprintf(
			/* translators: 1: site name, 2: number of findings */
			__( '%1$s: %2$d issues found during the scheduled scan.', 'omninox-site-toolkit'),
			get_bloginfo( 'name' ),
			count( $findings )
		);

		foreach ( array_slice( $findings, 0, 10 ) as $finding ) {
			$text .= sprintf( "\n• [%s] %s — %s", strtoupper( $finding['severity'] ), $finding['module'], $finding['label'] );
		}

		foreach ( $hooks as $service => $url ) {
			if ( ! $url ) {
				continue;
			}

			// Telegram takes the message as a query parameter on a bot URL
			// rather than a JSON body like the others.
			if ( 'telegram' === $service ) {
				wp_remote_post(
					$url,
					array(
						'timeout' => 15,
						'body'    => array( 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true ),
					)
				);

				continue;
			}

			$body = 'discord' === $service ? array( 'content' => $text ) : array( 'text' => $text );

			wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			);
		}
	}
}
