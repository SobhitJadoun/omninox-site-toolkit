<?php
/**
 * Builds plain-language explanations for scan findings.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Services;

defined( 'ABSPATH' ) || exit;

class Explainer {

	/**
	 * Explain a scan issue using the data already collected by the module.
	 *
	 * @param array $issue Scan issue.
	 * @return array
	 */
	public static function explain( array $issue ) {
		$id          = sanitize_key( $issue['id'] ?? '' );
		$severity    = strtolower( sanitize_key( $issue['severity'] ?? 'notice' ) );
		$label       = sanitize_text_field( (string) ( $issue['label'] ?? __( 'This check', 'omninox-site-toolkit') ) );
		$value       = self::value( $issue['value'] ?? '' );
		$expected    = self::value( $issue['expected'] ?? '' );
		$description = sanitize_text_field( (string) ( $issue['description'] ?? '' ) );
		$details     = self::details( $id, $label, $severity, $value, $expected, $issue );

		$fix = ! empty( $issue['fix'] )
			? sprintf( __( 'Use the Fix action for %1$s, then run the scan again to verify the result.', 'omninox-site-toolkit'), $label )
			: ( $details['fix'] ?: sprintf( __( 'Review the %1$s settings shown in this check and apply the recommended change, then scan again.', 'omninox-site-toolkit'), $label ) );

		return array(
			'cause'  => $details['cause'] ?: ( $description ?: sprintf( __( '%1$s is currently reported as %2$s.', 'omninox-site-toolkit'), $label, $value ?: __( 'not meeting the expected state', 'omninox-site-toolkit') ) ),
			'impact' => $details['impact'] ?: self::impact( $severity, $label ),
			'risk'   => $details['risk'] ?: self::risk( $label, $value, $expected, $severity ),
			'fix'    => $fix,
		);
	}

	/**
	 * Return check-specific explanation text.
	 *
	 * @param string $id       Check id.
	 * @param string $label    Check label.
	 * @param string $severity Severity.
	 * @param string $value    Current value.
	 * @param string $expected Expected value.
	 * @param array  $issue    Full issue payload.
	 * @return array
	 */
	private static function details( $id, $label, $severity, $value, $expected, array $issue ) {
		$count = self::context_count( $issue['context'] ?? array() );
		$text  = array( 'cause' => '', 'impact' => '', 'risk' => '', 'fix' => '' );

		$profiles = array(
			'generated_renditions' => array(
				'cause'  => __( 'The media scan found generated WebP and AVIF renditions alongside the original image files.', 'omninox-site-toolkit'),
				'impact' => __( 'Compatible browsers can download smaller modern image formats, reducing transfer size while the originals remain available.', 'omninox-site-toolkit'),
				'risk'   => __( 'This is a healthy media optimization result. The remaining review is whether the configured formats and image sizes match the site\'s delivery needs.', 'omninox-site-toolkit'),
				'fix'    => __( 'No corrective action is required. Review the format, size scope, and saved-byte details below; change them in Media settings only if the site needs a different delivery policy.', 'omninox-site-toolkit'),
			),
			'conversion_support' => array(
				'cause'  => __( 'The server image libraries do not expose a supported WebP or AVIF encoder.', 'omninox-site-toolkit'),
				'impact' => __( 'The plugin cannot create modern image renditions, so visitors may receive larger original files.', 'omninox-site-toolkit'),
				'risk'   => __( 'Image conversion remains unavailable until the hosting PHP image extension is enabled.', 'omninox-site-toolkit'),
				'fix'    => __( 'Ask the host to enable Imagick, or GD compiled with WebP/AVIF support, then rescan and convert the media library.', 'omninox-site-toolkit'),
			),
			'missing_alt' => array(
				'cause'  => __( 'These media-library images have no meaningful alternative text.', 'omninox-site-toolkit'),
				'impact' => __( 'Screen-reader users lose the image\'s meaning, and search engines receive less useful context.', 'omninox-site-toolkit'),
				'risk'   => __( 'Decorative images should have empty alt text, but informative images need an accurate description; filenames are not a reliable substitute.', 'omninox-site-toolkit'),
				'fix'    => __( 'Edit the affected media items and add concise alt text that conveys the image\'s purpose, then rescan.', 'omninox-site-toolkit'),
			),
			'missing_webp' => array(
				'cause'  => __( 'Some original images do not have a generated WebP or AVIF rendition.', 'omninox-site-toolkit'),
				'impact' => __( 'Compatible browsers may download larger JPEG or PNG files than necessary.', 'omninox-site-toolkit'),
				'risk'   => __( 'The effect is most visible on image-heavy pages and slower mobile connections.', 'omninox-site-toolkit'),
				'fix'    => __( 'Convert the affected images using the media conversion action, then confirm the generated files and rescan.', 'omninox-site-toolkit'),
			),
			'ssl' => array(
				'impact' => __( 'Without HTTPS, credentials and submitted form data can be intercepted, and browsers show security warnings.', 'omninox-site-toolkit'),
				'risk'   => __( 'Any login, checkout, or form submission is at risk while the site is served over an insecure connection.', 'omninox-site-toolkit'),
				'fix'    => __( 'Install or renew the TLS certificate, configure the site and WordPress URLs to use HTTPS, redirect HTTP traffic, and rescan.', 'omninox-site-toolkit'),
			),
			'xmlrpc' => array(
				'impact' => __( 'An enabled XML-RPC endpoint increases the number of public authentication requests the site must handle.', 'omninox-site-toolkit'),
				'risk'   => __( 'It can support brute-force amplification unless Jetpack, the WordPress mobile app, or another integration requires it.', 'omninox-site-toolkit'),
				'fix'    => __( 'Disable XML-RPC when no connected app needs it; keep it enabled only with appropriate login protections.', 'omninox-site-toolkit'),
			),
			'php_version' => array(
				'impact' => __( 'Unsupported PHP releases miss security patches and may limit compatibility with current WordPress and plugin versions.', 'omninox-site-toolkit'),
				'risk'   => __( 'The older the runtime, the greater the exposure to unpatched vulnerabilities and avoidable performance problems.', 'omninox-site-toolkit'),
				'fix'    => __( 'Upgrade PHP through the hosting control panel after checking theme and plugin compatibility, then rescan.', 'omninox-site-toolkit'),
			),
			'debug_log_exposed' => array(
				'impact' => __( 'Visitors can read diagnostic output that may reveal filesystem paths, queries, and implementation details.', 'omninox-site-toolkit'),
				'risk'   => __( 'Public logs can help an attacker map the site and may disclose sensitive request data.', 'omninox-site-toolkit'),
				'fix'    => __( 'Block web access to debug.log, move logs outside the public directory where possible, and disable production debugging output.', 'omninox-site-toolkit'),
			),
		);

		if ( isset( $profiles[ $id ] ) ) {
			$text = array_merge( $text, $profiles[ $id ] );
		} elseif ( false !== strpos( $id, 'alt' ) || false !== strpos( strtolower( $label ), 'alt text' ) ) {
			$text['impact'] = __( 'People using screen readers may not understand the purpose of the affected images.', 'omninox-site-toolkit');
			$text['risk']   = __( 'Informative images need accurate alternative text; decorative images should be explicitly marked as decorative.', 'omninox-site-toolkit');
		} elseif ( false !== strpos( $id, 'cache' ) || false !== strpos( strtolower( $label ), 'cache' ) ) {
			$text['impact'] = __( 'Caching can reduce repeated database and PHP work and make pages respond faster.', 'omninox-site-toolkit');
			$text['risk']   = __( 'Without the appropriate cache layer, traffic spikes and uncached requests can increase server load.', 'omninox-site-toolkit');
		} elseif ( in_array( $id, array( 'ttfb', 'page_size', 'http_requests', 'render_blocking_css', 'render_blocking_js', 'duplicate_css', 'duplicate_js', 'google_fonts', 'missing_lazy_load', 'compression' ), true ) ) {
			$text['impact'] = __( 'This affects how quickly the browser can download, process, and display the page for visitors.', 'omninox-site-toolkit');
			$text['risk']   = __( 'Slow or unnecessarily large front-end resources are most noticeable on mobile devices and slower connections.', 'omninox-site-toolkit');
		} elseif ( in_array( $id, array( 'title_length', 'missing_meta_description', 'meta_description_length', 'duplicate_meta_descriptions', 'duplicate_titles', 'missing_h1', 'multiple_h1', 'image_alt_text', 'thin_content', 'canonical' ), true ) ) {
			$text['impact'] = __( 'This affects how clearly search engines and visitors understand and distinguish the site\'s content.', 'omninox-site-toolkit');
			$text['risk']   = __( 'Search engines may show less useful snippets or have more difficulty interpreting the affected pages; review the content before changing it.', 'omninox-site-toolkit');
		} elseif ( in_array( $id, array( 'default_admin', 'weak_passwords', 'admin_count', 'xmlrpc_enabled', 'rest_user_enumeration', 'debug_display', 'core_integrity', 'suspicious_files' ), true ) ) {
			$text['impact'] = __( 'This changes the site\'s exposure to unauthorized access, information disclosure, or malicious file execution.', 'omninox-site-toolkit');
			$text['risk']   = __( 'The severity depends on whether the condition is reachable by unauthenticated visitors and whether other controls limit access.', 'omninox-site-toolkit');
		} elseif ( in_array( $id, array( 'table_overhead', 'revisions', 'auto_drafts', 'trash', 'spam', 'transients', 'orphan_meta', 'wc_sessions' ), true ) ) {
			$text['impact'] = __( 'Unneeded database rows increase storage and can make queries, backups, and maintenance take longer.', 'omninox-site-toolkit');
			$text['risk']   = __( 'Deletion is potentially irreversible, so confirm the affected records and keep a current backup before cleaning them.', 'omninox-site-toolkit');
		} elseif ( in_array( $id, array( 'form_plugins', 'smtp', 'from_domain' ), true ) ) {
			$text['impact'] = __( 'This affects whether forms can load efficiently and whether submitted messages are delivered reliably.', 'omninox-site-toolkit');
			$text['risk']   = __( 'Undelivered form notifications can hide leads, support requests, or account activity from the site owner.', 'omninox-site-toolkit');
		} elseif ( in_array( $id, array( '404s', 'redirects', 'redirect_chains', 'redirect_loop' ), true ) ) {
			$text['impact'] = __( 'Visitors and search crawlers may reach dead ends or take unnecessary hops before arriving at the intended page.', 'omninox-site-toolkit');
			$text['risk']   = __( 'Broken or chained URLs waste crawl budget and can send visitors away from content that should still be reachable.', 'omninox-site-toolkit');
		} elseif ( false !== strpos( $id, 'duplicate' ) ) {
			$text['impact'] = __( 'Duplicate resources or records add avoidable work and make maintenance less predictable.', 'omninox-site-toolkit');
			$text['risk']   = __( 'The practical effect depends on how often the duplicates are loaded or used; review the affected items before removing anything.', 'omninox-site-toolkit');
		}

		if ( $count && '' === $text['cause'] ) {
			$text['cause'] = sprintf( __( 'The check identified %1$s item(s) related to %2$s.', 'omninox-site-toolkit'), number_format_i18n( $count ), $label );
		}

		return $text;
	}

	private static function context_count( $context ) {
		if ( ! is_array( $context ) ) {
			return 0;
		}

		foreach ( $context as $value ) {
			if ( is_array( $value ) ) {
				return count( $value );
			}
		}

		return 0;
	}

	private static function impact( $severity, $label ) {
		if ( 'pass' === $severity ) {
			return sprintf( __( 'The site currently meets the %s check, so no corrective action is needed.', 'omninox-site-toolkit'), $label );
		}

		return sprintf( __( 'This finding describes how %s can affect the site and should be reviewed in its current context.', 'omninox-site-toolkit'), $label );
	}

	private static function risk( $label, $value, $expected, $severity ) {
		if ( $expected ) {
			return sprintf( __( '%1$s is currently %2$s; this check expects %3$s. The practical risk depends on the affected pages and visitors.', 'omninox-site-toolkit'), $label, $value ?: __( 'not reported', 'omninox-site-toolkit'), $expected );
		}

		return 'pass' === $severity
			? sprintf( __( '%s is currently in an acceptable state based on the scan.', 'omninox-site-toolkit'), $label )
			: sprintf( __( 'Review the affected %s details before deciding whether corrective action is needed.', 'omninox-site-toolkit'), $label );
	}

	/**
	 * Convert issue values into safe display text.
	 *
	 * @param mixed $value Issue value.
	 * @return string
	 */
	private static function value( $value ) {
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}
}
