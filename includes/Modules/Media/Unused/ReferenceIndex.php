<?php
/**
 * Reference Index for Unused Media Detection.
 *
 * Maps Attachment IDs, filenames, and URLs to their referencing locations
 * across posts, pages, page builders, themes, custom fields, and options.
 *
 * Supports high-speed in-memory indexing and batched database persistence.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused;

use OmninoxSiteToolkit\Database\Schema;

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
	 * Buffer for batched SQL inserts.
	 *
	 * @var array
	 */
	private array $db_buffer = array();

	/**
	 * Scan generation timestamp / ID.
	 *
	 * @var int
	 */
	private int $scan_generation = 0;

	/**
	 * Maximum references to record per unique entity in memory.
	 */
	const MAX_REFS_PER_ITEM = 25;

	/**
	 * Set the active scan generation.
	 *
	 * @param int $gen Generation timestamp.
	 */
	public function set_scan_generation( int $gen ): void {
		$this->scan_generation = $gen;
	}

	/**
	 * Get the active scan generation.
	 *
	 * @return int
	 */
	public function get_scan_generation(): int {
		return $this->scan_generation;
	}

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

		$formatted = $this->format_ref( $ref );
		$key       = ( $formatted['type'] ?? 'post' ) . ':' . ( $formatted['id'] ?? 0 ) . ':' . ( $formatted['field'] ?? '' );

		if ( ! isset( $this->id_map[ $attachment_id ][ $key ] ) ) {
			if ( ! isset( $this->id_map[ $attachment_id ] ) || count( $this->id_map[ $attachment_id ] ) < self::MAX_REFS_PER_ITEM ) {
				$this->id_map[ $attachment_id ][ $key ] = $formatted;
			}
		}

		$this->buffer_db_record(
			$attachment_id,
			'',
			'',
			$formatted['type'] ?? 'post',
			(int) ( $formatted['id'] ?? 0 ),
			$formatted['provider'] ?? 'core',
			$formatted['field'] ?? ''
		);
	}

	/**
	 * Record a filename reference.
	 *
	 * @param string $filename File name.
	 * @param array  $ref Reference details.
	 */
	public function add_filename( string $filename, array $ref ): void {
		$norm_fn = ReferenceNormalizer::normalize_filename( $filename );
		if ( '' === $norm_fn ) {
			return;
		}

		$formatted = $this->format_ref( $ref );
		$key       = ( $formatted['type'] ?? 'post' ) . ':' . ( $formatted['id'] ?? 0 ) . ':' . ( $formatted['field'] ?? '' );

		if ( ! isset( $this->filename_map[ $norm_fn ][ $key ] ) ) {
			if ( ! isset( $this->filename_map[ $norm_fn ] ) || count( $this->filename_map[ $norm_fn ] ) < self::MAX_REFS_PER_ITEM ) {
				$this->filename_map[ $norm_fn ][ $key ] = $formatted;
			}
		}

		$this->buffer_db_record(
			0,
			'',
			$norm_fn,
			$formatted['type'] ?? 'post',
			(int) ( $formatted['id'] ?? 0 ),
			$formatted['provider'] ?? 'core',
			$formatted['field'] ?? ''
		);
	}

	/**
	 * Record a URL reference.
	 *
	 * @param string $url Image URL or relative upload path.
	 * @param array  $ref Reference details.
	 */
	public function add_url( string $url, array $ref ): void {
		$norm_url = ReferenceNormalizer::normalize_url( $url );
		if ( '' === $norm_url ) {
			return;
		}

		$formatted = $this->format_ref( $ref );
		$key       = ( $formatted['type'] ?? 'post' ) . ':' . ( $formatted['id'] ?? 0 ) . ':' . ( $formatted['field'] ?? '' );

		if ( ! isset( $this->url_map[ $norm_url ][ $key ] ) ) {
			if ( ! isset( $this->url_map[ $norm_url ] ) || count( $this->url_map[ $norm_url ] ) < self::MAX_REFS_PER_ITEM ) {
				$this->url_map[ $norm_url ][ $key ] = $formatted;
			}
		}

		$norm_fn = ReferenceNormalizer::normalize_filename( $url );
		$this->buffer_db_record(
			0,
			$norm_url,
			$norm_fn,
			$formatted['type'] ?? 'post',
			(int) ( $formatted['id'] ?? 0 ),
			$formatted['provider'] ?? 'core',
			$formatted['field'] ?? ''
		);
	}

	/**
	 * Record an attachment reference by ID with optional URL and filename.
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
	 * Buffer a record for batched SQL insertion.
	 *
	 * @param int    $att_id Attachment ID.
	 * @param string $url Normalized URL.
	 * @param string $filename Normalized filename.
	 * @param string $source_type Source type.
	 * @param int    $source_id Source ID.
	 * @param string $ref_type Reference type / provider.
	 * @param string $field Reference field.
	 */
	private function buffer_db_record( int $att_id, string $url, string $filename, string $source_type, int $source_id, string $ref_type, string $field ): void {
		$hash = md5( "{$att_id}|{$url}|{$filename}|{$source_type}|{$source_id}|{$ref_type}|{$field}" );

		$this->db_buffer[ $hash ] = array(
			'attachment_id'       => $att_id,
			'normalized_url'      => substr( $url, 0, 255 ),
			'normalized_filename' => substr( $filename, 0, 191 ),
			'source_type'         => substr( $source_type, 0, 50 ),
			'source_id'           => $source_id,
			'reference_type'      => substr( $ref_type, 0, 50 ),
			'reference_field'     => substr( $field, 0, 100 ),
			'reference_hash'      => $hash,
			'last_seen'           => gmdate( 'Y-m-d H:i:s' ),
			'scan_generation'     => $this->scan_generation ?: time(),
		);

		if ( count( $this->db_buffer ) >= 200 ) {
			$this->flush_to_db();
		}
	}

	/**
	 * Flush buffered references to database table in a single multi-row query.
	 */
	public function flush_to_db(): void {
		if ( empty( $this->db_buffer ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'media_references' );

		$records = array_values( $this->db_buffer );
		$this->db_buffer = array();

		$placeholders = array();
		$values       = array();

		foreach ( $records as $rec ) {
			$placeholders[] = '(%d, %s, %s, %s, %d, %s, %s, %s, %s, %d)';
			$values[]       = $rec['attachment_id'];
			$values[]       = $rec['normalized_url'];
			$values[]       = $rec['normalized_filename'];
			$values[]       = $rec['source_type'];
			$values[]       = $rec['source_id'];
			$values[]       = $rec['reference_type'];
			$values[]       = $rec['reference_field'];
			$values[]       = $rec['reference_hash'];
			$values[]       = $rec['last_seen'];
			$values[]       = $rec['scan_generation'];
		}

		$sql = "INSERT IGNORE INTO {$table} 
			(attachment_id, normalized_url, normalized_filename, source_type, source_id, reference_type, reference_field, reference_hash, last_seen, scan_generation) 
			VALUES " . implode( ', ', $placeholders );

		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * High-speed batch matching of attachments against database references table.
	 *
	 * Performs indexed lookups matching an array of attachment IDs and variant filenames in bulk.
	 *
	 * @param array $attachment_ids List of attachment IDs.
	 * @param array $family_filenames_map Map of [att_id => array of filenames].
	 * @return array<int, array> Discovered references keyed by attachment ID.
	 */
	public static function match_attachments_in_db( array $attachment_ids, array $family_filenames_map = array() ): array {
		if ( empty( $attachment_ids ) ) {
			return array();
		}

		global $wpdb;
		$table = Schema::table( 'media_references' );
		$matched = array();

		// 1. Direct attachment ID match (bulk IN query)
		$id_placeholders = implode( ',', array_map( 'intval', $attachment_ids ) );
		$id_rows = $wpdb->get_results(
			"SELECT attachment_id, normalized_filename, source_type, source_id, reference_type, reference_field 
			 FROM {$table} 
			 WHERE attachment_id IN ({$id_placeholders})",
			ARRAY_A
		);

		if ( ! empty( $id_rows ) ) {
			foreach ( $id_rows as $row ) {
				$att_id = (int) $row['attachment_id'];
				$matched[ $att_id ][] = array(
					'type'     => $row['source_type'],
					'id'       => (int) $row['source_id'],
					'provider' => $row['reference_type'],
					'field'    => $row['reference_field'],
					'source'   => ucfirst( $row['source_type'] ) . ' #' . $row['source_id'],
					'method'   => 'id_index',
				);
			}
		}

		// 2. Filename match for attachments not matched by ID
		$check_filenames = array();
		$fn_to_att = array();

		foreach ( $attachment_ids as $att_id ) {
			if ( empty( $matched[ $att_id ] ) && ! empty( $family_filenames_map[ $att_id ] ) ) {
				foreach ( $family_filenames_map[ $att_id ] as $fn ) {
					$norm_fn = ReferenceNormalizer::normalize_filename( $fn );
					if ( '' !== $norm_fn ) {
						$check_filenames[] = $norm_fn;
						$fn_to_att[ $norm_fn ][] = $att_id;
					}
				}
			}
		}

		$check_filenames = array_unique( $check_filenames );
		if ( ! empty( $check_filenames ) ) {
			$fn_placeholders = implode( ',', array_fill( 0, count( $check_filenames ), '%s' ) );
			$fn_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT normalized_filename, source_type, source_id, reference_type, reference_field 
					 FROM {$table} 
					 WHERE normalized_filename IN ({$fn_placeholders})",
					$check_filenames
				),
				ARRAY_A
			);

			if ( ! empty( $fn_rows ) ) {
				foreach ( $fn_rows as $row ) {
					$fn = $row['normalized_filename'];
					if ( ! empty( $fn_to_att[ $fn ] ) ) {
						foreach ( $fn_to_att[ $fn ] as $att_id ) {
							$matched[ $att_id ][] = array(
								'type'     => $row['source_type'],
								'id'       => (int) $row['source_id'],
								'provider' => $row['reference_type'],
								'field'    => $row['reference_field'],
								'source'   => ucfirst( $row['source_type'] ) . ' #' . $row['source_id'] . ' (' . $fn . ')',
								'method'   => 'filename_index',
							);
						}
					}
				}
			}
		}

		return $matched;
	}

	/**
	 * Find all verified references for an attachment and its entire variant family.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $family_filenames All generated filenames.
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

		// 2. Family filename references
		foreach ( $family_filenames as $fn ) {
			$norm_fn = ReferenceNormalizer::normalize_filename( $fn );
			if ( ! empty( $this->filename_map[ $norm_fn ] ) ) {
				foreach ( $this->filename_map[ $norm_fn ] as $key => $ref ) {
					$refs[ $key ] = $ref;
				}
			}
		}

		// 3. Family URL references
		foreach ( $family_urls as $url ) {
			$norm_url = ReferenceNormalizer::normalize_url( $url );
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
		return ! empty( $this->get_references_for_attachment( $attachment_id, $family_filenames, $family_urls ) );
	}

	/**
	 * Normalize URLs for reliable lookups.
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized relative or path string.
	 */
	public function normalize_url( string $url ): string {
		return ReferenceNormalizer::normalize_url( $url );
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
			'id_map'          => $this->id_map,
			'filename_map'    => $this->filename_map,
			'url_map'         => $this->url_map,
			'scan_generation' => $this->scan_generation,
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
		$index->id_map          = (array) ( $data['id_map'] ?? array() );
		$index->filename_map    = (array) ( $data['filename_map'] ?? array() );
		$index->url_map         = (array) ( $data['url_map'] ?? array() );
		$index->scan_generation = (int) ( $data['scan_generation'] ?? 0 );

		return $index;
	}
}
