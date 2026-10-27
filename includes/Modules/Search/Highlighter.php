<?php
/**
 * Frontend Search Keyword Highlighter and Contextual Excerpt Generator.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Search;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Highlighter {

	use Singleton;

	/**
	 * Boot the highlighter hooks.
	 */
	public function boot() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'the_title', array( $this, 'highlight_title' ), 20, 2 );
		add_filter( 'get_the_excerpt', array( $this, 'highlight_excerpt' ), 20, 2 );
		add_filter( 'the_excerpt', array( $this, 'highlight_text' ), 20 );
		add_filter( 'the_content', array( $this, 'highlight_content' ), 20 );
	}

	/**
	 * Enqueue frontend CSS for search highlighting.
	 */
	public function enqueue_assets() {
		if ( ! is_search() ) {
			return;
		}

		$enabled   = (bool) Settings::instance()->get( 'search.enabled', true );
		$highlight = (bool) Settings::instance()->get( 'search.highlight_terms', true );

		if ( ! $enabled || ! $highlight ) {
			return;
		}

		$css_path = OMNINOX_SITE_TOOLKIT_PATH . 'assets/css/search.css';
		$version  = file_exists( $css_path ) ? filemtime( $css_path ) : OMNINOX_SITE_TOOLKIT_VERSION;

		wp_enqueue_style(
			'omninox-search-highlight',
			OMNINOX_SITE_TOOLKIT_URL . 'assets/css/search.css',
			array(),
			$version
		);
	}

	/**
	 * Get parsed search terms from the active search query.
	 *
	 * @return array
	 */
	public function get_search_terms() {
		if ( ! is_search() ) {
			return array();
		}

		$search_query = get_search_query();
		if ( '' === trim( $search_query ) ) {
			return array();
		}

		$parsed = RelevanceEngine::parse_query( $search_query );
		return $parsed['all_terms'];
	}

	/**
	 * Highlight search terms in the post title safely.
	 *
	 * Pipeline:
	 * RAW TITLE
	 *     ↓
	 * treat title content as plain text
	 *     ↓
	 * escape title text for HTML output
	 *     ↓
	 * perform highlighting on escaped text only
	 *     ↓
	 * insert only plugin-generated trusted markup (<mark>...</mark>)
	 *     ↓
	 * return result
	 *
	 * @param string   $title   Post title.
	 * @param int|null $post_id Post ID.
	 * @return string
	 */
	public function highlight_title( $title, $post_id = null ) {
		if ( ! is_search() || ! in_the_loop() || is_admin() ) {
			return $title;
		}

		$enabled   = (bool) Settings::instance()->get( 'search.enabled', true );
		$highlight = (bool) Settings::instance()->get( 'search.highlight_terms', true );

		if ( ! $enabled || ! $highlight ) {
			return $title;
		}

		// Treat title content strictly as plain text and escape for HTML output.
		$escaped_title = esc_html( (string) $title );

		$terms = $this->get_search_terms();
		if ( empty( $terms ) ) {
			return $escaped_title;
		}

		return $this->apply_title_highlight( $escaped_title, $terms );
	}

	/**
	 * Highlight search terms in the post excerpt.
	 *
	 * @param string        $excerpt Post excerpt.
	 * @param \WP_Post|null $post    Post object.
	 * @return string
	 */
	public function highlight_excerpt( $excerpt, $post = null ) {
		if ( ! is_search() || ! in_the_loop() || is_admin() ) {
			return $excerpt;
		}

		$enabled   = (bool) Settings::instance()->get( 'search.enabled', true );
		$highlight = (bool) Settings::instance()->get( 'search.highlight_terms', true );

		if ( ! $enabled ) {
			return $excerpt;
		}

		$terms = $this->get_search_terms();
		if ( empty( $terms ) ) {
			return $excerpt;
		}

		$smart = (bool) Settings::instance()->get( 'search.smart_excerpts', true );

		// If no excerpt exists or smart excerpt is enabled, generate context excerpt from content
		if ( $smart && $post && ( empty( $excerpt ) || empty( $post->post_excerpt ) ) ) {
			$context_excerpt = $this->generate_contextual_excerpt( $post->post_content, $terms );
			if ( ! empty( $context_excerpt ) ) {
				$excerpt = $context_excerpt;
			}
		}

		if ( $highlight ) {
			return $this->apply_highlight( $excerpt, $terms );
		}

		return $excerpt;
	}

	/**
	 * Highlight search terms in the post content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function highlight_content( $content ) {
		if ( ! is_search() || ! in_the_loop() || is_admin() ) {
			return $content;
		}

		$enabled   = (bool) Settings::instance()->get( 'search.enabled', true );
		$highlight = (bool) Settings::instance()->get( 'search.highlight_terms', true );

		if ( ! $enabled || ! $highlight ) {
			return $content;
		}

		$terms = $this->get_search_terms();
		if ( empty( $terms ) ) {
			return $content;
		}

		return $this->apply_highlight( $content, $terms );
	}

	/**
	 * Generic text highlighting filter.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public function highlight_text( $text ) {
		if ( ! is_search() || ! in_the_loop() || is_admin() ) {
			return $text;
		}

		$terms = $this->get_search_terms();
		if ( empty( $terms ) ) {
			return $text;
		}

		return $this->apply_highlight( $text, $terms );
	}

	/**
	 * Perform highlighting on escaped title text only, inserting only trusted plugin-generated markup.
	 *
	 * Guarantees:
	 * - Treats title content as escaped plain text.
	 * - Only plugin-generated <mark class="omninox-search-highlight">...</mark> tags are inserted.
	 * - HTML character entities (&amp;, &lt;, &gt;, &quot;, &#039;, etc.) are protected from damage.
	 * - Multibyte UTF-8 and regex characters are handled safely.
	 *
	 * @param string $escaped_title HTML-escaped title string.
	 * @param array  $terms         Search terms.
	 * @return string
	 */
	public function apply_title_highlight( $escaped_title, array $terms ) {
		if ( '' === trim( (string) $escaped_title ) || empty( $terms ) ) {
			return $escaped_title;
		}

		// Sort terms by descending length so longer phrases match first.
		usort(
			$terms,
			static function ( $a, $b ) {
				$len_a = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $a, 'UTF-8' ) : strlen( (string) $a );
				$len_b = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $b, 'UTF-8' ) : strlen( (string) $b );
				return $len_b - $len_a;
			}
		);

		$patterns = array();
		foreach ( $terms as $term ) {
			$clean = trim( (string) $term );
			$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $clean, 'UTF-8' ) : strlen( $clean );
			if ( '' === $clean || $len < 2 ) {
				continue;
			}

			// Escape search term for HTML output context so it matches the escaped title representation.
			$escaped_term = esc_html( $clean );
			$patterns[]   = preg_quote( $escaped_term, '/' );
		}

		if ( empty( $patterns ) ) {
			return $escaped_title;
		}

		$patterns = array_unique( $patterns );
		$regex    = '/&[#a-zA-Z0-9]+;(*SKIP)(*FAIL)|(' . implode( '|', $patterns ) . ')/iu';

		return (string) preg_replace_callback(
			$regex,
			static function ( $matches ) {
				if ( ! empty( $matches[1] ) ) {
					return '<mark class="omninox-search-highlight">' . $matches[1] . '</mark>';
				}
				return $matches[0];
			},
			$escaped_title
		);
	}

	/**
	 * Tag-safe replacement of terms with <mark class="omninox-search-highlight">.
	 *
	 * @param string $html  HTML or plain text string.
	 * @param array  $terms Array of search terms.
	 * @return string
	 */
	public function apply_highlight( $html, array $terms ) {
		if ( empty( $html ) || empty( $terms ) ) {
			return $html;
		}

		// Sort terms by descending length so longer phrases match first
		usort(
			$terms,
			static function ( $a, $b ) {
				$len_a = function_exists( 'mb_strlen' ) ? mb_strlen( $a, 'UTF-8' ) : strlen( $a );
				$len_b = function_exists( 'mb_strlen' ) ? mb_strlen( $b, 'UTF-8' ) : strlen( $b );
				return $len_b - $len_a;
			}
		);

		$patterns = array();
		foreach ( $terms as $term ) {
			$clean = trim( $term );
			$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $clean, 'UTF-8' ) : strlen( $clean );
			if ( '' !== $clean && $len >= 2 ) {
				$patterns[] = preg_quote( $clean, '/' );
			}
		}

		if ( empty( $patterns ) ) {
			return $html;
		}

		$regex = '/&[#a-zA-Z0-9]+;(*SKIP)(*FAIL)|(' . implode( '|', $patterns ) . ')/iu';

		// Split by HTML tags so we only highlight actual text, never tags or attribute values
		$parts  = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$output = '';

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			// If this part is an HTML tag, keep it untouched
			if ( 0 === strpos( $part, '<' ) && substr( $part, -1 ) === '>' ) {
				$output .= $part;
			} else {
				// Text node: safely wrap matched terms in mark tags, skipping existing HTML entities
				$output .= (string) preg_replace_callback(
					$regex,
					static function ( $matches ) {
						if ( ! empty( $matches[1] ) ) {
							return '<mark class="omninox-search-highlight">' . $matches[1] . '</mark>';
						}
						return $matches[0];
					},
					$part
				);
			}
		}

		return $output;
	}

	/**
	 * Generate a contextual snippet surrounding the first matched search term.
	 *
	 * @param string $content Post content.
	 * @param array  $terms   Search terms.
	 * @param int    $words   Snippet word length.
	 * @return string
	 */
	public function generate_contextual_excerpt( $content, array $terms, $words = 35 ) {
		$clean_text = wp_strip_all_tags( strip_shortcodes( $content ) );
		$clean_text = preg_replace( '/\s+/', ' ', $clean_text );

		if ( empty( $clean_text ) || empty( $terms ) ) {
			return esc_html( wp_trim_words( $clean_text, $words, '…' ) );
		}

		$lowest_pos = PHP_INT_MAX;
		foreach ( $terms as $term ) {
			$pos = function_exists( 'mb_stripos' )
				? mb_stripos( $clean_text, $term, 0, 'UTF-8' )
				: stripos( $clean_text, $term );

			if ( false !== $pos && $pos < $lowest_pos ) {
				$lowest_pos = $pos;
			}
		}

		if ( PHP_INT_MAX === $lowest_pos ) {
			return esc_html( wp_trim_words( $clean_text, $words, '…' ) );
		}

		// Calculate start character position before match
		$char_padding = (int) ( $words * 4 ); // Approx 4-5 chars per word
		$start_char   = max( 0, $lowest_pos - $char_padding );

		// Adjust to word boundary
		if ( $start_char > 0 ) {
			$space_pos = function_exists( 'mb_strpos' )
				? mb_strpos( $clean_text, ' ', $start_char, 'UTF-8' )
				: strpos( $clean_text, ' ', $start_char );

			if ( false !== $space_pos && $space_pos < $lowest_pos ) {
				$start_char = $space_pos + 1;
			}
		}

		$snippet = function_exists( 'mb_substr' )
			? mb_substr( $clean_text, $start_char, $char_padding * 2, 'UTF-8' )
			: substr( $clean_text, $start_char, $char_padding * 2 );

		$trimmed = wp_trim_words( $snippet, $words, '…' );

		if ( $start_char > 0 ) {
			$trimmed = '… ' . $trimmed;
		}

		return esc_html( $trimmed );
	}
}
