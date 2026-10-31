<?php
/** Original-format compression. No resizing, conversions or thumbnail regeneration. */
namespace OmninoxSiteToolkit\Modules\Media\Compression;

defined( 'ABSPATH' ) || exit;

class OriginalCompressor {

	public static function settings( array $input ) {
		return array(
			'target' => max( 1024, min( 10485760, (int) ( $input['target'] ?? 102400 ) ) ),
			'quality_floor' => max( 75, min( 95, (int) ( $input['quality_floor'] ?? 82 ) ) ),
			'strip_metadata' => 'yes' === ( $input['strip_metadata'] ?? 'no' ),
			'lossy_png' => 'yes' === ( $input['lossy_png'] ?? 'no' ),
			'keep_smaller' => 'no' !== ( $input['keep_smaller'] ?? 'yes' ),
			'backup' => 'no' !== ( $input['backup'] ?? 'yes' ),
			'retention_days' => max( 1, min( 3650, (int) ( $input['retention_days'] ?? 30 ) ) ),
			'force' => 'yes' === ( $input['force'] ?? 'no' ),
		);
	}

	public static function capabilities() {
		$im = class_exists( '\Imagick' );
		return array(
			'imagick_jpeg' => $im && count( \Imagick::queryFormats( 'JPEG' ) ) > 0,
			'imagick_png' => $im && count( \Imagick::queryFormats( 'PNG' ) ) > 0,
			'gd_jpeg' => function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' ),
			'gd_png' => function_exists( 'imagecreatefrompng' ) && function_exists( 'imagepng' ),
		);
	}

	/** Private storage must be secure. If system temp is restricted or deleted, fall back to protected plugin directory. */
	public static function storage() {
		$dir = defined( 'OMNINOX_COMPRESSION_STORAGE' ) ? OMNINOX_COMPRESSION_STORAGE : '';

		if ( empty( $dir ) ) {
			$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'omninox';
			$hash = substr( hash( 'sha256', ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . $salt ), 0, 24 );
			$temp = sys_get_temp_dir();
			$dir  = rtrim( $temp, '/\\' ) . '/omninox-compression-' . $hash;

			// Check if temp directory is usable and writable
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
				$dir = '';
			} elseif ( ! is_writable( $dir ) ) {
				$dir = '';
			}
		}

		// Fallback to protected storage under wp-content/uploads/omninox-site-toolkit/
		if ( empty( $dir ) ) {
			$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
			$basedir = ! empty( $uploads['basedir'] ) ? $uploads['basedir'] : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : '' );
			if ( empty( $basedir ) ) {
				throw new \RuntimeException( 'Private compression storage could not be determined.' );
			}

