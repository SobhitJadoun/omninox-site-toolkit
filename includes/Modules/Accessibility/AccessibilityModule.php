<?php
/**
 * Module 8: Accessibility Scanner (static analysis of rendered markup).
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Accessibility;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;

defined( 'ABSPATH' ) || exit;

class AccessibilityModule extends AbstractModule {

	protected $id   = 'accessibility';
	protected $icon = 'universal-access';

	public function get_label() {
		return __( 'Accessibility', 'omninox-site-toolkit' );
	}

	/**
	 * Actions.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array(
			array(
				'id'     => 'fill_alt_filenames',
				'label'  => __( 'Fill Missing Alt Text from Filenames', 'omninox-site-toolkit'),
				'type'   => 'button',
				'notice' => __( 'Automatically generates clean, human-readable alt text for images missing alt attributes based on cleaned media filenames.', 'omninox-site-toolkit'),
			),
		);
	}

	/**
	 * Run accessibility fix actions.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		if ( 'fill_alt_filenames' === $action ) {
			if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'accessibility', 'fill_alt_filenames' ) ) {
				return new \WP_Error(
					'omninox_pro_required',
					__( 'Auto-filling alt text from filenames requires Omninox Pro.', 'omninox-site-toolkit' ),
					array( 'status' => 403 )
				);
			}

			return $this->fill_alt_from_filenames();
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Auto-fill missing alt text from cleaned filenames.
	 *
	 * @return array
	 */
	private function fill_alt_from_filenames() {
		global $wpdb;

		$attachments = $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);

		$updated = 0;

		foreach ( (array) $attachments as $id ) {
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );

			if ( '' === trim( (string) $alt ) ) {
				$file = get_post_meta( $id, '_wp_attached_file', true );
				if ( ! $file ) {
					continue;
				}

				$filename = pathinfo( $file, PATHINFO_FILENAME );
				$clean    = ucwords( str_replace( array( '-', '_', '.' ), ' ', preg_replace( '/\d{2,4}x\d{2,4}$/', '', $filename ) ) );

				if ( '' !== trim( $clean ) ) {
					update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $clean ) );
					++$updated;
				}
			}
		}

		return array(
			'updated' => $updated,
			'message' => sprintf(
				/* translators: %d: updated image count */
				__( 'Generated and updated alt text for %d images.', 'omninox-site-toolkit'),
				$updated
			),
		);
	}

	/**
	 * Check the rendered home page against common WCAG failures.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$url  = ! empty( $args['url'] ) ? esc_url_raw( $args['url'] ) : home_url( '/' );
		$page = Utils::timed_get( $url, array( 'timeout' => 25 ) );

		if ( is_wp_error( $page ) ) {
			$this->add(
				array(
					'id'       => 'page_unreachable',
					'label'    => __( 'Page could not be loaded', 'omninox-site-toolkit'),
					'severity' => self::SEV_CRITICAL,
					'value'    => $page->get_error_message(),
					'weight'   => 5,
				)
			);

			return $this->result();
		}

		$html = $page['body'];

		// Language attribute.
		$has_lang = (bool) preg_match( '/<html[^>]+lang=["\'][^"\']+["\']/i', $html );
		$this->add(
			array(
				'id'          => 'html_lang',
				'label'       => __( 'Page language declared', 'omninox-site-toolkit'),
				'value'       => $has_lang ? __( 'Yes', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit'),
				'weight'      => 2,
				'severity'    => $has_lang ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => __( 'Screen readers use the lang attribute to choose a pronunciation model.', 'omninox-site-toolkit'),
			)
		);

		// Images without alt.
		preg_match_all( '/<img[^>]*>/i', $html, $images );
		$offenders = array();

		foreach ( $images[0] as $tag ) {
			if ( preg_match( '/\salt\s*=/i', $tag ) ) {
				continue;
			}

			$row           = $this->locate( $html, $tag, $url );
			$row['source'] = $this->attribute_source( $tag );
			$row['file']   = preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $src ) ? $src[1] : '';
			$offenders[]   = $row;
		}

		$this->add(
			array(
				'id'          => 'image_alt',
				'label'       => __( 'Images with no alt attribute', 'omninox-site-toolkit'),
				'value'       => sprintf( '%d / %d', count( $offenders ), count( $images[0] ) ),
				'expected'    => '0',
				'weight'      => 3,
				'severity'    => empty( $offenders ) ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => __( 'Screen readers announce the filename instead of a description, and search engines lose the context.', 'omninox-site-toolkit'),
				'context'     => array( 'elements' => array_slice( $offenders, 0, 40 ) ),
			)
		);

		// Form fields without labels.
		preg_match_all( '/<input[^>]*>/i', $html, $inputs );
		$unlabelled = array();

		foreach ( $inputs[0] as $tag ) {
			if ( preg_match( '/type=["\'](hidden|submit|button|image)["\']/i', $tag ) ) {
				continue;
			}

			$has_id    = preg_match( '/\sid=["\']([^"\']+)["\']/i', $tag, $id_match );
			$has_aria  = preg_match( '/aria-label(ledby)?=/i', $tag );
			$has_title = preg_match( '/\stitle=/i', $tag );
			$labelled  = $has_aria || $has_title;

			if ( ! $labelled && $has_id ) {
				$labelled = (bool) preg_match( '/<label[^>]+for=["\']' . preg_quote( $id_match[1], '/' ) . '["\']/i', $html );
			}

			if ( $labelled ) {
				continue;
			}

			$row           = $this->locate( $html, $tag, $url );
			$row['source'] = $this->attribute_source( $tag );
			$row['field']  = preg_match( '/name=["\']([^"\']+)["\']/i', $tag, $name ) ? $name[1] : __( 'unnamed', 'omninox-site-toolkit');
			$unlabelled[]  = $row;
		}

		$this->add(
			array(
				'id'          => 'form_labels',
				'label'       => __( 'Form fields without a label', 'omninox-site-toolkit'),
				'value'       => (string) count( $unlabelled ),
				'expected'    => '0',
				'weight'      => 3,
				'severity'    => empty( $unlabelled ) ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => __( 'A field with no label, aria-label or title is announced only as "edit text", so the person cannot tell what to type.', 'omninox-site-toolkit'),
				'context'     => array( 'elements' => array_slice( $unlabelled, 0, 40 ) ),
			)
		);

		// Links with no discernible text.
		preg_match_all( '/<a[^>]*>(.*?)<\/a>/is', $html, $links, PREG_SET_ORDER );
		$empty_links = array();

		foreach ( $links as $link ) {
			$text = trim( wp_strip_all_tags( $link[1] ) );

			if ( '' !== $text || preg_match( '/aria-label=|title=/i', $link[0] ) ) {
				continue;
			}

			$row           = $this->locate( $html, $link[0], $url );
			$row['href']   = preg_match( '/href=["\']([^"\']+)["\']/i', $link[0], $href ) ? $href[1] : '';
			$row['source'] = $this->attribute_source( $link[0] );
			$empty_links[] = $row;
		}

		$this->add(
			array(
				'id'          => 'empty_links',
				'label'       => __( 'Links with no readable text', 'omninox-site-toolkit'),
				'value'       => (string) count( $empty_links ),
				'expected'    => '0',
				'weight'      => 2.5,
				'severity'    => empty( $empty_links ) ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'Usually an icon link. Add an aria-label describing where it goes.', 'omninox-site-toolkit'),
				'context'     => array( 'elements' => array_slice( $empty_links, 0, 40 ) ),
			)
		);

		// Vague link text.
		$vague = array();

		foreach ( $links as $link ) {
			$text = strtolower( trim( wp_strip_all_tags( $link[1] ) ) );

			if ( ! in_array( $text, array( 'click here', 'read more', 'more', 'here', 'link' ), true ) ) {
				continue;
			}

			$row         = $this->locate( $html, $link[0], $url );
			$row['text'] = $text;
			$row['href'] = preg_match( '/href=["\']([^"\']+)["\']/i', $link[0], $href ) ? $href[1] : '';
			$vague[]     = $row;
		}

		$this->add(
			array(
				'id'          => 'vague_link_text',
				'label'       => __( 'Links labelled "click here" or "read more"', 'omninox-site-toolkit'),
				'value'       => (string) count( $vague ),
				'weight'      => 1,
				'severity'    => count( $vague ) < 3 ? self::SEV_PASS : self::SEV_NOTICE,
				'context'     => array( 'elements' => array_slice( $vague, 0, 30 ) ),
				'description' => __( 'Screen reader users often navigate by link list, where these labels carry no meaning.', 'omninox-site-toolkit'),
			)
		);

		// Heading order.
		preg_match_all( '/<h([1-6])[^>]*>/i', $html, $headings );
		$levels = array_map( 'intval', $headings[1] ?? array() );
		$skips  = array();
		$last   = 0;

		foreach ( $levels as $index => $level ) {
			if ( $last && $level > $last + 1 ) {
				$snippet = $headings[0][ $index ] ?? '';
				$row     = $this->locate( $html, $snippet, $url );

				$row['jump']   = 'h' . $last . ' → h' . $level;
				$row['source'] = $this->attribute_source( $snippet );
				$skips[]       = $row;
			}

			$last = $level;
		}

		$h1_count = count( array_filter( $levels, static function ( $l ) {
			return 1 === $l;
		} ) );

		$this->add(
			array(
				'id'          => 'heading_order',
				'label'       => __( 'Skipped heading levels', 'omninox-site-toolkit'),
				'value'       => (string) count( $skips ),
				'expected'    => '0',
				'weight'      => 2,
				'severity'    => empty( $skips ) ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'Jumping from H2 straight to H4 breaks the outline people use to navigate a long page.', 'omninox-site-toolkit'),
				'context'     => array( 'elements' => array_slice( $skips, 0, 30 ) ),
			)
		);

		$this->add(
			array(
				'id'       => 'single_h1',
				'label'    => __( 'Exactly one H1 on the page', 'omninox-site-toolkit'),
				'value'    => (string) $h1_count,
				'expected' => '1',
				'weight'   => 2,
				'severity' => 1 === $h1_count ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		// Landmarks and skip link.
		$has_main = (bool) preg_match( '/<main[\s>]|role=["\']main["\']/i', $html );
		$this->add(
			array(
				'id'       => 'main_landmark',
				'label'    => __( 'Main landmark present', 'omninox-site-toolkit'),
				'value'    => $has_main ? __( 'Yes', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit'),
				'weight'   => 2,
				'severity' => $has_main ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		$has_skip = (bool) preg_match( '/skip[- ]?(to[- ]?)?(main|content)/i', $html );
		$this->add(
			array(
				'id'       => 'skip_link',
				'label'    => __( 'Skip-to-content link', 'omninox-site-toolkit'),
				'value'    => $has_skip ? __( 'Present', 'omninox-site-toolkit') : __( 'Missing', 'omninox-site-toolkit'),
				'weight'   => 1.5,
				'severity' => $has_skip ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		// Positive tabindex.
		$positive_tabindex = preg_match_all( '/tabindex=["\']([1-9]\d*)["\']/i', $html );
		$this->add(
			array(
				'id'          => 'positive_tabindex',
				'label'       => __( 'Elements with a positive tabindex', 'omninox-site-toolkit'),
				'value'       => (string) $positive_tabindex,
				'expected'    => '0',
				'weight'      => 1.5,
				'severity'    => 0 === $positive_tabindex ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'A positive tabindex overrides the natural reading order and usually strands keyboard users.', 'omninox-site-toolkit'),
			)
		);

		// Inline colour contrast heuristics.
		$contrast = $this->inline_contrast_failures( $html );
		$this->add(
			array(
				'id'          => 'contrast',
				'label'       => __( 'Inline colour pairs below 4.5:1', 'omninox-site-toolkit'),
				'value'       => (string) count( $contrast ),
				'weight'      => 2,
				'severity'    => empty( $contrast ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'     => array( 'pairs' => array_slice( $contrast, 0, 20 ) ),
				'description' => __( 'Only inline styles can be checked from the server. Run a browser audit for stylesheet-driven contrast.', 'omninox-site-toolkit'),
			)
		);

		// Autoplaying media.
		$autoplay = preg_match_all( '/<(video|audio)[^>]*\bautoplay\b/i', $html );
		$this->add(
			array(
				'id'       => 'autoplay_media',
				'label'    => __( 'Media that plays automatically', 'omninox-site-toolkit'),
				'value'    => (string) $autoplay,
				'expected' => '0',
				'weight'   => 1.5,
				'severity' => 0 === $autoplay ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		return $this->result( array( 'audited_url' => $url ) );
	}


	/**
	 * Describe where a snippet sits in the document, so a finding points at a
	 * specific element rather than just reporting a count.
	 *
	 * @param string $html    Full document.
	 * @param string $snippet Offending markup.
	 * @param string $url     Page URL.
	 * @return array
	 */
	private function locate( $html, $snippet, $url ) {
		$offset = strpos( $html, $snippet );
		$line   = false === $offset ? 0 : ( substr_count( $html, "\n", 0, $offset ) + 1 );

		return array(
			'element'  => $this->truncate( $snippet ),
			'line'     => $line,
			'location' => $this->section_of( $html, $offset ),
			'url'      => $url,
		);
	}

	/**
	 * Which part of the page a byte offset falls in.
	 *
	 * @param string   $html   Document.
	 * @param int|bool $offset Byte offset.
	 * @return string
	 */
	private function section_of( $html, $offset ) {
		if ( false === $offset ) {
			return __( 'unknown', 'omninox-site-toolkit');
		}

		$landmarks = array(
			'<head'   => __( 'document head', 'omninox-site-toolkit'),
			'<header' => __( 'header', 'omninox-site-toolkit'),
			'<nav'    => __( 'navigation', 'omninox-site-toolkit'),
			'<main'   => __( 'main content', 'omninox-site-toolkit'),
			'<footer' => __( 'footer', 'omninox-site-toolkit'),
		);

		$current = __( 'body', 'omninox-site-toolkit');

		foreach ( $landmarks as $tag => $label ) {
			$position = stripos( $html, $tag );

			if ( false !== $position && $position < $offset ) {
				$current = $label;
			}
		}

		return $current;
	}

	/**
	 * Shorten markup for display without losing the identifying attributes.
	 *
	 * @param string $snippet Markup.
	 * @param int    $length  Maximum characters.
	 * @return string
	 */
	private function truncate( $snippet, $length = 180 ) {
		$snippet = preg_replace( '/\s+/', ' ', trim( (string) $snippet ) );

		return strlen( $snippet ) > $length ? substr( $snippet, 0, $length ) . '…' : $snippet;
	}

	/**
	 * Guess which template or plugin is responsible for a snippet.
	 *
	 * @param string $snippet Markup.
	 * @return string
	 */
	private function attribute_source( $snippet ) {
		if ( preg_match( '#/wp-content/plugins/([^/"\']+)#', $snippet, $match ) ) {
			return 'plugin: ' . $match[1];
		}

		if ( preg_match( '#/wp-content/themes/([^/"\']+)#', $snippet, $match ) ) {
			return 'theme: ' . $match[1];
		}

		if ( preg_match( '/class=["\']([^"\']{0,80})/i', $snippet, $match ) ) {
			$classes = preg_split( '/\s+/', trim( $match[1] ) );

			foreach ( (array) $classes as $class ) {
				if ( preg_match( '/^(elementor|wp-block|woocommerce|et_|fusion|vc_|jet-)/i', $class ) ) {
					return 'builder: ' . $class;
				}
			}
		}

		$theme = wp_get_theme();

		return 'theme: ' . $theme->get( 'Name' );
	}

	/**
	 * Find inline colour/background pairs with poor contrast.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private function inline_contrast_failures( $html ) {
		preg_match_all( '/style=["\']([^"\']*color\s*:[^"\']*)["\']/i', $html, $styles );
		$failures = array();

		foreach ( (array) ( $styles[1] ?? array() ) as $index => $style ) {
			if ( ! preg_match( '/(?<!background-)color\s*:\s*(#[0-9a-f]{3,6})/i', $style, $fg ) ) {
				continue;
			}

			if ( ! preg_match( '/background(?:-color)?\s*:\s*(#[0-9a-f]{3,6})/i', $style, $bg ) ) {
				continue;
			}

			$ratio = $this->contrast_ratio( $fg[1], $bg[1] );

			if ( $ratio < 4.5 ) {
				$failures[] = array(
					'foreground' => $fg[1],
					'background' => $bg[1],
					'ratio'      => round( $ratio, 2 ) . ':1',
					'required'   => '4.5:1',
					'element'    => $this->truncate( $styles[0][ $index ] ?? $style, 120 ),
					'suggestion' => sprintf(
						/* translators: %s: hex colour */
						__( 'Darken the text to about %s to clear 4.5:1', 'omninox-site-toolkit'),
						$this->suggest_accessible_colour( $fg[1], $bg[1] )
					),
				);
			}
		}

		return $failures;
	}

	/**
	 * Darken or lighten a foreground colour until it clears 4.5:1.
	 *
	 * @param string $foreground Hex colour.
	 * @param string $background Hex colour.
	 * @return string
	 */
	private function suggest_accessible_colour( $foreground, $background ) {
		$hex = ltrim( $foreground, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$channels  = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
		$lighten   = $this->luminance( $background ) < 0.5;

		for ( $step = 0; $step < 20; $step++ ) {
			foreach ( $channels as $index => $value ) {
				$channels[ $index ] = $lighten ? min( 255, $value + 12 ) : max( 0, $value - 12 );
			}

			$candidate = sprintf( '#%02x%02x%02x', $channels[0], $channels[1], $channels[2] );

			if ( $this->contrast_ratio( $candidate, $background ) >= 4.5 ) {
				return $candidate;
			}
		}

		return $lighten ? '#ffffff' : '#000000';
	}

	/**
	 * WCAG contrast ratio between two hex colours.
	 *
	 * @param string $a Hex colour.
	 * @param string $b Hex colour.
	 * @return float
	 */
	private function contrast_ratio( $a, $b ) {
		$la = $this->luminance( $a );
		$lb = $this->luminance( $b );

		$light = max( $la, $lb );
		$dark  = min( $la, $lb );

		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	/**
	 * Relative luminance of a hex colour.
	 *
	 * @param string $hex Hex colour.
	 * @return float
	 */
	private function luminance( $hex ) {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$channels = array(
			hexdec( substr( $hex, 0, 2 ) ) / 255,
			hexdec( substr( $hex, 2, 2 ) ) / 255,
			hexdec( substr( $hex, 4, 2 ) ) / 255,
		);

		foreach ( $channels as $index => $value ) {
			$channels[ $index ] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}
}
