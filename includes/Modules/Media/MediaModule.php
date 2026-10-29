<?php
/**
 * Module 6: Media Manager with bulk compression, WebP and AVIF conversion.
 *
 * Conversion covers every registered image size, not just the original file,
 * because the intermediate sizes are what visitors actually download.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;
use OmninoxSiteToolkit\Core\Settings;

defined( 'ABSPATH' ) || exit;

class MediaModule extends AbstractModule {

	protected $id   = 'media';
	protected $icon = 'format-image';

	/**
	 * The size the compressor aims for. Anything larger is compressed
	 * toward this target by stepping quality down; anything already at
	 * or under it is left alone.
	 */
	const TARGET_BYTES = 102400;

	/**
	 * The quality floor. The compressor never goes below this, whatever
	 * the target: past this point compression artefacts become visible,
	 * and a slightly-over-target image beats a visibly degraded one.
	 */
	const MIN_QUALITY = 60;

	/**
	 * Quality steps tried in order until the file fits the target.
	 *
	 * @var array
	 */
	const QUALITY_LADDER = array( 82, 76, 70, 65, 60 );

	/**
	 * Images wider than this are scaled down during compression.
	 */
	const MAX_WIDTH = 2560;

	/**
	 * Attachment ids the caller explicitly selected, if any.
	 *
	 * @var array
	 */
	protected $selection = array();

	public function get_label() {
		return __( 'Media', 'omninox-site-toolkit' );
	}

	/**
	 * Serve converted copies (AVIF / WebP) to browsers that support them.
	 */
	public function register() {
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 20 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 20 );
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), 20 );
		add_filter( 'the_content', array( $this, 'filter_content_images' ), 25 );
		add_filter( 'post_thumbnail_html', array( $this, 'filter_content_images' ), 25 );

		// Surface conversion state inside the Media Library itself.
		add_filter( 'manage_media_columns', array( $this, 'library_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'library_column_value' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_details' ), 10, 2 );

		// Originals replaced by their converted copies are held for 30 days, then
		// removed by the plugin's daily maintenance run.
		add_action( 'omninox_daily_maintenance', array( $this, 'purge_replaced_backups' ) );
	}

	/**
	 * Add an Optimisation column to the Media Library list view.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function library_column( $columns ) {
		$columns['omninox_optimised'] = __( 'Optimisation', 'omninox-site-toolkit');

		return $columns;
	}

	/**
	 * Render the column value.
	 *
	 * @param string $column Column id.
	 * @param int    $id     Attachment id.
	 */
	public function library_column_value( $column, $id ) {
		if ( 'omninox_optimised' !== $column ) {
			return;
		}

		$lines = array();
		$avif  = (array) get_post_meta( $id, '_omninox_avif', true );

		if ( ! empty( $avif['files'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: number of files, 2: bytes saved */
				__( 'AVIF: %1$d files, %2$s saved', 'omninox-site-toolkit'),
				count( $avif['files'] ),
				size_format( (int) ( $avif['saved'] ?? 0 ) )
			);
		}

		$webp = (array) get_post_meta( $id, '_omninox_webp', true );

		if ( ! empty( $webp['files'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: number of files, 2: bytes saved */
				__( 'WebP: %1$d files, %2$s saved', 'omninox-site-toolkit'),
				count( $webp['files'] ),
				size_format( (int) ( $webp['saved'] ?? 0 ) )
			);
		}

		$compressed = (array) get_post_meta( $id, '_omninox_compressed', true );

		if ( ! empty( $compressed['before'] ) && empty( $compressed['skipped'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: original size, 2: new size */
				__( 'Compressed: %1$s to %2$s', 'omninox-site-toolkit'),
				size_format( (int) $compressed['before'] ),
				size_format( (int) $compressed['after'] )
			);
		}

		echo $lines
			? esc_html( implode( ' · ', $lines ) )
			: '<span style="color:#787c82">' . esc_html__( 'Not optimised', 'omninox-site-toolkit') . '</span>';
	}

	/**
	 * List the generated renditions on the attachment edit screen.
	 *
	 * @param array    $fields     Attachment fields.
	 * @param \WP_Post $attachment Attachment.
	 * @return array
	 */
	public function attachment_details( $fields, $attachment ) {
		$avif = (array) get_post_meta( $attachment->ID, '_omninox_avif', true );
		$webp = (array) get_post_meta( $attachment->ID, '_omninox_webp', true );

		$parts = array();

		if ( ! empty( $avif['files'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: file count, 2: saved bytes */
				__( 'AVIF: %1$d files, %2$s saved', 'omninox-site-toolkit'),
				count( $avif['files'] ),
				size_format( (int) ( $avif['saved'] ?? 0 ) )
			);
		}

		if ( ! empty( $webp['files'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: file count, 2: saved bytes */
				__( 'WebP: %1$d files, %2$s saved', 'omninox-site-toolkit'),
				count( $webp['files'] ),
				size_format( (int) ( $webp['saved'] ?? 0 ) )
			);
		}

		if ( empty( $parts ) ) {
			return $fields;
		}

		$fields['omninox_renditions'] = array(
			'label' => __( 'Optimised Renditions', 'omninox-site-toolkit'),
			'input' => 'html',
			'html'  => '<p>' . esc_html(
				implode( ' · ', $parts ) . __( '. These sit beside the original and are served only to browsers that support modern formats.', 'omninox-site-toolkit')
			) . '</p>',
		);

		return $fields;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array(
			array(
				'id'      => 'set_conversion_format',
				'label'   => __( 'Conversion format', 'omninox-site-toolkit'),
				'type'    => 'select',
				'current' => Settings::instance()->get( 'conversion_format', 'webp' ),
				'options' => array(
					array( 'value' => 'webp', 'label' => __( 'WebP (wide compatibility)', 'omninox-site-toolkit') ),
					array( 'value' => 'avif', 'label' => __( 'AVIF (maximum compression)', 'omninox-site-toolkit') ),
					array( 'value' => 'both', 'label' => __( 'Both (WebP & AVIF)', 'omninox-site-toolkit') ),
				),
				'notice'  => __( 'Select whether to generate WebP, AVIF, or both formats during conversion passes.', 'omninox-site-toolkit'),
			),
			array(
				'id'     => 'bulk_convert_webp',
				'label'  => __( 'Convert the library to WebP', 'omninox-site-toolkit'),
				'type'   => 'batch',
				'notice' => __( 'Originals are kept untouched. WebP copies are served only to browsers that accept them, so nothing changes visually.', 'omninox-site-toolkit'),
			),
			array(
				'id'     => 'bulk_convert_avif',
				'label'  => __( 'Convert the library to AVIF', 'omninox-site-toolkit'),
				'type'   => 'batch',
				'notice' => __( 'Originals are kept untouched. AVIF copies offer smaller file sizes and are served to compatible browsers.', 'omninox-site-toolkit'),
			),
			array(
				'id'      => 'bulk_compress',
				'label'   => __( 'Compress originals toward 100 KB', 'omninox-site-toolkit'),
				'type'    => 'batch',
				'confirm' => __( 'This rewrites the original image files (JPEG, PNG, GIF, WebP and AVIF), stepping quality down until each fits under 100 KB or the quality floor is reached — whichever comes first, so nothing is visibly degraded. A backup copy is kept in the plugin storage directory for every file it touches, and any existing converted copies are regenerated from the new files. Continue?', 'omninox-site-toolkit' ),
			),
			array(
				'id'      => 'set_webp_scope',
				'label'   => __( 'Sizes to convert', 'omninox-site-toolkit'),
				'type'    => 'select',
				'current' => Settings::instance()->get( 'webp_sizes', 'all' ),
				'options' => array(
					array( 'value' => 'original', 'label' => __( 'Original file only', 'omninox-site-toolkit') ),
					array( 'value' => 'common', 'label' => __( 'Common sizes only', 'omninox-site-toolkit') ),
					array( 'value' => 'all', 'label' => __( 'Every registered size (recommended)', 'omninox-site-toolkit') ),
				),
				'notice'  => __( 'Converting intermediate sizes ensures that thumbnails loaded by visitors are also optimized.', 'omninox-site-toolkit'),
			),
			array(
				'id'      => 'toggle_library_entries',
				'label'   => __( 'Show converted files in the Media Library', 'omninox-site-toolkit'),
				'type'    => 'toggle',
				'current' => Settings::instance()->get( 'add_webp_to_library', false ) ? 1 : 0,
				'notice'  => __( 'Off by default: converted files are served automatically without cluttering the media gallery. Turn on if you want separate Media Library entries for converted files.', 'omninox-site-toolkit'),
			),
			array( 'id' => 'regenerate_thumbnails', 'label' => __( 'Regenerate missing thumbnails', 'omninox-site-toolkit'), 'type' => 'batch' ),
			array(
				'id'     => 'inventory',
				'label'  => __( 'Every image and its optimisation status', 'omninox-site-toolkit'),
				'type'   => 'selector',
				'submit' => __( 'Show images', 'omninox-site-toolkit'),
				'notice' => __( 'Tick individual images, or use the header checkbox to take the whole page, then run a conversion or compression on just that selection.', 'omninox-site-toolkit'),
				'fields' => array(
					array(
						'id'      => 'filter',
						'label'   => __( 'Show', 'omninox-site-toolkit'),
						'type'    => 'select',
						'options' => array(
							array( 'value' => 'all', 'label' => __( 'All images', 'omninox-site-toolkit') ),
							array( 'value' => 'pending', 'label' => __( 'Not yet optimised', 'omninox-site-toolkit') ),
							array( 'value' => 'converted_webp', 'label' => __( 'Converted to WebP', 'omninox-site-toolkit') ),
							array( 'value' => 'converted_avif', 'label' => __( 'Converted to AVIF', 'omninox-site-toolkit') ),
							array( 'value' => 'compressed', 'label' => __( 'Compressed', 'omninox-site-toolkit') ),
						),
					),
					array( 'id' => 'page', 'label' => __( 'Page', 'omninox-site-toolkit'), 'type' => 'text', 'placeholder' => '1' ),
				),
				'bulk'   => array(
					array( 'id' => 'bulk_convert_webp', 'label' => __( 'Convert selected to WebP', 'omninox-site-toolkit') ),
					array( 'id' => 'bulk_convert_avif', 'label' => __( 'Convert selected to AVIF', 'omninox-site-toolkit') ),
					array( 'id' => 'bulk_compress', 'label' => __( 'Compress selected', 'omninox-site-toolkit') ),
					array( 'id' => 'regenerate_thumbnails', 'label' => __( 'Regenerate selected', 'omninox-site-toolkit') ),
				),
			),
			array( 'id' => 'reset_status', 'label' => __( 'Clear processed markers and re-examine', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'fix_missing_alt', 'label' => __( 'Fill alt text from filenames', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'restore_originals', 'label' => __( 'Restore compressed images from backups', 'omninox-site-toolkit'), 'type' => 'button' ),
			array(
				'id'     => 'restore_replaced_originals',
				'label'  => __( 'Undo format replacement (bring originals back)', 'omninox-site-toolkit'),
				'type'   => 'button',
				'notice' => __( 'Returns held originals to the Media Library and switches their records back. Only works within 30 days of the replacement; after that the held copies are purged.', 'omninox-site-toolkit'),
			),
			array(
				'id'      => 'delete_conversions',
				'label'   => __( 'Delete generated WebP / AVIF files', 'omninox-site-toolkit'),
				'type'    => 'form',
				'submit'  => __( 'Delete Converted Files', 'omninox-site-toolkit'),
				'confirm' => __( 'This removes generated converted files for the selected format. If any originals were replaced, they will be automatically restored first. If original backups have expired (> 30 days), a safety warning will protect against media loss. Continue?', 'omninox-site-toolkit'),
				'fields'  => array(
					array(
						'id'      => 'format',
						'label'   => __( 'Select format to delete', 'omninox-site-toolkit'),
						'type'    => 'select',
						'options' => array(
							array( 'value' => 'both', 'label' => __( 'Both (WebP & AVIF)', 'omninox-site-toolkit') ),
							array( 'value' => 'webp', 'label' => __( 'WebP files only', 'omninox-site-toolkit') ),
							array( 'value' => 'avif', 'label' => __( 'AVIF files only', 'omninox-site-toolkit') ),
						),
					),
				),
			),
			array(
				'id'      => 'export_webp_map',
				'label'   => __( 'Export list of URLs to replace with converted files', 'omninox-site-toolkit'),
				'type'    => 'button',
				'notice'  => __( 'Generates an exact old-URL to new-URL list for every image that was actually converted (skipped or failed conversions are excluded). Use this list with a serialization-safe search-replace tool before changing URLs in your database.', 'omninox-site-toolkit'),
			),
			array(
				'id'      => 'replace_originals_with_webp',
				'label'   => __( 'Replace originals with WebP/AVIF everywhere', 'omninox-site-toolkit'),
				'type'    => 'batch',
				'confirm' => __( 'This rewrites references to converted images — in post content, postmeta, options, and intermediate attachment size metadata — to point at converted files, switches Media Library records over, and moves originals into a holding area. Held originals are kept for 30 days (use "Undo format replacement" to bring them back) and then deleted permanently. Make a full database backup first. Continue?', 'omninox-site-toolkit'),
			),
		);
	}

	/**
	 * Inventory the media library.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$support = $this->support();

		// Report the environment first.
		$this->add(
			array(
				'id'          => 'conversion_support',
				'label'       => __( 'Image conversion support', 'omninox-site-toolkit'),
				'value'       => $support['summary'],
				'expected'    => __( 'Imagick or GD with WebP / AVIF', 'omninox-site-toolkit'),
				'weight'      => 3,
				'severity'    => ( $support['webp'] || $support['avif'] ) ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => ( $support['webp'] || $support['avif'] )
					? ''
					: __( 'This server cannot write WebP or AVIF files. Ask your host to enable the Imagick extension, or GD compiled with WebP/AVIF support.', 'omninox-site-toolkit'),
				'context'     => $support,
			)
		);

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => absint( $args['limit'] ?? 800 ),
				'post_status'    => 'inherit',
				'fields'         => 'ids',
			)
		);

		$uploads         = wp_upload_dir();
		$basedir         = trailingslashit( $uploads['basedir'] );
		$missing_alt     = array();
		$large           = array();
		$broken          = array();
		$oversized       = array();
		$unconverted     = array();
		$hashes          = array();
		$duplicates      = array();
		$total_bytes     = 0;
		$saved_bytes     = 0;
		$webp_renditions = 0;
		$avif_renditions = 0;

		foreach ( $attachments as $id ) {
			$file = get_post_meta( $id, '_wp_attached_file', true );
			$path = $basedir . $file;

			if ( ! $file || ! file_exists( $path ) ) {
				$broken[] = array( 'id' => $id, 'file' => $file );
				continue;
			}

			$size         = filesize( $path );
			$total_bytes += $size;

			if ( '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				$missing_alt[] = array( 'id' => $id, 'file' => basename( $file ), 'edit' => get_edit_post_link( $id, 'raw' ) );
			}

			if ( $size > 512000 ) {
				$large[] = array( 'id' => $id, 'file' => basename( $file ), 'size' => Utils::format_bytes( $size ), 'bytes' => $size );
			}

			$dimensions = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			if ( $dimensions && $dimensions[0] > self::MAX_WIDTH ) {
				$oversized[] = array(
					'id'    => $id,
					'file'  => basename( $file ),
					'width' => $dimensions[0] . 'px',
					'size'  => Utils::format_bytes( $size ),
				);
			}

			$webp_rec = (array) get_post_meta( $id, '_omninox_webp', true );
			$avif_rec = (array) get_post_meta( $id, '_omninox_avif', true );

			$has_webp = ! empty( $webp_rec['files'] );
			$has_avif = ! empty( $avif_rec['files'] );

			if ( ! $has_webp && ! $has_avif ) {
				$unconverted[] = $id;
			}

			if ( $has_webp ) {
				$saved_bytes     += (int) ( $webp_rec['saved'] ?? 0 );
				$webp_renditions += count( (array) $webp_rec['files'] );
			}

			if ( $has_avif ) {
				$saved_bytes     += (int) ( $avif_rec['saved'] ?? 0 );
				$avif_renditions += count( (array) $avif_rec['files'] );
			}

			if ( $size < 5242880 ) {
				$hash = md5_file( $path );

				if ( isset( $hashes[ $hash ] ) ) {
					$duplicates[] = array( 'id' => $id, 'file' => basename( $file ), 'duplicate_of' => $hashes[ $hash ] );
				} else {
					$hashes[ $hash ] = $id;
				}
			}
		}

		$this->add(
			array(
				'id'          => 'generated_renditions',
				'label'       => __( 'Generated WebP & AVIF files', 'omninox-site-toolkit'),
				'value'       => sprintf( __( '%d WebP, %d AVIF', 'omninox-site-toolkit'), $webp_renditions, $avif_renditions ),
				'weight'      => 0.5,
				'severity'    => self::SEV_PASS,
				'description' => __( 'Optimized renditions sit beside your original images and are served automatically to compatible browsers.', 'omninox-site-toolkit'),
				'context'     => array(
					'saved'      => Utils::format_bytes( $saved_bytes ),
					'scope'      => Settings::instance()->get( 'webp_sizes', 'all' ),
					'in_library' => Settings::instance()->get( 'add_webp_to_library', false ) ? 'yes' : 'no',
					'format'     => Settings::instance()->get( 'conversion_format', 'webp' ),
				),
			)
		);

		$unused_snapshot = class_exists( '\OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector' )
			? \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::get_latest_snapshot()
			: array();

		if ( ! empty( $unused_snapshot['stats'] ) ) {
			$unused_count = (int) ( $unused_snapshot['stats']['likely_unused'] ?? 0 );
			$unused_items = array();
			if ( ! empty( $unused_snapshot['items'] ) ) {
				foreach ( $unused_snapshot['items'] as $item ) {
					if ( 'likely_unused' === ( $item['status'] ?? '' ) ) {
						$unused_items[] = array(
							'id'   => $item['id'],
							'file' => $item['filename'],
							'size' => $item['formatted_size'] ?? '',
						);
						if ( count( $unused_items ) >= 40 ) {
							break;
						}
					}
				}
			}
		} else {
			$unused = $this->unused_attachments( $attachments );
			$unused_count = count( $unused );
			$unused_items = array_slice( $unused, 0, 40 );
		}

		$checks = array(
			array( 'missing_alt', __( 'Images without alt text', 'omninox-site-toolkit'), count( $missing_alt ), 2.5, 'fix_missing_alt', array( 'items' => array_slice( $missing_alt, 0, 60 ) ) ),
			array( 'large_images', __( 'Images over 500 KB', 'omninox-site-toolkit'), count( $large ), 2.5, 'bulk_compress', array( 'items' => array_slice( $large, 0, 60 ) ) ),
			array( 'oversized_dimensions', sprintf( /* translators: %d: pixel width */ __( 'Images wider than %dpx', 'omninox-site-toolkit'), self::MAX_WIDTH ), count( $oversized ), 2, 'bulk_compress', array( 'items' => array_slice( $oversized, 0, 40 ) ) ),
			array( 'missing_webp', __( 'Images with no WebP/AVIF copy', 'omninox-site-toolkit'), count( $unconverted ), 2, 'bulk_convert_webp', array() ),
			array( 'broken_images', __( 'Library entries with a missing file', 'omninox-site-toolkit'), count( $broken ), 2, '', array( 'items' => array_slice( $broken, 0, 40 ) ) ),
			array( 'duplicate_images', __( 'Duplicate images', 'omninox-site-toolkit'), count( $duplicates ), 1, '', array( 'items' => array_slice( $duplicates, 0, 40 ) ) ),
			array( 'unused_images', __( 'Images not referenced anywhere', 'omninox-site-toolkit'), $unused_count, 1, 'unused_open_manager', array( 'items' => $unused_items, 'has_full_scan' => ! empty( $unused_snapshot['stats'] ) ) ),
		);

		foreach ( $checks as $check ) {
			list( $check_id, $label, $count, $weight, $fix, $context ) = $check;

			$this->add(
				array(
					'id'       => $check_id,
					'label'    => $label,
					'value'    => number_format_i18n( $count ),
					'expected' => '0',
					'weight'   => $weight,
					'severity' => 0 === $count ? self::SEV_PASS : ( $count > 20 ? self::SEV_WARNING : self::SEV_NOTICE ),
					'fix'      => ( $count > 0 && $fix && ( $support['webp'] || $support['avif'] ) ) ? $fix : '',
					'context'  => $context,
				)
			);
		}

		return $this->result(
			array(
				'library_size' => Utils::format_bytes( $total_bytes ),
				'bytes_saved'  => Utils::format_bytes( $saved_bytes ),
				'image_count'  => count( $attachments ),
				'support'      => $support,
				'progress'     => $this->progress(),
			)
		);
	}

	/**
	 * Run a media task.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		$this->selection = array_map( 'absint', (array) ( $params['ids'] ?? array() ) );

		switch ( $action ) {
			case 'bulk_convert_webp':
				return $this->batch_convert( absint( $params['offset'] ?? 0 ), absint( $params['batch'] ?? 10 ), 'webp' );

			case 'bulk_convert_avif':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'media', 'convert_avif' ) ) {
					return new \WP_Error( 'omninox_pro_required', __( 'AVIF conversion requires Omninox Pro.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
				}
				return $this->batch_convert( absint( $params['offset'] ?? 0 ), absint( $params['batch'] ?? 10 ), 'avif' );

			case 'bulk_convert':
				$format = Settings::instance()->get( 'conversion_format', 'webp' );
				if ( 'avif' === $format && ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'media', 'convert_avif' ) ) {
					return new \WP_Error( 'omninox_pro_required', __( 'AVIF conversion requires Omninox Pro.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
				}
				return $this->batch_convert( absint( $params['offset'] ?? 0 ), absint( $params['batch'] ?? 10 ), $format );

			case 'bulk_compress':
				return $this->batch_compress( absint( $params['offset'] ?? 0 ), absint( $params['batch'] ?? 10 ), absint( $params['quality'] ?? 82 ) );

			case 'regenerate_thumbnails':
				return $this->batch_regenerate( absint( $params['offset'] ?? 0 ), absint( $params['batch'] ?? 10 ) );

			case 'fix_missing_alt':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'accessibility', 'fill_alt_filenames' ) ) {
					return new \WP_Error( 'omninox_pro_required', __( 'Auto-filling alt text from filenames requires Omninox Pro.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
				}
				return $this->fill_alt_text();

			case 'restore_originals':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'media', 'restore_original' ) ) {
					return new \WP_Error( 'omninox_pro_required', __( 'Restoring original images requires Omninox Pro.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
				}
				return $this->restore_originals();

			case 'restore_replaced_originals':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'media', 'restore_original' ) ) {
					return new \WP_Error( 'omninox_pro_required', __( 'Restoring replaced originals requires Omninox Pro.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
				}
				return $this->restore_replaced_originals();

			case 'delete_conversions':
				return $this->delete_conversions( $params );

			case 'delete_webp_conversions':
				return $this->delete_conversions( array_merge( $params, array( 'format' => 'webp' ) ) );

			case 'delete_avif_conversions':
				return $this->delete_conversions( array_merge( $params, array( 'format' => 'avif' ) ) );

			case 'export_webp_map':
				return $this->export_conversion_map( 'webp' );

			case 'export_avif_map':
				return $this->export_conversion_map( 'avif' );

			case 'replace_originals_with_webp':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'media', 'replace_original' ) ) {
					return new \WP_Error( 'omninox_pro_required', __( 'Replacing original images in-place with WebP requires Omninox Pro.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
				}
				return $this->replace_originals_with_converted( absint( $params['offset'] ?? 0 ), absint( $params['batch'] ?? 200 ), 'webp' );

			case 'replace_originals_with_avif':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'media', 'replace_original' ) ) {
					return new \WP_Error( 'omninox_pro_required', __( 'Replacing original images in-place with AVIF requires Omninox Pro.', 'omninox-site-toolkit' ), array( 'status' => 403 ) );
				}
				return $this->replace_originals_with_converted( absint( $params['offset'] ?? 0 ), absint( $params['batch'] ?? 200 ), 'avif' );

			case 'set_conversion_format':
				$format = in_array( $params['value'] ?? '', array( 'webp', 'avif', 'both' ), true ) ? $params['value'] : 'webp';
				$before = Settings::instance()->get( 'conversion_format', 'webp' );

				Settings::instance()->update( array( 'conversion_format' => $format ) );
				\OmninoxSiteToolkit\Services\ValueHistory::record( 'conversion_format', $before, $format, __( 'Image conversion format', 'omninox-site-toolkit') );

				return array(
					'previous' => $before,
					'current'  => $format,
					'message'  => __( 'Conversion format saved.', 'omninox-site-toolkit'),
				);

			case 'set_webp_scope':
				$scope  = in_array( $params['value'] ?? '', array( 'all', 'common', 'original' ), true ) ? $params['value'] : 'all';
				$before = Settings::instance()->get( 'webp_sizes', 'all' );

				Settings::instance()->update( array( 'webp_sizes' => $scope ) );
				\OmninoxSiteToolkit\Services\ValueHistory::record( 'webp_sizes', $before, $scope, __( 'Conversion scope', 'omninox-site-toolkit') );

				return array(
					'previous' => $before,
					'current'  => $scope,
					'message'  => __( 'Scope saved. It applies to the next conversion run.', 'omninox-site-toolkit'),
				);

			case 'toggle_library_entries':
				$enabled = ! empty( $params['value'] );

				Settings::instance()->update( array( 'add_webp_to_library' => $enabled ) );

				return array(
					'message' => $enabled
						? __( 'New conversions will also appear as Media Library entries.', 'omninox-site-toolkit')
						: __( 'Converted files stay out of the Media Library and are served automatically.', 'omninox-site-toolkit'),
				);

			case 'media_status':
				return $this->progress();

			case 'inventory':
				return array( 'rows' => $this->inventory( absint( $params['page'] ?? 1 ), sanitize_key( $params['filter'] ?? 'all' ) ) );

			case 'reset_status':
				return $this->reset_status( $this->selection );

			case 'unused_scan_start':
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::start_scan( $params );

			case 'unused_scan_step':
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::step_scan(
					absint( $params['offset'] ?? 0 ),
					absint( $params['batch'] ?? 30 )
				);

			case 'unused_scan_pause':
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::pause_scan();

			case 'unused_scan_resume':
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::resume_scan();

			case 'unused_scan_cancel':
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::cancel_scan();

			case 'unused_get_results':
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::get_results( $params );

			case 'unused_quarantine':
				$ids = ! empty( $params['ids'] ) ? (array) $params['ids'] : $this->selection;
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::bulk_quarantine( $ids );

			case 'unused_restore':
				$ids = ! empty( $params['ids'] ) ? (array) $params['ids'] : $this->selection;
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::bulk_restore( $ids );

			case 'unused_delete':
				$ids = ! empty( $params['ids'] ) ? (array) $params['ids'] : $this->selection;
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::bulk_delete( $ids );

			case 'unused_set_protection':
				$id        = absint( $params['attachment_id'] ?? 0 );
				$protected = ! empty( $params['protected'] );
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::set_protection( $id, $protected );

			case 'unused_get_settings':
				return array( 'settings' => \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::get_settings() );

			case 'unused_save_settings':
				return \OmninoxSiteToolkit\Modules\Media\Unused\UnusedDetector::save_settings( (array) ( $params['settings'] ?? array() ) );

			case 'unused_get_history':
				return \OmninoxSiteToolkit\Modules\Media\Unused\QuarantineManager::get_history( absint( $params['limit'] ?? 100 ) );

			case 'unused_get_quarantined':
				return array(
					'success'     => true,
					'quarantined' => \OmninoxSiteToolkit\Modules\Media\Unused\QuarantineManager::list_quarantined(),
				);
		}

		return parent::fix( $action, $params );
	}

	/* --------------------------------------------------------------- Convert */

	/**
	 * Convert a batch of attachments, including every registered size.
	 *
	 * @param int    $offset Offset into the attachment list.
	 * @param int    $batch  Attachments per request.
	 * @param string $format Target format: webp|avif|both.
	 * @return array|\WP_Error
	 */
	private function batch_convert( $offset, $batch, $format = 'webp' ) {
		$support = $this->support();

		if ( 'avif' === $format && ! $support['avif'] ) {
			return new \WP_Error(
				'omninox_no_avif_library',
				__( 'This server cannot write AVIF files. Imagick with AVIF support or PHP 8.1+ GD with AVIF is required.', 'omninox-site-toolkit'),
				array( 'status' => 501 )
			);
		}

		if ( 'webp' === $format && ! $support['webp'] ) {
			return new \WP_Error(
				'omninox_no_webp_library',
				__( 'This server cannot write WebP files. Imagick or GD with WebP support is required.', 'omninox-site-toolkit'),
				array( 'status' => 501 )
			);
		}

		$batch     = max( 1, min( 25, $batch ) );
		$selection = array_map( 'absint', (array) ( $this->selection ?? array() ) );
		$meta_key  = ( 'avif' === $format ) ? '_omninox_avif' : '_omninox_webp';
		$ids       = $this->convertible_ids( $offset, $batch, $meta_key, $selection );

		$converted = 0;
		$files     = 0;
		$skipped   = 0;
		$failed    = 0;
		$saved     = 0;
		$errors    = array();
		$limited   = false;

		foreach ( $ids as $id ) {
			// Free tier: stop once the 100-image lifetime quota is spent.
			// Pro installs report -1 remaining (unlimited) and never hit this.
			if ( ! \OmninoxSiteToolkit\License\FeatureGate::media_can_convert() ) {
				$limited = true;
				break;
			}

			$outcome = $this->convert_attachment( $id, $format );

			if ( is_wp_error( $outcome ) ) {
				++$failed;

				if ( count( $errors ) < 5 ) {
					$errors[] = array( 'id' => $id, 'error' => $outcome->get_error_message() );
				}

				continue;
			}

			if ( 0 === $outcome['files'] ) {
				++$skipped;
				continue;
			}

			++$converted;
			$files += $outcome['files'];
			$saved += $outcome['saved'];

			// One image counts as one conversion against the free quota.
			\OmninoxSiteToolkit\License\FeatureGate::increment_media_counter( 1 );
		}

		$processed  = $offset + count( $ids );
		$pending    = $this->pending_count( $meta_key );
		$progress   = $this->progress();
		$free_left  = \OmninoxSiteToolkit\License\FeatureGate::media_conversions_remaining();

		$message = $this->convert_message( $converted, $files, $skipped, $failed, $saved, $errors, $format );

		if ( $limited ) {
			$message = sprintf(
				/* translators: %d: free conversion limit */
				__( 'Free conversion limit reached (%d images). Upgrade to Pro for unlimited WebP and AVIF conversions.', 'omninox-site-toolkit'),
				\OmninoxSiteToolkit\License\FeatureGate::MEDIA_FREE_LIMIT
			);
		}

		return array(
			'converted'       => $converted,
			'files'           => $files,
			'skipped'         => $skipped,
			'failed'          => $failed,
			'errors'          => $errors,
			'offset'          => $processed,
			'total'           => $progress['images'],
			'remaining'       => $pending,
			// When the free quota stops the run, mark it done so the UI halts
			// the batch loop and shows the upgrade prompt instead of retrying.
			'done'            => $limited || empty( $ids ) || ! empty( $selection ),
			'saved'           => Utils::format_bytes( $saved ),
			'limit_reached'   => $limited,
			'free_remaining'  => $free_left,
			'upgrade_required' => $limited,
			'message'         => $message,
		);
	}

	/**
	 * One row per image describing exactly what has been done to it.
	 *
	 * @param int    $page   Page number.
	 * @param string $filter all|converted_webp|converted_avif|compressed|pending.
	 * @return array
	 */
	private function inventory( $page = 1, $filter = 'all' ) {
		$per_page = 100;

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$rows    = array();
		$uploads = wp_upload_dir();

		foreach ( $ids as $id ) {
			$file = get_post_meta( $id, '_wp_attached_file', true );
			$path = trailingslashit( $uploads['basedir'] ) . $file;
			$webp = (array) get_post_meta( $id, '_omninox_webp', true );
			$avif = (array) get_post_meta( $id, '_omninox_avif', true );
			$comp = (array) get_post_meta( $id, '_omninox_compressed', true );

			$converted_webp = ! empty( $webp['files'] );
			$converted_avif = ! empty( $avif['files'] );
			$compressed     = ! empty( $comp['before'] ) && empty( $comp['skipped'] );

			if ( 'converted_webp' === $filter && ! $converted_webp ) {
				continue;
			}

			if ( 'converted_avif' === $filter && ! $converted_avif ) {
				continue;
			}

			if ( 'compressed' === $filter && ! $compressed ) {
				continue;
			}

			if ( 'pending' === $filter && ( $converted_webp || $converted_avif || $compressed ) ) {
				continue;
			}

			$total_saved = (int) ( $webp['saved'] ?? 0 ) + (int) ( $avif['saved'] ?? 0 ) + max( 0, (int) ( $comp['before'] ?? 0 ) - (int) ( $comp['after'] ?? 0 ) );

			$rows[] = array(
				'id'         => $id,
				'file'       => basename( (string) $file ),
				'size'       => file_exists( $path ) ? Utils::format_bytes( filesize( $path ) ) : '—',
				'webp'       => $converted_webp
					? sprintf( __( 'Yes (%d files)', 'omninox-site-toolkit'), count( $webp['files'] ) )
					: ( ! empty( $webp['skipped'] ) ? __( 'Not worthwhile', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit') ),
				'avif'       => $converted_avif
					? sprintf( __( 'Yes (%d files)', 'omninox-site-toolkit'), count( $avif['files'] ) )
					: ( ! empty( $avif['skipped'] ) ? __( 'Not worthwhile', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit') ),
				'compressed' => $compressed
					? sprintf( __( '%1$s to %2$s', 'omninox-site-toolkit'), size_format( (int) $comp['before'] ), size_format( (int) $comp['after'] ) )
					: ( ! empty( $comp['skipped'] ) ? __( 'Already optimal', 'omninox-site-toolkit') : __( 'No', 'omninox-site-toolkit') ),
				'saved'      => Utils::format_bytes( $total_saved ),
				'alt'        => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ? __( 'Yes', 'omninox-site-toolkit') : __( 'Missing', 'omninox-site-toolkit'),
				'status'     => ( ( $converted_webp || $converted_avif ) && $compressed )
					? __( 'Fully optimised', 'omninox-site-toolkit')
					: ( ( $converted_webp || $converted_avif || $compressed ) ? __( 'Partly optimised', 'omninox-site-toolkit') : __( 'Not optimised', 'omninox-site-toolkit') ),
			);
		}

		return $rows;
	}

	/**
	 * Clear the processed markers so images can be run again.
	 *
	 * @param array $ids Attachment ids, empty for all.
	 * @return array
	 */
	private function reset_status( array $ids ) {
		if ( empty( $ids ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'posts_per_page' => 5000,
					'fields'         => 'ids',
				)
			);
		}

		foreach ( $ids as $id ) {
			delete_post_meta( $id, '_omninox_compressed' );

			$webp = (array) get_post_meta( $id, '_omninox_webp', true );
			if ( ! empty( $webp['skipped'] ) ) {
				delete_post_meta( $id, '_omninox_webp' );
			}

			$avif = (array) get_post_meta( $id, '_omninox_avif', true );
			if ( ! empty( $avif['skipped'] ) ) {
				delete_post_meta( $id, '_omninox_avif' );
			}
		}

		return array(
			/* translators: %d: number of images */
			'message' => sprintf( __( 'Cleared processed markers on %d images.', 'omninox-site-toolkit'), count( $ids ) ),
		);
	}

	/**
	 * Convert one attachment and all of its generated sizes.
	 *
	 * @param int    $id     Attachment id.
	 * @param string $format webp|avif|both.
	 * @return array|\WP_Error
	 */
	private function convert_attachment( $id, $format = 'webp' ) {
		$original = get_attached_file( $id );

		if ( ! $original || ! file_exists( $original ) ) {
			return new \WP_Error( 'omninox_file_missing', __( 'The file for this attachment is missing from disk.', 'omninox-site-toolkit') );
		}

		$mime = $this->mime_of( $original );

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
			return array( 'files' => 0, 'saved' => 0 );
		}

		$dir      = trailingslashit( dirname( $original ) );
		$targets  = array( $original );
		$metadata = wp_get_attachment_metadata( $id );
		$scope    = Settings::instance()->get( 'webp_sizes', 'all' );

		if ( 'original' !== $scope ) {
			$common = array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' );

			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				if ( 'common' === $scope && ! in_array( $name, $common, true ) ) {
					continue;
				}

				$targets[] = $dir . $size['file'];
			}
		}

		$uploads        = wp_upload_dir();
		$targets        = array_unique( $targets );
		$formats_to_run = ( 'both' === $format ) ? array( 'webp', 'avif' ) : array( $format );

		$total_converted = 0;
		$total_saved     = 0;
		$last_error      = null;

		foreach ( $formats_to_run as $fmt ) {
			$converted_files = array();
			$fmt_saved       = 0;

			foreach ( $targets as $path ) {
				if ( ! file_exists( $path ) ) {
					continue;
				}

				$dest = ( 'avif' === $fmt ) ? $this->avif_path( $path ) : $this->webp_path( $path );

				if ( file_exists( $dest ) ) {
					if ( filemtime( $dest ) >= filemtime( $path ) ) {
						$converted_files[] = $this->relative( $dest, $uploads['basedir'] );
						continue;
					}

					@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}

				$result = ( 'avif' === $fmt )
					? $this->write_avif( $path, $dest )
					: $this->write_webp( $path, $dest );

				if ( is_wp_error( $result ) ) {
					$last_error = $result;
					continue;
				}

				clearstatcache( true, $dest );

				$before = filesize( $path );
				$after  = file_exists( $dest ) ? filesize( $dest ) : 0;

				// A converted copy that is not smaller is worse than the original.
				if ( ! $after || $after >= $before ) {
					@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					continue;
				}

				$converted_files[] = $this->relative( $dest, $uploads['basedir'] );
				$fmt_saved        += ( $before - $after );
			}

			$meta_key = '_omninox_' . $fmt;

			if ( empty( $converted_files ) ) {
				update_post_meta( $id, $meta_key, array( 'files' => array(), 'saved' => 0, 'at' => current_time( 'mysql' ), 'skipped' => true ) );
			} else {
				update_post_meta(
					$id,
					$meta_key,
					array(
						'files' => $converted_files,
						'saved' => $fmt_saved,
						'at'    => current_time( 'mysql' ),
					)
				);

				if ( Settings::instance()->get( 'add_webp_to_library', false ) ) {
					$main_dest = ( 'avif' === $fmt ) ? $this->avif_path( $original ) : $this->webp_path( $original );
					$this->register_in_library( $id, $main_dest, $fmt );
				}

				$total_converted += count( $converted_files );
				$total_saved     += $fmt_saved;
			}
		}

		if ( 0 === $total_converted && $last_error instanceof \WP_Error ) {
			return $last_error;
		}

		return array( 'files' => $total_converted, 'saved' => $total_saved );
	}

	/**
	 * Create a Media Library entry for a generated WebP/AVIF file.
	 *
	 * @param int    $parent Source attachment id.
	 * @param string $path   Converted file path.
	 * @param string $format webp|avif.
	 * @return int Attachment id, or 0.
	 */
	private function register_in_library( $parent, $path, $format = 'webp' ) {
		if ( ! file_exists( $path ) ) {
			return 0;
		}

		$meta_key    = '_omninox_' . $format . '_attachment';
		$existing_id = (int) get_post_meta( $parent, $meta_key, true );

		if ( $existing_id && get_post( $existing_id ) ) {
			return $existing_id;
		}

		$uploads  = wp_upload_dir();
		$relative = $this->relative( $path, $uploads['basedir'] );
		$mime     = 'image/' . $format;
		$title    = get_the_title( $parent ) . ' (' . strtoupper( $format ) . ')';

		$attachment_data = array(
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $parent,
		);

		$attachment = wp_insert_attachment( $attachment_data, $path, $parent );

		if ( is_wp_error( $attachment ) || ! $attachment ) {
			return 0;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		update_post_meta( $attachment, '_wp_attached_file', $relative );
		update_post_meta( $attachment, '_omninox_generated_from', $parent );
		update_post_meta( $parent, $meta_key, $attachment );

		$dimensions = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$metadata = array(
			'file'   => $relative,
			'width'  => $dimensions ? (int) $dimensions[0] : 0,
			'height' => $dimensions ? (int) $dimensions[1] : 0,
			'sizes'  => array(),
		);

		wp_update_attachment_metadata( $attachment, $metadata );

		return (int) $attachment;
	}

	/**
	 * Build an honest summary of what a conversion pass did.
	 *
	 * @param int    $converted Attachments converted.
	 * @param int    $files     Files written.
	 * @param int    $skipped   Attachments already done or not worth converting.
	 * @param int    $failed    Attachments that errored.
	 * @param int    $saved     Bytes saved.
	 * @param array  $errors    Sample errors.
	 * @param string $format    Format converted to.
	 * @return string
	 */
	private function convert_message( $converted, $files, $skipped, $failed, $saved, array $errors, $format = 'webp' ) {
		$format_label = strtoupper( $format );

		if ( $failed && ! $converted ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Nothing was converted. The first error was: %s', 'omninox-site-toolkit'),
				$errors ? $errors[0]['error'] : __( 'unknown', 'omninox-site-toolkit')
			);
		}

		if ( ! $converted && $skipped ) {
			return sprintf(
				/* translators: %s: format */
				__( 'Nothing to do in this batch: those images already have %s copies, or copies came out larger than the originals.', 'omninox-site-toolkit'),
				$format_label
			);
		}

		return sprintf(
			/* translators: 1: attachments, 2: format, 3: files written, 4: saved bytes */
			__( 'Converted %1$d images to %2$s across %3$d files, saving %4$s.', 'omninox-site-toolkit'),
			$converted,
			$format_label,
			$files,
			Utils::format_bytes( $saved )
		);
	}

	/* -------------------------------------------------------------- Compress */

	/**
	 * Compress originals toward the size target, keeping a restorable
	 * backup, and refresh converted copies.
	 *
	 * @param int $offset  Offset.
	 * @param int $batch   Batch size.
	 * @param int $quality Starting quality.
	 * @return array
	 */
	private function batch_compress( $offset, $batch, $quality ) {
		$batch     = max( 1, min( 25, $batch ) );
		$quality   = max( self::MIN_QUALITY, min( 95, $quality ) );
		$selection = array_map( 'absint', (array) ( $this->selection ?? array() ) );
		$ids       = $this->convertible_ids( $offset, $batch, '_omninox_compressed', $selection, true );

		$compressed  = 0;
		$reconverted = 0;
		$hit_target  = 0;
		$skipped     = 0;
		$failed      = 0;
		$saved       = 0;
		$errors      = array();

		foreach ( $ids as $id ) {
			$path = get_attached_file( $id );

			if ( ! $path || ! file_exists( $path ) ) {
				++$skipped;
				continue;
			}

			clearstatcache( true, $path );
			$before = filesize( $path );

			if ( $before <= self::TARGET_BYTES ) {
				update_post_meta( $id, '_omninox_compressed', array( 'before' => $before, 'after' => $before, 'at' => current_time( 'mysql' ), 'skipped' => 'under target' ) );
				++$skipped;
				continue;
			}

			$mime = $this->mime_of( $path );

			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
				update_post_meta( $id, '_omninox_compressed', array( 'before' => $before, 'after' => $before, 'at' => current_time( 'mysql' ), 'skipped' => 'unsupported type' ) );
				++$skipped;
				continue;
			}

			$backup = $this->get_media_backup_path( $id, $path );

			if ( ! file_exists( $backup ) && ! @copy( $path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				++$failed;

				if ( count( $errors ) < 5 ) {
					$errors[] = array( 'id' => $id, 'error' => __( 'The plugin backup folder inside uploads is not writable, so no backup could be made. The file was left untouched.', 'omninox-site-toolkit' ) );
				}

				continue;
			}

			$attempt = $this->compress_to_target( $path, $mime, $before, $quality );

			if ( is_wp_error( $attempt ) ) {
				++$failed;

				if ( count( $errors ) < 5 ) {
					$errors[] = array( 'id' => $id, 'error' => $attempt->get_error_message() );
				}

				continue;
			}

			if ( empty( $attempt['path'] ) ) {
				update_post_meta( $id, '_omninox_compressed', array( 'before' => $before, 'after' => $before, 'at' => current_time( 'mysql' ), 'skipped' => 'already optimal' ) );
				++$skipped;
				continue;
			}

			if ( ! @rename( $attempt['path'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $attempt['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				++$failed;
				continue;
			}

			clearstatcache( true, $path );
			$after  = filesize( $path );
			$saved += max( 0, $before - $after );
			++$compressed;

			if ( $after <= self::TARGET_BYTES ) {
				++$hit_target;
			}

			update_post_meta(
				$id,
				'_omninox_compressed',
				array(
					'before'  => $before,
					'after'   => $after,
					'quality' => $attempt['quality'],
					'target'  => $after <= self::TARGET_BYTES ? 'reached' : 'floor reached first',
					'at'      => current_time( 'mysql' ),
				)
			);

			if ( $this->regenerate_converted_after_change( $id ) ) {
				++$reconverted;
			}
		}

		$processed = $offset + count( $ids );
		$pending   = $this->pending_count( '_omninox_compressed', true );
		$progress  = $this->progress();

		if ( $failed && ! $compressed ) {
			$message = sprintf(
				/* translators: %s: error message */
				__( 'Nothing was compressed. The first error was: %s', 'omninox-site-toolkit'),
				$errors ? $errors[0]['error'] : __( 'unknown', 'omninox-site-toolkit')
			);
		} elseif ( ! $compressed && $skipped ) {
			$message = sprintf(
				/* translators: %s: size target */
				__( 'Nothing to do in this batch: those files are already at or under %s, or cannot shrink further without visible quality loss.', 'omninox-site-toolkit'),
				size_format( self::TARGET_BYTES )
			);
		} else {
			$message = sprintf(
				/* translators: 1: number compressed, 2: number that reached target, 3: size target, 4: saved bytes, 5: refreshed copies */
				__( 'Compressed %1$d images (%2$d now at or under %3$s), saving %4$s. Refreshed converted copies of %5$d of them.', 'omninox-site-toolkit'),
				$compressed,
				$hit_target,
				size_format( self::TARGET_BYTES ),
				Utils::format_bytes( $saved ),
				$reconverted
			);
		}

		return array(
			'compressed'  => $compressed,
			'hit_target'  => $hit_target,
			'reconverted' => $reconverted,
			'skipped'     => $skipped,
			'failed'      => $failed,
			'errors'      => $errors,
			'offset'      => $processed,
			'total'       => $progress['images'],
			'remaining'   => $pending,
			'done'        => empty( $ids ) || ! empty( $selection ),
			'saved'       => Utils::format_bytes( $saved ),
			'message'     => $message,
		);
	}

	/**
	 * Re-encode one file at descending qualities until it fits the target or floor.
	 *
	 * @param string $path    Source path.
	 * @param string $mime    Detected mime type.
	 * @param int    $before  Original size in bytes.
	 * @param int    $start_q Top of the quality ladder.
	 * @return array|\WP_Error
	 */
	private function compress_to_target( $path, $mime, $before, $start_q ) {
		$best_path    = '';
		$best_size    = $before;
		$best_quality = 0;

		$ladder = array_values(
			array_filter(
				self::QUALITY_LADDER,
				static function ( $step ) use ( $start_q ) {
					return $step <= $start_q;
				}
			)
		);

		if ( empty( $ladder ) ) {
			$ladder = array( max( self::MIN_QUALITY, $start_q ) );
		}

		foreach ( $ladder as $step ) {
			$editor = wp_get_image_editor( $path );

			if ( is_wp_error( $editor ) ) {
				return $editor;
			}

			$editor->set_quality( $step );

			$temp   = $path . '.omninox-q' . $step;
			$result = $editor->save( $temp, $mime );

			if ( is_wp_error( $result ) ) {
				@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

				if ( $best_path ) {
					break;
				}

				return $result;
			}

			$written = isset( $result['path'] ) ? $result['path'] : $temp;

			clearstatcache( true, $written );
			$size = file_exists( $written ) ? filesize( $written ) : 0;

			if ( $size && $size < $best_size ) {
				if ( $best_path && $best_path !== $written ) {
					@unlink( $best_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}

				$best_path    = $written;
				$best_size    = $size;
				$best_quality = $step;

				if ( $size <= self::TARGET_BYTES ) {
					break;
				}
			} else {
				@unlink( $written ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

				if ( in_array( $mime, array( 'image/png', 'image/gif' ), true ) ) {
					break;
				}
			}
		}

		return array(
			'path'    => $best_path,
			'quality' => $best_quality,
			'size'    => $best_size,
		);
	}

	/**
	 * Refresh converted copies after a source file changes.
	 *
	 * @param int $id Attachment id.
	 * @return bool
	 */
	private function regenerate_converted_after_change( $id ) {
		$original = get_attached_file( $id );

		if ( ! $original ) {
			return false;
		}

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );

		// Clean WebP.
		$webp = (array) get_post_meta( $id, '_omninox_webp', true );
		foreach ( (array) ( $webp['files'] ?? array() ) as $rel ) {
			$stale = $basedir . ltrim( $rel, '/' );
			if ( file_exists( $stale ) ) {
				@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		delete_post_meta( $id, '_omninox_webp' );

		// Clean AVIF.
		$avif = (array) get_post_meta( $id, '_omninox_avif', true );
		foreach ( (array) ( $avif['files'] ?? array() ) as $rel ) {
			$stale = $basedir . ltrim( $rel, '/' );
			if ( file_exists( $stale ) ) {
				@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		delete_post_meta( $id, '_omninox_avif' );

		$format  = Settings::instance()->get( 'conversion_format', 'webp' );
		$outcome = $this->convert_attachment( $id, $format );

		return ! is_wp_error( $outcome ) && ! empty( $outcome['files'] );
	}

	/* ------------------------------------------------------------- Housekeep */

	/**
	 * Rebuild missing intermediate sizes.
	 *
	 * @param int $offset Offset.
	 * @param int $batch  Batch size.
	 * @return array
	 */
	private function batch_regenerate( $offset, $batch ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$batch = max( 1, min( 25, $batch ) );

		$ids = ! empty( $this->selection )
			? array_map( 'absint', $this->selection )
			: get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

		$done   = 0;
		$failed = 0;

		foreach ( $ids as $id ) {
			$path = get_attached_file( $id );

			if ( ! $path || ! file_exists( $path ) ) {
				++$failed;
				continue;
			}

			$meta = wp_generate_attachment_metadata( $id, $path );

			if ( ! is_wp_error( $meta ) && $meta ) {
				wp_update_attachment_metadata( $id, $meta );
				++$done;
			} else {
				++$failed;
			}
		}

		$processed = $offset + count( $ids );
		$progress  = $this->progress();

		return array(
			'regenerated' => $done,
			'failed'      => $failed,
			'offset'      => $processed,
			'total'       => $progress['images'],
			'remaining'   => max( 0, $progress['images'] - $processed ),
			'done'        => count( $ids ) < $batch || ! empty( $this->selection ),
			/* translators: %d: number of images */
			'message'     => sprintf( _n( 'Regenerated %d image.', 'Regenerated %d images.', $done, 'omninox-site-toolkit'), $done ),
		);
	}

	/**
	 * Derive alt text from the filename where it is empty.
	 *
	 * @return array
	 */
	private function fill_alt_text() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				),
			)
		);

		$updated = 0;

		foreach ( $ids as $id ) {
			$title = get_the_title( $id );
			$alt   = ucfirst( trim( preg_replace( '/[-_]+/', ' ', (string) $title ) ) );

			if ( '' === $alt ) {
				continue;
			}

			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			++$updated;
		}

		return array(
			'updated' => $updated,
			/* translators: %d: number of images */
			'message' => sprintf( __( 'Filled alt text on %d images from their titles.', 'omninox-site-toolkit' ), $updated ),
		);
	}
	/**
	 * Get the dedicated backup path for an attachment inside the uploads/omninox-site-toolkit/media-backups folder.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $path Original file path.
	 * @return string
	 */
	private function get_media_backup_path( $id, $path ) {
		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'omninox-site-toolkit/media-backups';
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
			$index_file = $backup_dir . '/index.php';
			if ( ! file_exists( $index_file ) ) {
				@file_put_contents( $index_file, '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$ext = pathinfo( $path, PATHINFO_EXTENSION );
		return $backup_dir . '/' . absint( $id ) . '-' . md5( $path ) . '.' . ( $ext ? $ext : 'img' ) . '.backup';
	}

	/**
	 * Put back every backup from plugin storage.
	 *
	 * @return array
	 */
	private function restore_originals() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'meta_key'       => '_omninox_compressed', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$restored = 0;

		foreach ( $ids as $id ) {
			$path   = get_attached_file( $id );
			$backup = $this->get_media_backup_path( $id, $path );

			if ( ! file_exists( $backup ) && file_exists( $path . '.original' ) ) {
				$backup = $path . '.original';
			}

			if ( $path && file_exists( $backup ) && copy( $backup, $path ) ) {
				@unlink( $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				delete_post_meta( $id, '_omninox_compressed' );
				$this->refresh_metadata( $id, $path );
				++$restored;
			}
		}

		return array(
			'restored' => $restored,
			/* translators: %d: number of images */
			'message'  => sprintf( __( 'Restored %d images from their backups.', 'omninox-site-toolkit' ), $restored ),
		);
	}

	/**
	 * Build an exact old-URL to new-URL map for every image that was actually converted.
	 *
	 * @param string $format webp|avif.
	 * @return array
	 */
	private function export_conversion_map( $format = 'webp' ) {
		$meta_key = '_omninox_' . $format;
		$ids      = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
				'post_status'    => 'inherit',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_omninox_replaced',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', $ids );
		}

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );
		$baseurl = trailingslashit( $uploads['baseurl'] );
		$pairs   = array();

		foreach ( $ids as $id ) {
			$record = (array) get_post_meta( $id, $meta_key, true );

			if ( empty( $record['files'] ) ) {
				continue;
			}

			$original_file = get_post_meta( $id, '_wp_attached_file', true );
			if ( ! $original_file ) {
				continue;
			}

			$metadata   = wp_get_attachment_metadata( $id );
			$dir        = trailingslashit( dirname( $original_file ) );
			$candidates = array(
				array( 'key' => '_main', 'file' => $original_file ),
			);

			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size_name => $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$candidates[] = array( 'key' => $size_name, 'file' => $dir . $size_info['file'] );
				}
			}

			foreach ( (array) $record['files'] as $conv_relative ) {
				$conv_stem = preg_replace( '/\.(webp|avif)$/i', '', $conv_relative );

				foreach ( $candidates as $candidate ) {
					$candidate_rel  = $candidate['file'];
					$candidate_stem = preg_replace( '/\.(jpe?g|png|gif)$/i', '', $candidate_rel );

					if ( $candidate_stem !== $conv_stem ) {
						continue;
					}

					// Verify both files actually exist on disk right now.
					if ( file_exists( $basedir . $candidate_rel ) && file_exists( $basedir . $conv_relative ) ) {
						$pairs[] = array(
							'attachment_id' => $id,
							'size_key'      => $candidate['key'],
							'old_rel'       => $candidate_rel,
							'new_rel'       => $conv_relative,
							'old'           => $baseurl . $candidate_rel,
							'new'           => $baseurl . $conv_relative,
							'format'        => $format,
						);
					}

					break;
				}
			}
		}

		return array(
			'count'   => count( $pairs ),
			'pairs'   => $pairs,
			'format'  => $format,
			'message' => sprintf(
				/* translators: %d: count */
				__( 'Found %d confirmed conversion URL pairs.', 'omninox-site-toolkit'),
				count( $pairs )
			),
		);
	}

	/**
	 * Rewrite every reference to a converted image so it points at the new 	 * High-speed, memory-safe, timeout-resilient batch processing:
	 * - Queries only the current attachment batch (25-50 attachments) instead of rescanning entire library.
	 * - Dynamic execution time budget prevents PHP max_execution_time and 502/504 gateway timeouts.
	 * - Memory limit guard (75% threshold) prevents Out of Memory crashes on low-memory servers (e.g. 128M).
	 * - Multi-pattern string replacement using strtr in memory protects serialized postmeta/options.
	 * - Batched SQL queries (chunks of 30 terms) replace N+1 full table scans with fast indexed lookups.
	 *
	 * @param int    $offset Row offset.
	 * @param int    $batch  Batch size.
	 * @param string $format webp|avif.
	 * @return array
	 */
	private function replace_originals_with_converted( $offset, $batch, $format = 'webp' ) {
		global $wpdb;

		$start_time = microtime( true );

		// 1. Time Budget Guard: leave safety margin to avoid PHP max_execution_time or Nginx 502 timeout.
		$max_time    = (int) ini_get( 'max_execution_time' );
		$time_budget = ( $max_time > 0 ) ? max( 6, min( 22, $max_time - 4 ) ) : 18;

		// 2. Memory Limit Guard: stop batch before reaching PHP memory_limit (e.g. 128M/256M).
		$mem_limit_str  = ini_get( 'memory_limit' );
		$mem_limit      = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $mem_limit_str ) : 134217728;
		$safe_mem_limit = ( $mem_limit > 0 ) ? (int) ( $mem_limit * 0.75 ) : 0;

		$meta_key = '_omninox_' . $format;

		// 3. Determine remaining attachments that still need replacement.
		$total_remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
				LEFT JOIN {$wpdb->postmeta} pm_rep ON (p.ID = pm_rep.post_id AND pm_rep.meta_key = '_omninox_replaced')
				WHERE p.post_type = 'attachment' AND pm_rep.meta_id IS NULL",
				$meta_key
			)
		);

		if ( 0 === $total_remaining ) {
			return array(
				'done'         => true,
				'offset'       => $offset,
				'total'        => $offset,
				'remaining'    => 0,
				'rows_changed' => 0,
				'pairs_done'   => 0,
				'message'      => __( 'All converted images have already been replaced.', 'omninox-site-toolkit' ),
			);
		}

		// 4. Fetch the next batch of attachment IDs to process (default 25 attachments).
		$batch_limit    = max( 10, min( 100, $batch ) );
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
				LEFT JOIN {$wpdb->postmeta} pm_rep ON (p.ID = pm_rep.post_id AND pm_rep.meta_key = '_omninox_replaced')
				WHERE p.post_type = 'attachment' AND pm_rep.meta_id IS NULL
				ORDER BY p.ID ASC
				LIMIT %d",
				$meta_key,
				$batch_limit
			)
		);

		if ( empty( $attachment_ids ) ) {
			return array(
				'done'         => true,
				'offset'       => $offset,
				'total'        => $offset,
				'remaining'    => 0,
				'rows_changed' => 0,
				'pairs_done'   => 0,
				'message'      => __( 'No remaining image attachments found to replace.', 'omninox-site-toolkit' ),
			);
		}

		// Pre-populate postmeta cache for all IDs in this batch in a single query.
		update_meta_cache( 'post', $attachment_ids );

		$uploads   = wp_upload_dir();
		$basedir   = trailingslashit( $uploads['basedir'] );
		$baseurl   = trailingslashit( $uploads['baseurl'] );
		$base_path = wp_parse_url( $baseurl, PHP_URL_PATH );

		$pairs         = array();
		$grouped       = array();
		$processed_ids = array();

		foreach ( $attachment_ids as $att_id ) {
			// Check guards inside loop
			if ( ( microtime( true ) - $start_time ) > $time_budget ) {
				break;
			}
			if ( $safe_mem_limit > 0 && memory_get_usage() > $safe_mem_limit ) {
				break;
			}

			$processed_ids[] = $att_id;
			$record          = (array) get_post_meta( $att_id, $meta_key, true );
			$original_file   = get_post_meta( $att_id, '_wp_attached_file', true );

			if ( empty( $record['files'] ) || empty( $original_file ) ) {
				// Mark as skipped so it doesn't block the queue
				update_post_meta( $att_id, '_omninox_replaced', array( 'skipped' => true, 'format' => $format, 'replaced' => time() ) );
				continue;
			}

			$metadata   = wp_get_attachment_metadata( $att_id );
			$dir        = trailingslashit( dirname( $original_file ) );
			$candidates = array(
				array( 'key' => '_main', 'file' => $original_file ),
			);

			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size_name => $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$candidates[] = array( 'key' => $size_name, 'file' => $dir . $size_info['file'] );
				}
			}

			$att_pairs = array();
			foreach ( (array) $record['files'] as $conv_relative ) {
				$conv_stem = preg_replace( '/\.(webp|avif)$/i', '', $conv_relative );

				foreach ( $candidates as $candidate ) {
					$candidate_rel  = $candidate['file'];
					$candidate_stem = preg_replace( '/\.(jpe?g|png|gif)$/i', '', $candidate_rel );

					if ( $candidate_stem !== $conv_stem ) {
						continue;
					}

					// Verify both files actually exist on disk right now
					if ( file_exists( $basedir . $candidate_rel ) && file_exists( $basedir . $conv_relative ) ) {
						$pair = array(
							'attachment_id' => $att_id,
							'size_key'      => $candidate['key'],
							'old_rel'       => $candidate_rel,
							'new_rel'       => $conv_relative,
							'old'           => $baseurl . $candidate_rel,
							'new'           => $baseurl . $conv_relative,
							'format'        => $format,
						);
						$pairs[]     = $pair;
						$att_pairs[] = $pair;
					}
					break;
				}
			}

			if ( ! empty( $att_pairs ) ) {
				$grouped[ $att_id ] = $att_pairs;
			} else {
				// Mark as skipped if no matching conversion files physically exist on disk
				update_post_meta( $att_id, '_omninox_replaced', array( 'skipped' => true, 'format' => $format, 'replaced' => time() ) );
			}
		}

		$rows_changed   = 0;
		$fields_changed = count( $pairs );

		if ( ! empty( $pairs ) ) {
			// Build high-speed multi-pattern search & replace map
			$search_map = array();
			$like_terms = array();

			foreach ( $pairs as $pair ) {
				$old = $pair['old'];
				$new = $pair['new'];
				$search_map[ $old ] = $new;

				// Relative paths
				$old_rel = $pair['old_rel'];
				if ( ! empty( $old_rel ) ) {
					$like_terms[] = basename( $old_rel );
					$search_map[ $old_rel ] = $pair['new_rel'];

					if ( $base_path ) {
						$rel_old = trailingslashit( $base_path ) . $old_rel;
						$rel_new = trailingslashit( $base_path ) . $pair['new_rel'];
						$search_map[ $rel_old ] = $rel_new;
					}
				}

				// Port-less URL variation (e.g. test.local:10010 vs test.local)
				$parsed = wp_parse_url( $old );
				if ( ! empty( $parsed['port'] ) ) {
					$scheme       = $parsed['scheme'] ?? 'http';
					$host_no_port = $parsed['host'];
					$path_query   = ( $parsed['path'] ?? '' ) . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
					$old_no_port  = $scheme . '://' . $host_no_port . $path_query;
					$new_no_port  = str_replace( ':' . $parsed['port'], '', $new );
					$search_map[ $old_no_port ] = $new_no_port;
				}
			}

			// Sort search_map keys by length descending to match longest URLs first
			uksort( $search_map, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );

			$like_terms = array_values( array_unique( array_filter( $like_terms ) ) );

			$tables_to_scan = array(
				$wpdb->posts    => array( 'ID', array( 'post_content', 'post_excerpt' ) ),
				$wpdb->postmeta => array( 'meta_id', array( 'meta_value' ) ),
				$wpdb->options  => array( 'option_id', array( 'option_value' ) ),
			);

			if ( ! empty( $like_terms ) && ! empty( $search_map ) ) {
				foreach ( $tables_to_scan as $table => $config ) {
					list( $id_column, $columns ) = $config;

					foreach ( $columns as $column ) {
						// Check limits before querying each column
						if ( ( microtime( true ) - $start_time ) > $time_budget ) {
							break 2;
						}
						if ( $safe_mem_limit > 0 && memory_get_usage() > $safe_mem_limit ) {
							break 2;
						}

						$term_chunks = array_chunk( $like_terms, 30 );

						foreach ( $term_chunks as $terms ) {
							$clauses = array();
							$params  = array();

							foreach ( $terms as $term ) {
								$clauses[] = "{$column} LIKE %s";
								$params[]  = '%' . $wpdb->esc_like( $term ) . '%';
							}

							$where_sql = implode( ' OR ', $clauses );
							// Limit fetched rows per chunk to avoid buffering huge result sets into memory
							$query     = $wpdb->prepare(
								"SELECT {$id_column} AS row_id, {$column} AS value FROM {$table} WHERE {$where_sql} LIMIT 250", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedTableName
								$params
							);

							$rows = $wpdb->get_results( $query );

							if ( empty( $rows ) ) {
								continue;
							}

							foreach ( $rows as $row ) {
								$val = $this->safe_replace_map( $search_map, $row->value );

								if ( $val !== $row->value ) {
									$wpdb->update(
										$table,
										array( $column => $val ),
										array( $id_column => $row->row_id )
									);
									++$rows_changed;
								}
							}

							// Free memory from results immediately
							unset( $rows );
						}
					}
				}
			}

			// Clean SAVEQUERIES memory bloat if enabled
			if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
				$wpdb->queries = array();
			}

			// Update attachment canonical records and intermediate sizes metadata
			foreach ( $grouped as $att_id => $att_pairs ) {
				$this->update_attachment_records_for_pairs( $att_id, $att_pairs, $format );
			}
		}

		$remaining = max( 0, $total_remaining - count( $processed_ids ) );
		$processed = $offset + count( $processed_ids );
		$is_done   = ( $remaining <= 0 );

		return array(
			'done'         => $is_done,
			'offset'       => $processed,
			'total'        => $processed + $remaining,
			'remaining'    => $remaining,
			'rows_changed' => $rows_changed,
			'pairs_done'   => $fields_changed,
			'message'      => $is_done
				? sprintf(
					/* translators: 1: attachments, 2: rows */
					__( 'Done: replaced originals for %1$d image attachments (%2$d database rows updated). Originals moved to 30-day holding area.', 'omninox-site-toolkit' ),
					$processed,
					$rows_changed
				)
				: sprintf(
					/* translators: 1: attachments, 2: remaining */
					__( 'Replaced %1$d image attachments so far, %2$d remaining…', 'omninox-site-toolkit' ),
					$processed,
					$remaining
				),
		);
	}

	/**
	 * Update attachment canonical record and intermediate metadata for replaced pairs.
	 *
	 * @param int    $id     Attachment id.
	 * @param array  $pairs  List of replacement pairs for this attachment.
	 * @param string $format webp|avif.
	 */
	private function update_attachment_records_for_pairs( $id, array $pairs, $format = 'webp' ) {
		$uploads  = wp_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$holding  = $this->media_backup_dir();
		$metadata = wp_get_attachment_metadata( $id );
		$replaced = (array) get_post_meta( $id, '_omninox_replaced', true );

		if ( empty( $replaced['sizes'] ) ) {
			$replaced['sizes'] = array();
		}

		$main_old_rel = '';
		$main_held_at = '';

		foreach ( $pairs as $pair ) {
			$old_rel = $pair['old_rel'];
			$new_rel = $pair['new_rel'];
			$source  = $basedir . $old_rel;
			$target  = trailingslashit( $holding ) . $old_rel;

			if ( file_exists( $source ) ) {
				wp_mkdir_p( dirname( $target ) );
				if ( @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( '_main' === $pair['size_key'] ) {
						$main_old_rel = $old_rel;
						$main_held_at = $this->relative( $target, $uploads['basedir'] );
					} else {
						$replaced['sizes'][ $pair['size_key'] ] = array(
							'old_file' => basename( $old_rel ),
							'held_at'  => $this->relative( $target, $uploads['basedir'] ),
						);
					}
				}
			}

			if ( '_main' === $pair['size_key'] ) {
				update_post_meta( $id, '_wp_attached_file', $new_rel );

				if ( isset( $metadata['file'] ) ) {
					$metadata['file'] = $new_rel;
				}

				wp_update_post(
					array(
						'ID'             => $id,
						'post_mime_type' => 'image/' . $format,
					)
				);
			} elseif ( isset( $metadata['sizes'][ $pair['size_key'] ] ) ) {
				$metadata['sizes'][ $pair['size_key'] ]['file']      = basename( $new_rel );
				$metadata['sizes'][ $pair['size_key'] ]['mime-type'] = 'image/' . $format;
			}
		}

		if ( $metadata ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		if ( $main_old_rel ) {
			$replaced['old_rel']  = $main_old_rel;
			$replaced['held_at']  = $main_held_at;
			$replaced['format']   = $format;
			$replaced['replaced'] = time();
		}

		update_post_meta( $id, '_omninox_replaced', $replaced );

		// Clean up standalone duplicate library entry if one exists.
		$dup_key   = '_omninox_' . $format . '_attachment';
		$duplicate = (int) get_post_meta( $id, $dup_key, true );

		if ( $duplicate ) {
			delete_post_meta( $duplicate, '_wp_attached_file' );
			wp_delete_post( $duplicate, true );
			delete_post_meta( $id, $dup_key );
		}
	}

	/**
	 * Safe deletion of converted files with 30-day backup expiration warning safeguard.
	 *
	 * @param array $params Request parameters.
	 * @return array|\WP_Error
	 */
	private function delete_conversions( array $params = array() ) {
		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );

		$format = sanitize_key( $params['format'] ?? 'both' );
		if ( ! in_array( $format, array( 'webp', 'avif', 'both' ), true ) ) {
			$format = 'both';
		}

		$delete_webp = ( 'webp' === $format || 'both' === $format );
		$delete_avif = ( 'avif' === $format || 'both' === $format );

		// Check for attachments whose originals were replaced.
		$replaced_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'meta_key'       => '_omninox_replaced', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$expired_count   = 0;
		$held_dir        = $this->media_backup_dir();
		$now             = time();
		$recoverable_ids = array();

		foreach ( $replaced_ids as $id ) {
			$rec     = (array) get_post_meta( $id, '_omninox_replaced', true );
			$rec_fmt = $rec['format'] ?? 'webp';

			if ( 'both' !== $format && $rec_fmt !== $format ) {
				continue;
			}

			$held_at = ! empty( $rec['held_at'] ) ? ( $basedir . ltrim( $rec['held_at'], '/' ) ) : '';
			$time    = (int) ( $rec['replaced'] ?? 0 );

			if ( ! $held_at || ! file_exists( $held_at ) || ( $time && ( $now - $time ) > 30 * DAY_IN_SECONDS ) ) {
				++$expired_count;
			} else {
				$recoverable_ids[] = $id;
			}
		}

		// Safeguard: If backups have expired and force is not provided, warn the user.
		$force = ! empty( $params['force'] );

		if ( $expired_count > 0 && ! $force ) {
			return new \WP_Error(
				'omninox_expired_backups_warning',
				sprintf(
					/* translators: %d: expired count */
					__( 'Warning: %d original images were replaced and their 30-day backup holding period has expired (original files are no longer on disk). Deleting converted files will cause permanent image loss for those media items. Please confirm if you wish to proceed regardless.', 'omninox-site-toolkit'),
					$expired_count
				),
				array( 'status' => 409, 'expired_count' => $expired_count )
			);
		}

		// Step 1: Restore recoverable originals first so attachments point back to original files.
		$restored_count = 0;
		if ( ! empty( $recoverable_ids ) ) {
			$restore_result = $this->restore_replaced_originals();
			$restored_count = $restore_result['restored'] ?? 0;
		}

		// Step 2: Unlink selected format files.
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
			)
		);

		$webp_removed        = 0;
		$avif_removed        = 0;
		$cleaned_attachments = 0;

		foreach ( $ids as $id ) {
			$had_conversion = false;

			// WebP
			if ( $delete_webp ) {
				$webp = (array) get_post_meta( $id, '_omninox_webp', true );
				if ( ! empty( $webp ) ) {
					$had_conversion = true;
				}
				foreach ( (array) ( $webp['files'] ?? array() ) as $rel ) {
					$path = $basedir . ltrim( $rel, '/' );
					if ( file_exists( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
						++$webp_removed;
					}
				}
				$webp_att = (int) get_post_meta( $id, '_omninox_webp_attachment', true );
				if ( $webp_att ) {
					wp_delete_attachment( $webp_att, true );
					delete_post_meta( $id, '_omninox_webp_attachment' );
				}
				delete_post_meta( $id, '_omninox_webp' );
			}

			// AVIF
			if ( $delete_avif ) {
				$avif = (array) get_post_meta( $id, '_omninox_avif', true );
				if ( ! empty( $avif ) ) {
					$had_conversion = true;
				}
				foreach ( (array) ( $avif['files'] ?? array() ) as $rel ) {
					$path = $basedir . ltrim( $rel, '/' );
					if ( file_exists( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
						++$avif_removed;
					}
				}
				$avif_att = (int) get_post_meta( $id, '_omninox_avif_attachment', true );
				if ( $avif_att ) {
					wp_delete_attachment( $avif_att, true );
					delete_post_meta( $id, '_omninox_avif_attachment' );
				}
				delete_post_meta( $id, '_omninox_avif' );
			}

			if ( $had_conversion ) {
				++$cleaned_attachments;
			}
		}

		// Also clean up any remaining converted files on disk
		if ( is_dir( $basedir ) ) {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $basedir, \RecursiveDirectoryIterator::SKIP_DOTS )
				);

				foreach ( $iterator as $file_info ) {
					if ( ! $file_info->isFile() ) {
						continue;
					}
					$ext = strtolower( $file_info->getExtension() );
					if ( $delete_webp && 'webp' === $ext ) {
						$file_path = $file_info->getPathname();
						if ( file_exists( $file_path ) && @unlink( $file_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
							++$webp_removed;
						}
					} elseif ( $delete_avif && 'avif' === $ext ) {
						$file_path = $file_info->getPathname();
						if ( file_exists( $file_path ) && @unlink( $file_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
							++$avif_removed;
						}
					}
				}
			} catch ( \Throwable $e ) {
				// Silently continue if directory iterator hits permission issues
			}
		}

		$total_removed = $webp_removed + $avif_removed;
		$format_label  = ( 'webp' === $format ) ? 'WebP' : ( ( 'avif' === $format ) ? 'AVIF' : 'WebP & AVIF' );

		$rows = array();
		$rows[] = array(
			'Format Target'        => $format_label,
			'WebP Files Deleted'   => $webp_removed,
			'AVIF Files Deleted'   => $avif_removed,
			'Total Files Removed'  => $total_removed,
			'Attachments Cleaned'  => $cleaned_attachments,
			'Originals Restored'   => $restored_count,
			'Status'               => $total_removed > 0
				? __( 'Deleted & Cleaned', 'omninox-site-toolkit')
				: __( 'Clean (No converted files remaining)', 'omninox-site-toolkit'),
		);

		return array(
			'module'         => 'media',
			'format'         => $format,
			'removed'        => $total_removed,
			'webp_removed'   => $webp_removed,
			'avif_removed'   => $avif_removed,
			'restored'       => $restored_count,
			'expired'        => $expired_count,
			'rows'           => $rows,
			/* translators: 1: removed count, 2: format label, 3: attachments count, 4: restored count */
			'message'        => sprintf(
				__( 'Cleanup complete: Deleted %1$d generated %2$s files across %3$d media attachments. %4$d originals restored.', 'omninox-site-toolkit'),
				$total_removed,
				$format_label,
				$cleaned_attachments,
				$restored_count
			),
		);
	}

	/**
	 * Put replaced originals back: file returned to uploads, attachment record restored.
	 *
	 * @return array
	 */
	private function restore_replaced_originals() {
		global $wpdb;

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'meta_key'       => '_omninox_replaced', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$uploads  = wp_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$baseurl  = trailingslashit( $uploads['baseurl'] );
		$restored = 0;
		$expired  = 0;

		$tables_to_scan = array(
			$wpdb->posts    => array( 'ID', array( 'post_content', 'post_excerpt' ) ),
			$wpdb->postmeta => array( 'meta_id', array( 'meta_value' ) ),
			$wpdb->options  => array( 'option_id', array( 'option_value' ) ),
		);

		$revert_map   = array();
		$revert_terms = array();

		foreach ( $ids as $id ) {
			$record = (array) get_post_meta( $id, '_omninox_replaced', true );

			if ( empty( $record['old_rel'] ) || empty( $record['held_at'] ) ) {
				continue;
			}

			$held = $basedir . ltrim( $record['held_at'], '/' );
			$dest = $basedir . ltrim( $record['old_rel'], '/' );

			if ( ! file_exists( $held ) ) {
				++$expired;
				delete_post_meta( $id, '_omninox_replaced' );
				continue;
			}

			wp_mkdir_p( dirname( $dest ) );

			if ( ! @rename( $held, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}

			// Restore main attached file.
			update_post_meta( $id, '_wp_attached_file', $record['old_rel'] );

			$metadata = wp_get_attachment_metadata( $id );
			if ( $metadata && isset( $metadata['file'] ) ) {
				$metadata['file'] = $record['old_rel'];
			}

			// Restore intermediate size files.
			foreach ( (array) ( $record['sizes'] ?? array() ) as $size_key => $size_backup ) {
				$size_held = $basedir . ltrim( $size_backup['held_at'], '/' );
				$size_dest = trailingslashit( dirname( $dest ) ) . $size_backup['old_file'];

				if ( file_exists( $size_held ) ) {
					@rename( $size_held, $size_dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}

				if ( isset( $metadata['sizes'][ $size_key ] ) ) {
					$metadata['sizes'][ $size_key ]['file']      = $size_backup['old_file'];
					$metadata['sizes'][ $size_key ]['mime-type'] = $this->mime_of( $size_dest );
				}

				$s_conv = trailingslashit( dirname( $record['old_rel'] ) ) . preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . ( $record['format'] ?? 'webp' ), $size_backup['old_file'] );
				$s_orig = trailingslashit( dirname( $record['old_rel'] ) ) . $size_backup['old_file'];
				$revert_map[ $baseurl . $s_conv ] = $baseurl . $s_orig;
				$revert_map[ $s_conv ]           = $s_orig;
				$revert_terms[]                  = basename( $s_conv );
			}

			if ( $metadata ) {
				wp_update_attachment_metadata( $id, $metadata );
			}

			$mime = $this->mime_of( $dest );
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $mime ? $mime : 'image/jpeg',
				)
			);

			// Revert DB URLs back from converted to original.
			$format   = $record['format'] ?? 'webp';
			$conv_rel = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $record['old_rel'] );
			$conv_url = $baseurl . $conv_rel;
			$orig_url = $baseurl . $record['old_rel'];

			$revert_map[ $conv_url ] = $orig_url;
			$revert_map[ $conv_rel ] = $record['old_rel'];
			$revert_terms[]          = basename( $conv_rel );

			delete_post_meta( $id, '_omninox_replaced' );
			++$restored;
		}

		if ( ! empty( $revert_map ) && ! empty( $revert_terms ) ) {
			uksort( $revert_map, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );

			$revert_terms = array_values( array_unique( array_filter( $revert_terms ) ) );

			foreach ( $tables_to_scan as $table => $config ) {
				list( $id_column, $columns ) = $config;

				foreach ( $columns as $column ) {
					$term_chunks = array_chunk( $revert_terms, 30 );

					foreach ( $term_chunks as $terms ) {
						$clauses = array();
						$params  = array();

						foreach ( $terms as $term ) {
							$clauses[] = "{$column} LIKE %s";
							$params[]  = '%' . $wpdb->esc_like( $term ) . '%';
						}

						$where_sql = implode( ' OR ', $clauses );
						$query     = $wpdb->prepare(
							"SELECT {$id_column} AS row_id, {$column} AS value FROM {$table} WHERE {$where_sql} LIMIT 250", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedTableName
							$params
						);

						$rows = $wpdb->get_results( $query );

						if ( empty( $rows ) ) {
							continue;
						}

						foreach ( $rows as $row ) {
							$val = $this->safe_replace_map( $revert_map, $row->value );

							if ( $val !== $row->value ) {
								$wpdb->update(
									$table,
									array( $column => $val ),
									array( $id_column => $row->row_id )
								);
							}
						}

						unset( $rows );
					}
				}
			}
		}

		return array(
			'restored' => $restored,
			'expired'  => $expired,
			'message'  => $expired
				/* translators: 1: restored, 2: expired */
				? sprintf( __( 'Restored %1$d originals. %2$d could not be restored because their 30-day backup window had passed.', 'omninox-site-toolkit'), $restored, $expired )
				/* translators: %d: restored */
				: sprintf( __( 'Restored %d originals to the Media Library and reverted database URLs.', 'omninox-site-toolkit'), $restored ),
		);
	}

	/* --------------------------------------------------------------- Serving */

	/**
	 * Swap a single URL for its AVIF/WebP copy when one exists.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private function swap_url( $url ) {
		if ( ! $url || is_admin() ) {
			return $url;
		}

		$uploads = wp_upload_dir();

		if ( false === strpos( $url, $uploads['baseurl'] ) ) {
			return $url;
		}

		$clean = strtok( $url, '?' );
		$path  = str_replace( $uploads['baseurl'], $uploads['basedir'], $clean );

		// Check AVIF first for superior compression.
		if ( $this->browser_accepts_format( 'avif' ) ) {
			$avif = $this->avif_path( $path );
			if ( file_exists( $avif ) ) {
				return str_replace( $uploads['basedir'], $uploads['baseurl'], $avif );
			}
		}

		// Fall back to WebP.
		if ( $this->browser_accepts_format( 'webp' ) ) {
			$webp = $this->webp_path( $path );
			if ( file_exists( $webp ) ) {
				return str_replace( $uploads['basedir'], $uploads['baseurl'], $webp );
			}
		}

		return $url;
	}

	/**
	 * Filter the src returned for attachment images.
	 *
	 * @param array|false $image Image data.
	 * @return array|false
	 */
	public function filter_image_src( $image ) {
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$image[0] = $this->swap_url( $image[0] );
		}

		return $image;
	}

	/**
	 * Filter every candidate in a srcset.
	 *
	 * @param array $sources Srcset sources.
	 * @return array
	 */
	public function filter_srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$sources[ $width ]['url'] = $this->swap_url( $source['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Filter a single content image tag.
	 *
	 * @param string $tag Image tag.
	 * @return string
	 */
	public function filter_content_img_tag( $tag ) {
		return $this->filter_content_images( $tag );
	}

	/**
	 * Rewrite upload URLs inside arbitrary markup.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	public function filter_content_images( $html ) {
		if ( ! is_string( $html ) || '' === $html || is_admin() || is_feed() ) {
			return $html;
		}

		if ( ! $this->browser_accepts_format( 'webp' ) && ! $this->browser_accepts_format( 'avif' ) ) {
			return $html;
		}

		$uploads = wp_upload_dir();
		$pattern = '#' . preg_quote( $uploads['baseurl'], '#' ) . '[^\s"\'>]+\.(?:jpe?g|png|gif)#i';
		$self    = $this;

		$result = preg_replace_callback(
			$pattern,
			static function ( $matches ) use ( $self ) {
				return $self->public_swap_url( $matches[0] );
			},
			$html
		);

		return null === $result ? $html : $result;
	}

	/**
	 * Callback-safe wrapper around URL swap.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public function public_swap_url( $url ) {
		return esc_url( $this->swap_url( $url ) );
	}

	/**
	 * Does the current request accept the given format?
	 *
	 * @param string $format avif|webp.
	 * @return bool
	 */
	private function browser_accepts_format( $format = 'webp' ) {
		if ( is_admin() ) {
			return false;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) : '';

		return false !== strpos( $accept, 'image/' . $format );
	}

	/* --------------------------------------------------------------- Helpers */

	/**
	 * WebP path for a source image.
	 *
	 * @param string $path Source path.
	 * @return string
	 */
	private function webp_path( $path ) {
		$converted = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $path );

		return ( $converted && $converted !== $path ) ? $converted : $path . '.webp';
	}

	/**
	 * AVIF path for a source image.
	 *
	 * @param string $path Source path.
	 * @return string
	 */
	private function avif_path( $path ) {
		$converted = preg_replace( '/\.(jpe?g|png|gif)$/i', '.avif', $path );

		return ( $converted && $converted !== $path ) ? $converted : $path . '.avif';
	}

	/**
	 * Path relative to uploads base directory.
	 *
	 * @param string $path    Absolute path.
	 * @param string $basedir Uploads base directory.
	 * @return string
	 */
	private function relative( $path, $basedir ) {
		return ltrim( str_replace( trailingslashit( $basedir ), '', $path ), '/' );
	}

	/**
	 * Detect real mime type.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function mime_of( $path ) {
		if ( function_exists( 'wp_get_image_mime' ) ) {
			$mime = wp_get_image_mime( $path );
			if ( $mime ) {
				return $mime;
			}
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return isset( $info['mime'] ) ? $info['mime'] : '';
	}

	/**
	 * Write a WebP copy of a source image.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return true|\WP_Error
	 */
	private function write_webp( $source, $target ) {
		if ( ! is_writable( dirname( $target ) ) ) {
			return new \WP_Error(
				'omninox_not_writable',
				/* translators: %s: directory path */
				sprintf( __( 'The folder %s is not writable.', 'omninox-site-toolkit'), dirname( $target ) )
			);
		}

		$mime          = $this->mime_of( $source );
		$imagick_error = '';

		if ( class_exists( 'Imagick' ) ) {
			try {
				$image = new \Imagick();
				$image->readImage( $source );
				$image->setImageFormat( 'webp' );
				$image->setImageCompressionQuality( 82 );

				if ( 'image/png' === $mime ) {
					$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
					$image->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
				}

				$image->stripImage();
				$written = $image->writeImage( $target );
				$image->clear();
				$image->destroy();

				if ( $written ) {
					return true;
				}
			} catch ( \Exception $e ) {
				$imagick_error = $e->getMessage();
			}
		}

		if ( ! function_exists( 'imagewebp' ) ) {
			return new \WP_Error(
				'omninox_no_webp',
				$imagick_error
					? sprintf( /* translators: %s: error message */ __( 'Imagick failed (%s) and GD has no WebP support.', 'omninox-site-toolkit'), $imagick_error )
					: __( 'GD was compiled without WebP support on this server.', 'omninox-site-toolkit')
			);
		}

		if ( 'image/png' === $mime ) {
			$image = @imagecreatefrompng( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} elseif ( 'image/jpeg' === $mime ) {
			$image = @imagecreatefromjpeg( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} elseif ( 'image/gif' === $mime ) {
			$image = @imagecreatefromgif( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} else {
			return new \WP_Error( 'omninox_unsupported_type', __( 'Only JPEG, PNG and GIF files can be converted.', 'omninox-site-toolkit') );
		}

		if ( ! $image ) {
			return new \WP_Error( 'omninox_read_failed', __( 'The image could not be read.', 'omninox-site-toolkit') );
		}

		if ( in_array( $mime, array( 'image/png', 'image/gif' ), true ) ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		$ok = imagewebp( $image, $target, 82 );
		imagedestroy( $image );

		if ( ! $ok ) {
			return new \WP_Error( 'omninox_write_failed', __( 'The WebP copy could not be written.', 'omninox-site-toolkit') );
		}

		return true;
	}

	/**
	 * Write an AVIF copy of a source image.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return true|\WP_Error
	 */
	private function write_avif( $source, $target ) {
		if ( ! is_writable( dirname( $target ) ) ) {
			return new \WP_Error(
				'omninox_not_writable',
				/* translators: %s: directory path */
				sprintf( __( 'The folder %s is not writable.', 'omninox-site-toolkit'), dirname( $target ) )
			);
		}

		$mime          = $this->mime_of( $source );
		$imagick_error = '';

		if ( class_exists( 'Imagick' ) ) {
			try {
				$formats = array_map( 'strtoupper', (array) \Imagick::queryFormats() );
				if ( in_array( 'AVIF', $formats, true ) ) {
					$image = new \Imagick();
					$image->readImage( $source );
					$image->setImageFormat( 'avif' );
					$image->setImageCompressionQuality( 75 );

					if ( 'image/png' === $mime ) {
						$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
						$image->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
					}

					$image->stripImage();
					$written = $image->writeImage( $target );
					$image->clear();
					$image->destroy();

					if ( $written ) {
						return true;
					}
				}
			} catch ( \Exception $e ) {
				$imagick_error = $e->getMessage();
			}
		}

		if ( ! function_exists( 'imageavif' ) ) {
			return new \WP_Error(
				'omninox_no_avif',
				$imagick_error
					? sprintf( /* translators: %s: error message */ __( 'Imagick failed (%s) and GD has no AVIF support.', 'omninox-site-toolkit'), $imagick_error )
					: __( 'GD was compiled without AVIF support on this server.', 'omninox-site-toolkit')
			);
		}

		if ( 'image/png' === $mime ) {
			$image = @imagecreatefrompng( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} elseif ( 'image/jpeg' === $mime ) {
			$image = @imagecreatefromjpeg( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} elseif ( 'image/gif' === $mime ) {
			$image = @imagecreatefromgif( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} else {
			return new \WP_Error( 'omninox_unsupported_type', __( 'Only JPEG, PNG and GIF files can be converted.', 'omninox-site-toolkit') );
		}

		if ( ! $image ) {
			return new \WP_Error( 'omninox_read_failed', __( 'The image could not be read.', 'omninox-site-toolkit') );
		}

		if ( in_array( $mime, array( 'image/png', 'image/gif' ), true ) ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		$ok = imageavif( $image, $target, 75 );
		imagedestroy( $image );

		if ( ! $ok ) {
			return new \WP_Error( 'omninox_write_failed', __( 'The AVIF copy could not be written.', 'omninox-site-toolkit') );
		}

		return true;
	}

	/**
	 * What this server can actually do.
	 *
	 * @return array
	 */
	private function support() {
		$imagick = class_exists( 'Imagick' );
		$formats = array();

		if ( $imagick ) {
			try {
				$formats = array_map( 'strtoupper', (array) \Imagick::queryFormats() );
			} catch ( \Exception $e ) {
				$formats = array();
			}
		}

		$imagick_webp = $imagick && in_array( 'WEBP', $formats, true );
		$imagick_avif = $imagick && in_array( 'AVIF', $formats, true );
		$gd_webp      = function_exists( 'imagewebp' );
		$gd_avif      = function_exists( 'imageavif' );

		$parts = array();

		if ( $imagick_webp || $gd_webp ) {
			$parts[] = 'WebP (' . ( $imagick_webp ? 'Imagick' : 'GD' ) . ')';
		}

		if ( $imagick_avif || $gd_avif ) {
			$parts[] = 'AVIF (' . ( $imagick_avif ? 'Imagick' : 'GD' ) . ')';
		}

		return array(
			'imagick'      => $imagick,
			'imagick_webp' => $imagick_webp,
			'imagick_avif' => $imagick_avif,
			'gd'           => function_exists( 'gd_info' ),
			'gd_webp'      => $gd_webp,
			'gd_avif'      => $gd_avif,
			'webp'         => $imagick_webp || $gd_webp,
			'avif'         => $imagick_avif || $gd_avif,
			'memory_limit' => ini_get( 'memory_limit' ),
			'summary'      => $parts
				? sprintf(
					/* translators: %s: library formats */
					__( 'Supported: %s', 'omninox-site-toolkit'),
					implode( ', ', $parts )
				)
				: __( 'No modern image format support', 'omninox-site-toolkit'),
		);
	}

	/**
	 * Attachment ids eligible for conversion or compression.
	 *
	 * @param int    $offset    Offset.
	 * @param int    $batch     Batch size.
	 * @param string $meta_key  Meta marking completion.
	 * @param array  $ids       Explicit ids.
	 * @param bool   $all_types Include webp/avif for compression.
	 * @return array
	 */
	private function convertible_ids( $offset, $batch, $meta_key = '', $ids = array(), $all_types = false ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => $all_types
				? array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' )
				: array( 'image/jpeg', 'image/png', 'image/gif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => $batch,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( ! empty( $ids ) ) {
			$args['post__in']       = array_map( 'absint', $ids );
			$args['posts_per_page'] = count( $ids );

			return get_posts( $args );
		}

		if ( $meta_key ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => $meta_key, 'compare' => 'NOT EXISTS' ),
			);
		} else {
			$args['offset'] = $offset;
		}

		return get_posts( $args );
	}

	/**
	 * How many images still need each operation.
	 *
	 * @param string $meta_key  Meta marking completion.
	 * @param bool   $all_types All types flag.
	 * @return int
	 */
	private function pending_count( $meta_key, $all_types = false ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => $all_types
					? array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' )
					: array( 'image/jpeg', 'image/png', 'image/gif' ),
				'post_status'    => 'inherit',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => $meta_key, 'compare' => 'NOT EXISTS' ),
				),
			)
		);

		return count( $ids );
	}

	/**
	 * Library totals used to drive the progress bar.
	 *
	 * @return array
	 */
	private function progress() {
		$counts = (array) wp_count_attachments();
		$images = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		$total  = 0;

		foreach ( $images as $mime ) {
			$total += (int) ( $counts[ $mime ] ?? 0 );
		}

		return array(
			'images' => $total,
			'jpeg'   => (int) ( $counts['image/jpeg'] ?? 0 ),
			'png'    => (int) ( $counts['image/png'] ?? 0 ),
			'gif'    => (int) ( $counts['image/gif'] ?? 0 ),
			'webp'   => (int) ( $counts['image/webp'] ?? 0 ),
			'avif'   => (int) ( $counts['image/avif'] ?? 0 ),
		);
	}

	/**
	 * Attachments not referenced in content or as a thumbnail.
	 *
	 * @param array $ids Attachment ids.
	 * @return array
	 */
	private function unused_attachments( array $ids ) {
		global $wpdb;

		$unused = array();

		foreach ( array_slice( $ids, 0, 300 ) as $id ) {
			$url = wp_get_attachment_url( $id );

			if ( ! $url ) {
				continue;
			}

			$file = wp_basename( $url );

			$in_content = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
					'%' . $wpdb->esc_like( $file ) . '%'
				)
			);

			$as_thumb = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $id )
			);

			if ( 0 === $in_content && 0 === $as_thumb ) {
				$unused[] = array( 'id' => $id, 'file' => $file, 'url' => $url );
			}
		}

		return $unused;
	}

	/**
	 * High-speed multi-pattern replacement safe for both strings and serialized arrays/objects.
	 * Uses strtr for single-pass O(N) replacement and handles complex nested serialized objects.
	 *
	 * @param array $map Search-to-replace mapping array.
	 * @param mixed $value String or serialized data.
	 * @return mixed
	 */
	private function safe_replace_map( array $map, $value ) {
		if ( empty( $map ) || ! is_string( $value ) ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions

			if ( false === $data && 'b:0;' !== $value ) {
				return strtr( $value, $map );
			}

			$data = $this->recursive_replace_map( $map, $data );

			return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		return strtr( $value, $map );
	}

	/**
	 * Walk arrays/objects recursively, replacing strings using strtr mapping.
	 *
	 * @param array $map  Search-to-replace mapping array.
	 * @param mixed $data Value.
	 * @return mixed
	 */
	private function recursive_replace_map( array $map, $data ) {
		if ( is_string( $data ) ) {
			return strtr( $data, $map );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->recursive_replace_map( $map, $value );
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = $this->recursive_replace_map( $map, $value );
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Replace a string inside a value that may be serialized PHP data.
	 *
	 * @param string $search  String to find.
	 * @param string $replace Replacement.
	 * @param string $value   Raw DB value.
	 * @return string
	 */
	private function safe_str_replace( $search, $replace, $value ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions

			if ( false === $data && 'b:0;' !== $value ) {
				return str_replace( $search, $replace, $value );
			}

			$data = $this->recursive_replace( $search, $replace, $data );

			return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		return str_replace( $search, $replace, $value );
	}

	/**
	 * Walk arrays/objects recursively, replacing inside every string leaf.
	 *
	 * @param string $search  String to find.
	 * @param string $replace Replacement.
	 * @param mixed  $data    Value.
	 * @return mixed
	 */
	private function recursive_replace( $search, $replace, $data ) {
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->recursive_replace( $search, $replace, $value );
			}

			return $data;
		}

		if ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = $this->recursive_replace( $search, $replace, $value );
			}

			return $data;
		}

		return $data;
	}

	/**
	 * Rebuild sizes after a resize.
	 *
	 * @param int    $id   Attachment id.
	 * @param string $path File path.
	 */
	private function refresh_metadata( $id, $path ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$meta = wp_generate_attachment_metadata( $id, $path );

		if ( ! is_wp_error( $meta ) && $meta ) {
			wp_update_attachment_metadata( $id, $meta );
		}
	}

	/**
	 * Holding area for originals that were replaced by converted files.
	 *
	 * @return string
	 */
	private function media_backup_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'omninox-media-backups';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$dir . '/.htaccess',
				"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			);
		}

		return $dir;
	}

	/**
	 * Delete held originals older than 30 days.
	 */
	public function purge_replaced_backups() {
		$dir = $this->media_backup_dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$cutoff = time() - 30 * DAY_IN_SECONDS;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( $item->isFile() && ! in_array( $item->getFilename(), array( 'index.html', '.htaccess' ), true ) && $item->getMTime() < $cutoff ) {
					@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				} elseif ( $item->isDir() ) {
					@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
	}
}