			$norm_base = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $basedir ) : str_replace( '\\', '/', $basedir );
			$dir = rtrim( $norm_base, '/' ) . '/omninox-site-toolkit/storage-' . substr( hash( 'sha256', ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . 'store' ), 0, 16 );
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
				throw new \RuntimeException( 'Private compression storage directory could not be created.' );
			}

			$htaccess = $dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				@file_put_contents( $htaccess, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
			}
			$index = $dir . '/index.html';
			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, '' );
			}
		}

		$real_dir = realpath( $dir ) ?: $dir;
		if ( ! is_writable( $real_dir ) ) {
			throw new \RuntimeException( 'Private compression storage is not writable.' );
		}
		return $real_dir;
	}

	public static function inside( $path, $root ) {
		if ( empty( $path ) || empty( $root ) ) {
			return false;
		}

		$real_root = realpath( $root );
		$real_path = realpath( $path );

		if ( $real_root && $real_path ) {
			$norm_root = rtrim( str_replace( '\\', '/', $real_root ), '/' );
			$norm_path = str_replace( '\\', '/', $real_path );
			if ( '\\' === DIRECTORY_SEPARATOR ) {
				$norm_root = strtolower( $norm_root );
				$norm_path = strtolower( $norm_path );
			}
			if ( $norm_path === $norm_root || 0 === strpos( $norm_path, $norm_root . '/' ) ) {
				return true;
			}
		}

		// Fallback check on unresolved normalized paths (handles symlinks where realpath differs)
		$norm_root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$norm_path = str_replace( '\\', '/', $path );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$norm_root = strtolower( $norm_root );
			$norm_path = strtolower( $norm_path );
		}
		return ( $norm_path === $norm_root || 0 === strpos( $norm_path, $norm_root . '/' ) );
	}

	public static function source( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			throw new \RuntimeException( 'Invalid attachment ID.' );
		}

		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : false;
		if ( ! $path || ! is_file( $path ) ) {
			$path = function_exists( 'get_attached_file' ) ? get_attached_file( $id ) : false;
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : '' );
		$basedir = ! empty( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		// If path is relative or not found, try resolving against uploads basedir
		if ( $path && ! file_exists( $path ) && ! empty( $basedir ) ) {
			$candidate = trailingslashit( $basedir ) . ltrim( $path, '/\\' );
			if ( file_exists( $candidate ) ) {
				$path = $candidate;
			}
		}

		// Also check _wp_attached_file postmeta if still not found
		if ( ( ! $path || ! file_exists( $path ) ) && function_exists( 'get_post_meta' ) ) {
			$meta_file = get_post_meta( $id, '_wp_attached_file', true );
			if ( $meta_file && ! empty( $basedir ) ) {
				$candidate = trailingslashit( $basedir ) . ltrim( $meta_file, '/\\' );
				if ( file_exists( $candidate ) ) {
					$path = $candidate;
				}
			}
		}

		if ( ! $path || ! is_file( $path ) ) {
			throw new \RuntimeException( 'Original image file does not exist on disk.' );
		}

		if ( ! empty( $basedir ) && ! self::inside( $path, $basedir ) ) {
			throw new \RuntimeException( 'Original image is outside the allowed uploads directory.' );
		}

		return realpath( $path ) ?: $path;
	}

	protected function temp( $path ) {
		$dir = dirname( $path );
		$temp = tempnam( $dir, '.onx-' );
		if ( ! $temp ) {
			throw new \RuntimeException( 'Could not create a temporary file beside the original.' );
		}
		$norm_temp_dir = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( dirname( $temp ) ) : str_replace( '\\', '/', dirname( $temp ) );
		$norm_orig_dir = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $dir ) : str_replace( '\\', '/', $dir );
		if ( $norm_temp_dir !== $norm_orig_dir && realpath( dirname( $temp ) ) !== realpath( $dir ) ) {
			unlink( $temp );
			throw new \RuntimeException( 'Could not create a temporary file beside the original.' );
		}
		return $temp;
	}

	protected function replace( $from, $to ) { return rename( $from, $to ); }
	protected function disk_free( $path ) { return disk_free_space( $path ); }
	protected function backup( $path, $destination ) { return copy( $path, $destination ); }

	/** Converts PHP IO/decoder warnings into observable failures, never success. */
	public function process( $id, array $settings, $job_id ) {
		$id = absint( $id );
		$result = array( 'id' => $id, 'file' => '', 'before' => 0, 'after' => 0, 'saved' => 0,
			'status' => 'failed', 'message' => '', 'target' => $settings['target'], 'target_reached' => false );
		if ( ! $id ) {
			$result['status'] = 'skipped_invalid';
			$result['message'] = 'Invalid attachment ID.';
			return $result;
		}
		$lock = null; $candidate = ''; $rollback = ''; $path = ''; $replaced = false; $recovered = false;
		set_error_handler( static function ( $level, $message ) { throw new \RuntimeException( $message ); } );
		try {
			$lock = fopen( self::storage() . '/images.lock', 'c' );
			if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
				$result['status'] = 'busy'; return $result;
			}
			$path = self::source( $id );
			$result['file'] = basename( $path );
			$this->recover( $id, $path );
			$recovered = true;
			clearstatcache( true, $path );
			$result['before'] = $result['after'] = (int) filesize( $path );
			$before = $result['before'];
			if ( ! is_readable( $path ) || ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) {
				$result['status'] = 'skipped_protected'; $result['message'] = 'Original or destination is not readable/writable.'; return $result;
			}
			$info = getimagesize( $path );
			$mime = $info['mime'] ?? '';
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ||
				( 'image/jpeg' === $mime && ! in_array( $ext, array( 'jpg', 'jpeg' ), true ) ) ||
				( 'image/png' === $mime && 'png' !== $ext ) ) {
				$result['status'] = 'skipped_unsupported'; $result['message'] = 'Only matching original JPEG/JPG and PNG files are supported.'; return $result;
			}
			$cap = self::capabilities();
			$format = 'image/jpeg' === $mime ? 'jpeg' : 'png';
			$use_im = $cap['imagick_' . $format];
			if ( ! $use_im && ! $cap['gd_' . $format] ) { throw new \RuntimeException( 'No supported ' . $format . ' decoder/encoder is installed.' ); }
			// Bound decoded memory before reading image payloads. Large images are skipped, never resized.
			$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
			$need = $info[0] * $info[1] * 24 + $before * 4 + 16777216;
			if ( $need > 268435456 || ( $limit > 0 && $need > $limit - memory_get_usage( true ) ) ) {
				$result['status'] = 'skipped_protected'; $result['message'] = 'Image exceeds the safe memory budget; dimensions were preserved.'; return $result;
			}
			$hash = hash_file( 'sha256', $path );
			$fingerprint_settings = $settings; unset( $fingerprint_settings['force'] );
			$fingerprint = hash( 'sha256', json_encode( $fingerprint_settings ) );
			$previous = (array) get_post_meta( $id, '_omninox_original_compression', true );
			$evaluated = (array) get_post_meta( $id, '_omninox_compression_attempt', true );
			if ( ! $settings['force'] && ( $evaluated['source_hash'] ?? '' ) === $hash && ( $evaluated['settings_hash'] ?? '' ) === $fingerprint ) {
				$result['status'] = $evaluated['status']; $result['message'] = 'Already evaluated with these settings. ' . $evaluated['message']; return $result;
			}
			// If PHP died after commit but before the job checkpoint, replay the verified receipt.
			if ( ( $previous['job_id'] ?? '' ) === $job_id && ( $previous['output_hash'] ?? '' ) === $hash ) {
				$this->validate( $path, $info, $use_im );
				$result['before'] = $previous['before']; $result['after'] = $before;
				$result['saved'] = max( 0, $previous['before'] - $before );
				$result['status'] = $result['saved'] > 0 ? 'optimized' : 'no_size_improvement';
				$result['target_reached'] = $before <= $settings['target'];
				$result['message'] = 'Recovered verified compression receipt.'; $result['recovery'] = $previous;
				return $result;
			}
			if ( ! $settings['force'] && ( $previous['output_hash'] ?? '' ) === $hash ) {
				$result['status'] = 'no_size_improvement';
				$result['message'] = ( $previous['settings_hash'] ?? '' ) === $fingerprint ? 'Already processed with these settings.' : 'Previously compressed generation protected. Restore first or explicitly force recompression.';
				return $result;
			}
			if ( $before <= $settings['target'] ) {
				$this->validate( $path, $info, $use_im );
				$result['status'] = 'already_below_target'; $result['target_reached'] = true; return $result;
			}
			$free = $this->disk_free( dirname( $path ) );
			$private_free = $this->disk_free( self::storage() );
			if ( false === $free || false === $private_free || $free < $before * 3 + 1048576 || $private_free < $before * 2 + 1048576 ) {
				throw new \RuntimeException( 'Insufficient disk space for candidate and recovery copies.' );
			}
			$attempt = $this->encode( $path, $info, $settings, $use_im );
			$candidate = $attempt['path'];
			if ( ! $candidate ) {
				$result['status'] = $attempt['status'] ?? 'no_size_improvement';
				$result['message'] = $attempt['message'] ?? 'No size improvement within quality limits.';
				update_post_meta( $id, '_omninox_compression_attempt', wp_slash( array( 'source_hash' => $hash, 'settings_hash' => $fingerprint, 'status' => $result['status'], 'message' => $result['message'] ) ) );
				return $result;
			}
			$this->validate( $candidate, $info, $use_im );
			$size = filesize( $candidate );
			if ( $size >= $before ) { throw new \RuntimeException( 'Candidate is not smaller than the original.' ); }
			if ( $size > $settings['target'] && ! $settings['keep_smaller'] ) {
				$result['status'] = 'target_not_achievable'; $result['message'] = 'Target not achievable within quality limits.';
				update_post_meta( $id, '_omninox_compression_attempt', wp_slash( array( 'source_hash' => $hash, 'settings_hash' => $fingerprint, 'status' => $result['status'], 'message' => $result['message'] ) ) );
				return $result;
			}
			if ( hash_file( 'sha256', $path ) !== $hash ) { throw new \RuntimeException( 'Original changed during compression.' ); }
			// A transaction copy is mandatory even when long-term backup retention is disabled.
			$rollback = self::storage() . '/' . bin2hex( random_bytes( 20 ) ) . '.original';
			if ( ! $this->backup( $path, $rollback ) || hash_file( 'sha256', $rollback ) !== $hash ) { throw new \RuntimeException( 'Recovery copy verification failed; original untouched.' ); }
			chmod( $rollback, 0600 );
			$record = array( 'backup' => $rollback, 'source_hash' => $hash, 'output_hash' => hash_file( 'sha256', $candidate ),
				'settings_hash' => $fingerprint, 'path' => $path, 'before' => $before, 'after' => $size,
				'job_id' => $job_id, 'expires' => time() + $settings['retention_days'] * DAY_IN_SECONDS,
				'metadata' => wp_get_attachment_metadata( $id ), 'keep_backup' => $settings['backup'] );
			update_post_meta( $id, '_omninox_compression_pending', wp_slash( $record ) );
			if ( get_post_meta( $id, '_omninox_compression_pending', true ) !== $record ) { throw new \RuntimeException( 'Could not persist recovery journal.' ); }
			chmod( $candidate, fileperms( $path ) & 0777 );
			if ( ! $this->replace( $candidate, $path ) ) { throw new \RuntimeException( 'Atomic replacement failed; original untouched.' ); }
			$candidate = ''; $replaced = true;
			clearstatcache( true, $path );
			$this->validate( $path, $info, $use_im );
			if ( filesize( $path ) !== $size || hash_file( 'sha256', $path ) !== $record['output_hash'] || $size >= $before ) {
				throw new \RuntimeException( 'Final on-disk verification failed.' );
			}
			$this->metadata( $id, $path, $size );
			update_post_meta( $id, '_omninox_original_compression', wp_slash( $record ) );
			if ( get_post_meta( $id, '_omninox_original_compression', true ) !== $record ) { throw new \RuntimeException( 'Could not persist compression result.' ); }
			$result['after'] = $size; $result['saved'] = $before - $size;
			$result['status'] = 'optimized'; $result['target_reached'] = $size <= $settings['target'];
			$result['message'] = $result['target_reached'] ? 'Optimized — target reached.' : 'Optimized — target not reached. Target not achievable within quality limits.';
			$result['quality'] = $attempt['quality']; $result['engine'] = $attempt['engine'];
			$result['recovery'] = $record;
			delete_post_meta( $id, '_omninox_compression_attempt' );
			delete_post_meta( $id, '_omninox_compression_pending' );
			// Retention cleanup is separate from the verified commit and must never turn it into a false failure.
			if ( ! $settings['backup'] ) {
				$record['expires'] = time(); $result['recovery']['expires'] = $record['expires'];
				update_post_meta( $id, '_omninox_original_compression', wp_slash( $record ) );
			}
			$rollback = ''; $replaced = false;
		} catch ( \Throwable $e ) {
			$result['status'] = 'failed'; $result['saved'] = 0; $result['message'] = $e->getMessage();
			if ( $replaced ) {
				try {
					$this->restore_record( $id, $record, $path, true, true );
					delete_post_meta( $id, '_omninox_compression_pending' );
					$result['message'] .= ' Original restored.';
				} 
				catch ( \Throwable $restore_error ) { $result['message'] .= ' Recovery required: ' . $restore_error->getMessage(); $rollback = ''; }
			} elseif ( $recovered ) { delete_post_meta( $id, '_omninox_compression_pending' ); }
			if ( $path && is_file( $path ) ) { clearstatcache( true, $path ); $result['after'] = (int) filesize( $path ); }
		} finally {
			restore_error_handler();
			foreach ( array( $candidate, $rollback ) as $tmp ) { if ( $tmp && is_file( $tmp ) ) { unlink( $tmp ); } }
			if ( is_resource( $lock ) ) { flock( $lock, LOCK_UN ); fclose( $lock ); }
		}
		return $result;
	}

	private function metadata( $id, $path, $size ) {
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		// For WP scaled images, filesize describes the attached scaled file, not original_image.
		if ( realpath( get_attached_file( $id ) ) === $path ) { $meta['filesize'] = $size; }
		else { $meta['omninox_original_filesize'] = $size; }
		wp_update_attachment_metadata( $id, $meta );
		clean_post_cache( $id );
		if ( wp_get_attachment_metadata( $id ) !== $meta ) { throw new \RuntimeException( 'Attachment metadata verification failed.' ); }
	}

	private function validate( $path, array $original, $use_im ) {
		clearstatcache( true, $path );
		$info = getimagesize( $path );
		if ( ! $info || $info[0] !== $original[0] || $info[1] !== $original[1] || $info['mime'] !== $original['mime'] ) {
			throw new \RuntimeException( 'Image MIME or dimensions changed unexpectedly.' );
		}
		if ( $use_im ) {
			$image = new \Imagick( $path );
			try { if ( $image->getNumberImages() !== 1 ) { throw new \RuntimeException( 'Animated images are unsupported.' ); } $image->getImageSignature(); }
			finally { $image->clear(); }
		} else {
			$image = 'image/jpeg' === $info['mime'] ? imagecreatefromjpeg( $path ) : imagecreatefrompng( $path );
			if ( ! $image ) { throw new \RuntimeException( 'Image cannot be decoded.' ); }
			imagedestroy( $image );
		}
	}

	/** Fresh source decode on every JPEG attempt prevents cumulative generation loss. */
	private function encode( $path, array $info, array $settings, $use_im ) {
		$best = ''; $size = filesize( $path ); $quality = null; $tmp = '';
		try {
			if ( 'image/png' === $info['mime'] ) { return $this->png( $path, $settings, $use_im ); }
			if ( ! $use_im && ( $info['channels'] ?? 3 ) === 4 ) {
				return array( 'path' => '', 'status' => 'skipped_protected', 'message' => 'CMYK JPEG requires Imagick to preserve color.' );
			}
			$markers = $this->jpeg_markers( file_get_contents( $path ), $settings['strip_metadata'] );
			$steps = array_unique( array_merge( range( 95, $settings['quality_floor'], -3 ), array( $settings['quality_floor'] ) ) );
			foreach ( $steps as $q ) {
				$tmp = $this->temp( $path );
				if ( $use_im ) {
					$image = new \Imagick( $path );
					try {
						$image->setImageFormat( 'jpeg' ); $image->setImageCompressionQuality( $q );
						$image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
						$image->setOption( 'jpeg:optimize-coding', 'true' );
						if ( ! $image->writeImage( $tmp ) ) { throw new \RuntimeException( 'JPEG encoder failed to write candidate.' ); }
					} finally { $image->clear(); }
				} else {
					$image = imagecreatefromjpeg( $path );
					try { imageinterlace( $image, true ); if ( ! imagejpeg( $image, $tmp, $q ) ) { throw new \RuntimeException( 'JPEG encoder failed.' ); } }
					finally { imagedestroy( $image ); }
				}
				// Keep original EXIF (including orientation), ICC, XMP and comments by default.
				// Do not auto-orient pixels: retained EXIF must continue to describe the same orientation.
				$encoded = $this->jpeg_without_metadata( file_get_contents( $tmp ) );
				$bytes = substr( $encoded, 0, 2 ) . $markers . substr( $encoded, 2 );
				if ( file_put_contents( $tmp, $bytes ) !== strlen( $bytes ) ) { throw new \RuntimeException( 'Candidate metadata write failed.' ); }
				clearstatcache( true, $tmp );
				$this->validate( $tmp, $info, $use_im );
				if ( ! $this->similar( $path, $tmp, $use_im, $info ) ) { unlink( $tmp ); $tmp = ''; continue; }
				if ( filesize( $tmp ) < $size ) {
					if ( $best ) { unlink( $best ); }
					$best = $tmp; $tmp = ''; $size = filesize( $best ); $quality = $q;
					// Prefer the highest tested quality that reaches the target.
					if ( $size <= $settings['target'] ) { break; }
				} else { unlink( $tmp ); $tmp = ''; }
			}
			return array( 'path' => $best, 'quality' => $quality, 'engine' => $use_im ? 'Imagick JPEG' : 'GD JPEG' );
		} catch ( \Throwable $e ) { if ( $best && is_file( $best ) ) { unlink( $best ); } throw $e; }
		finally { if ( $tmp && is_file( $tmp ) ) { unlink( $tmp ); } }
	}

	/** Supplementary decoded-pixel error guard, not a claim of perceptual losslessness. */
	private function similar( $source, $candidate, $use_im, array $info ) {
		if ( $use_im ) {
			$a = new \Imagick( $source ); $b = new \Imagick( $candidate );
			try { $c = $a->compareImages( $b, \Imagick::METRIC_ROOTMEANSQUAREDERROR ); $c[0]->clear(); return $c[1] <= 0.06; }
			finally { $a->clear(); $b->clear(); }
		}
		$a = imagecreatefromjpeg( $source ); $b = imagecreatefromjpeg( $candidate ); $sum = 0; $count = 0;
		try {
			for ( $y = 0; $y < $info[1]; $y += max( 1, (int) floor( $info[1] / 64 ) ) ) {
				for ( $x = 0; $x < $info[0]; $x += max( 1, (int) floor( $info[0] / 64 ) ) ) {
					$ca = imagecolorat( $a, $x, $y ); $cb = imagecolorat( $b, $x, $y );
					foreach ( array( 16, 8, 0 ) as $shift ) { $d = ( ( $ca >> $shift ) & 255 ) - ( ( $cb >> $shift ) & 255 ); $sum += $d * $d; ++$count; }
				}
			}
			return sqrt( $sum / max( 1, $count ) ) / 255 <= 0.06;
		} finally { imagedestroy( $a ); imagedestroy( $b ); }
	}

	/** Parse JPEG markers through SOS. APP0/APP14 retain encoder color interpretation. */
	private function jpeg_parts( $bytes ) {
		if ( substr( $bytes, 0, 2 ) !== "\xff\xd8" ) { throw new \RuntimeException( 'Invalid JPEG header.' ); }
		$parts = array(); $i = 2; $len = strlen( $bytes );
		while ( $i + 4 <= $len ) {
			$start = $i;
			if ( ord( $bytes[$i++] ) !== 255 ) { throw new \RuntimeException( 'Invalid JPEG marker.' ); }
			while ( $i < $len && ord( $bytes[$i] ) === 255 ) { ++$i; }
			$type = ord( $bytes[$i++] );
			if ( 218 === $type || 217 === $type ) { $parts[] = array( $type, substr( $bytes, $start ) ); return $parts; }
			$n = unpack( 'n', substr( $bytes, $i, 2 ) )[1];
			if ( $n < 2 || $i + $n > $len ) { throw new \RuntimeException( 'Truncated JPEG marker.' ); }
			$i += $n; $parts[] = array( $type, substr( $bytes, $start, $i - $start ) );
		}
		throw new \RuntimeException( 'Truncated JPEG.' );
	}

	private function jpeg_markers( $bytes, $strip ) {
		$out = '';
		foreach ( $this->jpeg_parts( $bytes ) as $p ) {
			if ( ( $p[0] >= 225 && $p[0] <= 237 ) || 239 === $p[0] || 254 === $p[0] ) {
				if ( ! $strip || 225 === $p[0] || 226 === $p[0] ) { $out .= $p[1]; }
			}
		}
		return $out;
	}

	private function jpeg_without_metadata( $bytes ) {
		$out = "\xff\xd8";
		foreach ( $this->jpeg_parts( $bytes ) as $p ) {
			if ( ! ( ( $p[0] >= 225 && $p[0] <= 237 ) || 239 === $p[0] || 254 === $p[0] ) ) { $out .= $p[1]; }
		}
		return $out;
	}

	/** Lossless PNG recompresses the unchanged filtered pixel stream, retaining every ancillary chunk. */
	private function png( $path, array $settings, $use_im ) {
		$bytes = file_get_contents( $path ); $chunks = array(); $idat = ''; $i = 8;
		while ( $i + 12 <= strlen( $bytes ) ) {
			$n = unpack( 'N', substr( $bytes, $i, 4 ) )[1]; $type = substr( $bytes, $i + 4, 4 );
			if ( $n > strlen( $bytes ) - $i - 12 ) { throw new \RuntimeException( 'Truncated PNG chunk.' ); }
			$data = substr( $bytes, $i + 8, $n );
			if ( hash( 'crc32b', $type . $data, true ) !== substr( $bytes, $i + 8 + $n, 4 ) ) { throw new \RuntimeException( 'Invalid PNG checksum.' ); }
			if ( 'acTL' === $type ) { return array( 'path' => '', 'status' => 'skipped_unsupported', 'message' => 'Animated PNG is preserved unchanged.' ); }
			if ( 'IDAT' === $type ) { $idat .= $data; }
			$chunks[] = array( $type, substr( $bytes, $i, $n + 12 ) ); $i += $n + 12;
		}
		$dimensions = getimagesize( $path );
		// Bound expansion by the advertised dimensions (including 16-bit RGBA/Adam7 overhead).
		$max_stream = min( 268435456, $dimensions[0] * $dimensions[1] * 8 + $dimensions[1] * 16 + 1024 );
		$raw = gzuncompress( $idat, $max_stream );
		if ( false === $raw ) { throw new \RuntimeException( 'Invalid PNG pixel stream.' ); }
		$packed = gzcompress( $raw, 9 ); unset( $raw );
		$out = substr( $bytes, 0, 8 ); $written = false;
		foreach ( $chunks as $chunk ) {
			if ( 'IDAT' === $chunk[0] ) {
				if ( ! $written ) { $out .= pack( 'N', strlen( $packed ) ) . 'IDAT' . $packed . hash( 'crc32b', 'IDAT' . $packed, true ); $written = true; }
			} else { $out .= $chunk[1]; }
		}
		$tmp = $this->temp( $path );
		try {
			if ( file_put_contents( $tmp, $out ) !== strlen( $out ) ) { throw new \RuntimeException( 'PNG candidate write failed.' ); }
			// Optional lossy quantization is deliberately conservative: only opaque, 8-bit PNGs.
			// Alpha/ICC/16-bit images keep the strictly lossless stream path.
			if ( $settings['lossy_png'] && $use_im && strlen( $out ) > $settings['target'] ) {
				$original = new \Imagick( $path ); $lossy = clone $original;
				try {
					if ( ! $original->getImageAlphaChannel() && $original->getImageDepth() <= 8 && ! $original->getImageProfiles( 'icc' ) ) {
						$lossy->quantizeImage( 256, $original->getImageColorspace(), 0, false, false );
						$comparison = $original->compareImages( $lossy, \Imagick::METRIC_ROOTMEANSQUAREDERROR );
						$comparison[0]->clear();
						if ( $comparison[1] <= 0.005 ) {
							$blob = $lossy->getImageBlob();
							if ( strlen( $blob ) < strlen( $out ) && file_put_contents( $tmp, $blob ) !== strlen( $blob ) ) { throw new \RuntimeException( 'Lossy PNG write failed.' ); }
						}
					}
				} finally { $original->clear(); $lossy->clear(); }
			}
			clearstatcache( true, $tmp );
			if ( filesize( $tmp ) >= filesize( $path ) ) { unlink( $tmp ); $tmp = ''; }
			return array( 'path' => $tmp, 'quality' => null, 'engine' => 'PNG lossless stream optimization' . ( $settings['lossy_png'] ? ' / optional bounded quantization' : '' ) );
		} catch ( \Throwable $e ) { if ( $tmp && is_file( $tmp ) ) { unlink( $tmp ); } throw $e; }
	}

	/** Recover interrupted replacement before another attempt. Never discard the recovery journal on failure. */
	public function recover( $id, $path ) {
		$pending = get_post_meta( $id, '_omninox_compression_pending', true );
		if ( ! $pending ) { return; }
		$this->restore_record( $id, $pending, $path, true );
		delete_post_meta( $id, '_omninox_compression_pending' );
	}

	public function restore_record( $id, array $record, $path = '', $recovery = false, $owned_failure = false ) {
		$path = $path ?: self::source( $id );
		if ( $path !== $record['path'] || ! self::inside( $record['backup'], self::storage() ) ||
			hash_file( 'sha256', $record['backup'] ) !== $record['source_hash'] ) { throw new \RuntimeException( 'Backup missing, changed, or path no longer matches.' ); }
		$current = hash_file( 'sha256', $path );
		if ( $recovery && ! $owned_failure && ! in_array( $current, array( $record['output_hash'], $record['source_hash'] ), true ) ) {
			throw new \RuntimeException( 'Pending recovery conflicts with externally changed image; backup retained.' );
		}
		if ( ! $recovery && $current !== $record['output_hash'] ) { throw new \RuntimeException( 'Image changed since this job; refusing to overwrite it.' ); }
		$tmp = $this->temp( $path );
		try {
			if ( ! copy( $record['backup'], $tmp ) || hash_file( 'sha256', $tmp ) !== $record['source_hash'] ) { throw new \RuntimeException( 'Restore candidate verification failed.' ); }
			chmod( $tmp, fileperms( $path ) & 0777 );
			if ( ! $this->replace( $tmp, $path ) ) { throw new \RuntimeException( 'Restore replacement failed.' ); }
			clearstatcache( true, $path );
			if ( hash_file( 'sha256', $path ) !== $record['source_hash'] ) { throw new \RuntimeException( 'Restored file hash mismatch.' ); }
			wp_update_attachment_metadata( $id, $record['metadata'] ); clean_post_cache( $id );
			delete_post_meta( $id, '_omninox_original_compression' );
			return array( 'id' => $id, 'status' => 'restored', 'after' => filesize( $path ), 'message' => 'Original bytes restored and SHA-256 verified.' );
		} finally { if ( is_file( $tmp ) ) { unlink( $tmp ); } }
	}
}
