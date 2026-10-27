<?php
/**
 * Reference Index for Unused Media Detection.
 *
 * Maps Attachment IDs, filenames, and URLs to their referencing locations
 * across posts, pages, page builders, themes, custom fields, and options.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused;

defined( 'ABSPATH' ) || exit;

class ReferenceIndex {

	/**
	 * Map: attachment_id => list of references.
	 *
	 * @var array<int, array>
	 */
	private array $id_map = array();

	/**
	 * Map: normalized_filename => list of references.
	 *
	 * @var array<string, array>
	 */
	private array $filename_map = array();

	/**
	 * Map: normalized_url => list of references.
	 *
	 * @var array<string, array>
	 */
	private array $url_map = array();

	/**
	 * Maximum references to record per unique entity.
	 * Prevents memory exhaustion on large sites where common images (logos, icons)
	 * appear across thousands of posts.
	 */
	const MAX_REFS_PER_ITEM = 25;

	/**
	 * Record an attachment ID reference.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $ref Reference details.
	 */
	public function add_id( int $attachment_id, array $ref ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		if ( isset( $this->id_map[ $attachment_id ] ) && count( $this->id_map[ $attachment_id ] ) >= self::MAX_REFS_PER_ITEM ) {
			return;
		}

		$key = ( $ref['type'] ?? 'post' ) . ':' . ( $ref['id'] ?? 0 ) . ':' . ( $ref['field'] ?? '' );
		if ( isset( $this->id_map[ $attachment_id ][ $key ] ) ) {
			return;
		}

		$this->id_map[ $attachment_id ][ $key ] = $this->format_ref( $ref );
	}

	/**
	 * Record a filename reference (e.g. photo.jpg, photo-300x200.jpg, photo.webp).
	 *
	 * @param string $filename File name.
	 * @param array  $ref Reference details.
	 */
	public function add_filename( string $filename, array $ref ): void {
		$filename = strtolower( trim( wp_basename( $filename ) ) );
		if ( '' === $filename ) {
			return;
		}

		if ( isset( $this->filename_map[ $filename ] ) && count( $this->filename_map[ $filename ] ) >= self::MAX_REFS_PER_ITEM ) {
			return;
		}

		$key = ( $ref['type'] ?? 'post' ) . ':' . ( $ref['id'] ?? 0 ) . ':' . ( $ref['field'] ?? '' );
		if ( isset( $this->filename_map[ $filename ][ $key ] ) ) {
			return;
		}

		$this->filename_map[ $filename ][ $key ] = $this->format_ref( $ref );
	}

	/**
	 * Record a URL reference.
	 *
	 * @param string $url Image URL or relative upload path.
	 * @param array  $ref Reference details.
	 */
	public function add_url( string $url, array $ref ): void {
		$normalized = $this->normalize_url( $url );
		if ( '' === $normalized ) {
			return;
		}

		if ( isset( $this->url_map[ $normalized ] ) && count( $this->url_map[ $normalized ] ) >= self::MAX_REFS_PER_ITEM ) {
			return;
		}

		$key = ( $ref['type'] ?? 'post' ) . ':' . ( $ref['id'] ?? 0 ) . ':' . ( $ref['field'] ?? '' );
		if ( isset( $this->url_map[ $normalized ][ $key ] ) ) {
			return;
		}

		$this->url_map[ $normalized ][ $key ] = $this->format_ref( $ref );
	}

	/**
	 * Record an attachment reference by ID with optional URL and filename.
	 *
	 * Compatible with both positional arguments and array format across Free and Pro modules.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param array|string $type_or_ref   Type string (e.g. 'post') or full reference array.
	 * @param int          $id            Object ID.
	 * @param string       $provider      Provider identifier.
	 * @param string       $field         Field name or setting key.
	 * @param string|null  $url           Associated image URL if known.
	 * @param string       $method        Detection method.
	 */
	public function add_reference( int $attachment_id, $type_or_ref = 'post', int $id = 0, string $provider = 'core', string $field = '', ?string $url = null, string $method = 'id' ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		if ( is_array( $type_or_ref ) ) {
			$ref = $type_or_ref;
		} else {
			$ref = array(
				'type'     => (string) $type_or_ref,
				'id'       => (int) $id,
				'provider' => (string) $provider,
				'field'    => (string) $field,
				'url'      => (string) ( $url ?? '' ),
				'method'   => (string) $method,
			);
		}

		$this->add_id( $attachment_id, $ref );

		if ( ! empty( $ref['url'] ) && is_string( $ref['url'] ) ) {
			$this->add_url( $ref['url'], $ref );
			$this->add_filename( wp_basename( $ref['url'] ), $ref );
		}
	}

	/**
	 * Record an attachment reference by URL and filename.
	 *
	 * @param string       $url         Image URL.
	 * @param array|string $type_or_ref Type string or reference array.
	 * @param int          $id          Object ID.
	 * @param string       $provider    Provider identifier.
	 * @param string       $field       Field name or setting key.
	 * @param string       $method      Detection method.
	 */
	public function add_reference_by_url( string $url, $type_or_ref = 'post', int $id = 0, string $provider = 'core', string $field = '', string $method = 'url' ): void {
		$url = trim( $url );
		if ( '' === $url ) {
			return;
		}

		if ( is_array( $type_or_ref ) ) {
			$ref = $type_or_ref;
		} else {
			$ref = array(
				'type'     => (string) $type_or_ref,
				'id'       => (int) $id,
				'provider' => (string) $provider,
				'field'    => (string) $field,
				'url'      => $url,
				'method'   => (string) $method,
			);
		}

		if ( empty( $ref['url'] ) ) {
			$ref['url'] = $url;
		}

		$this->add_url( $url, $ref );
		$this->add_filename( wp_basename( $url ), $ref );
	}

	/**
	 * Safe fallback for dynamic or legacy reference recording calls.
	 *
	 * @param string $name Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	public function __call( string $name, array $arguments ) {
		if ( 'add_reference' === $name && ! empty( $arguments ) ) {
			$this->add_reference( ...$arguments );
			return null;
		}
		if ( 'add_reference_by_url' === $name && ! empty( $arguments ) ) {
			$this->add_reference_by_url( ...$arguments );
			return null;
		}
		return null;
	}

	/**
	 * Find all verified references for an attachment and its entire variant family.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $family_filenames All generated filenames (original, sizes, WebP/AVIF).
	 * @param array $family_urls All generated URLs.
	 * @return array List of unique references.
	 */
	public function get_references_for_attachment( int $attachment_id, array $family_filenames = array(), array $family_urls = array() ): array {
		$refs = array();

		// 1. Direct ID references
		if ( ! empty( $this->id_map[ $attachment_id ] ) ) {
			foreach ( $this->id_map[ $attachment_id ] as $key => $ref ) {
				$refs[ $key ] = $ref;
			}
		}

		// 2. Family filename references (original, 150x150, 300x200, WebP, etc.)
		foreach ( $family_filenames as $fn ) {
			$norm_fn = strtolower( trim( wp_basename( $fn ) ) );
			if ( ! empty( $this->filename_map[ $norm_fn ] ) ) {
				foreach ( $this->filename_map[ $norm_fn ] as $key => $ref ) {
					$refs[ $key ] = $ref;
				}
			}
		}

		// 3. Family URL references
		foreach ( $family_urls as $url ) {
			$norm_url = $this->normalize_url( $url );
			if ( ! empty( $this->url_map[ $norm_url ] ) ) {
				foreach ( $this->url_map[ $norm_url ] as $key => $ref ) {
					$refs[ $key ] = $ref;
				}
			}
		}

		return array_values( $refs );
	}

	/**
	 * Check if an attachment or any of its generated size variants is referenced.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $family_filenames Generated size filenames.
	 * @param array $family_urls Generated size URLs.
	 * @return bool True if referenced anywhere.
	 */
	public function is_referenced( int $attachment_id, array $family_filenames = array(), array $family_urls = array() ): bool {
		if ( ! empty( $this->id_map[ $attachment_id ] ) ) {
			return true;
		}

		foreach ( $family_filenames as $fn ) {
			$norm_fn = strtolower( trim( wp_basename( $fn ) ) );
			if ( ! empty( $this->filename_map[ $norm_fn ] ) ) {
				return true;
			}
		}

		foreach ( $family_urls as $url ) {
			$norm_url = $this->normalize_url( $url );
			if ( ! empty( $this->url_map[ $norm_url ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize URLs for reliable lookups (handles ports, schemes, relative upload paths).
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized relative or path string.
	 */
	public function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Strip query parameters and fragments
		$clean = preg_replace( '/[?#].*$/', '', $url );

		// If full URL, extract path after /wp-content/uploads/
		$uploads = wp_upload_dir();
		$baseurl = $uploads['baseurl'];
		$base_parsed = wp_parse_url( $baseurl, PHP_URL_PATH );

		if ( $base_parsed && false !== strpos( $clean, $base_parsed ) ) {
			$sub = substr( $clean, strpos( $clean, $base_parsed ) + strlen( $base_parsed ) );
			return strtolower( ltrim( $sub, '/' ) );
		}

		// Fallback: lowercase relative path
		return strtolower( ltrim( preg_replace( '#^https?://[^/]+#i', '', $clean ), '/' ) );
	}

	/**
	 * Format reference details consistently.
	 *
	 * @param array $ref Raw reference data.
	 * @return array
	 */
	private function format_ref( array $ref ): array {
		return array(
			'type'        => $ref['type'] ?? 'post',
			'id'          => (int) ( $ref['id'] ?? 0 ),
			'title'       => sanitize_text_field( $ref['title'] ?? '' ),
			'provider'    => sanitize_key( $ref['provider'] ?? 'core' ),
			'field'       => sanitize_text_field( $ref['field'] ?? 'content' ),
			'url'         => esc_url_raw( $ref['url'] ?? '' ),
			'method'      => sanitize_key( $ref['method'] ?? 'id' ),
			'detected_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Convert index to exportable array for snapshot caching.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id_map'       => $this->id_map,
			'filename_map' => $this->filename_map,
			'url_map'      => $this->url_map,
		);
	}

	/**
	 * Restore index from cached array.
	 *
	 * @param array $data Cached data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$index = new self();
		$index->id_map       = (array) ( $data['id_map'] ?? array() );
		$index->filename_map = (array) ( $data['filename_map'] ?? array() );
		$index->url_map      = (array) ( $data['url_map'] ?? array() );

		return $index;
	}
}
