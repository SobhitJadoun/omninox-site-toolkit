<?php
/** Persistent original compression jobs, shared by Free and Pro. */
namespace OmninoxSiteToolkit\Modules\Media\Compression;

defined( 'ABSPATH' ) || exit;

class CompressionJobs {

	const HOOK = 'omninox_original_compression_tick';
	const INDEX = 'omninox_original_compression_jobs';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'omninox_daily_maintenance', array( __CLASS__, 'prune' ) );
	}

	private static function key( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) { throw new \RuntimeException( 'Invalid compression job ID.' ); }
		return 'omninox_compression_job_' . $id;
	}

	private static function read( $id ) {
		$job = get_option( self::key( $id ) );
		if ( ! is_array( $job ) ) { throw new \RuntimeException( 'Compression job not found.' ); }
		return $job;
	}

	private static function save( array $job ) {
		update_option( self::key( $job['id'] ), $job, false );
		if ( get_option( self::key( $job['id'] ) ) !== $job ) { throw new \RuntimeException( 'Could not persist compression job.' ); }
	}

	private static function lock( $id ) {
		self::key( $id );
		$lock = fopen( OriginalCompressor::storage() . '/' . $id . '.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) { fclose( $lock ); }
			throw new \RuntimeException( 'Job is processing an image. Retry shortly.' );
		}
		return $lock;
	}

	private static function schedule( $id ) {
		if ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) { wp_schedule_single_event( time() + 10, self::HOOK, array( $id ) ); }
	}

	public static function dispatch( $action, array $params ) {
		try {
			if ( 'compression_start' === $action || 'bulk_compress' === $action ) { return self::start( $params ); }
			if ( 'compression_history' === $action ) {
				$jobs = array();
				foreach ( (array) get_option( self::INDEX, array() ) as $id ) {
					$job = get_option( self::key( $id ) );
					if ( $job ) { $jobs[] = self::summary( $job ); }
				}
				return array( 'jobs' => array_reverse( $jobs ), 'capabilities' => OriginalCompressor::capabilities() );
			}
			$id = (string) ( $params['job_id'] ?? '' );
			if ( 'compression_status' === $action ) { return self::summary( self::read( $id ), $params ); }
			if ( 'compression_step' === $action ) { self::tick( $id ); return self::summary( self::read( $id ), $params ); }
			$lock = self::lock( $id );
			try {
				$job = self::read( $id );
				if ( 'compression_pause' === $action && in_array( $job['status'], array( 'running', 'discovering' ), true ) ) {
					$job['resume_status'] = $job['status']; $job['status'] = 'paused';
				} elseif ( 'compression_resume' === $action && 'paused' === $job['status'] ) {
					$job['status'] = $job['resume_status']; self::schedule( $id );
				} elseif ( 'compression_cancel' === $action && ! in_array( $job['status'], array( 'completed', 'cancelled' ), true ) ) {
					$job['status'] = 'cancelled';
				} elseif ( 'compression_restore' === $action ) {
					if ( ! in_array( $job['status'], array( 'completed', 'cancelled', 'paused' ), true ) ) { throw new \RuntimeException( 'Pause the job before restoring images.' ); }
					$job['restore_ids'] = array_values( array_unique( array_map( 'absint', (array) ( $params['ids'] ?? array() ) ) ) );
					$job['restore_cursor'] = 0; $job['restore_run'] = bin2hex( random_bytes( 16 ) ); $job['status'] = 'restoring'; self::schedule( $id );
				}
				self::save( $job );
				return self::summary( $job, $params );
			} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
		} catch ( \Throwable $e ) { return new \WP_Error( 'omninox_compression', $e->getMessage(), array( 'status' => 400 ) ); }
	}

	private static function start( array $params ) {
		global $wpdb;
		// Client request IDs make a retried start idempotent.
		$request = preg_replace( '/[^a-zA-Z0-9-]/', '', (string) ( $params['request_id'] ?? '' ) );
		$lock = self::lock( str_repeat( '0', 32 ) );
		try {
			$index = (array) get_option( self::INDEX, array() );
			foreach ( $index as $existing ) {
				$old = self::read( $existing );
				if ( $request && $old['request_id'] === $request ) { return self::summary( $old ); }
				if ( in_array( $old['status'], array( 'running', 'discovering', 'paused', 'restoring' ), true ) ) {
					throw new \RuntimeException( 'An existing compression job must finish or be cancelled first.' );
				}
			}
			$cap = OriginalCompressor::capabilities();
			if ( ! array_filter( $cap ) ) { throw new \RuntimeException( 'Install a supported Imagick or GD JPEG/PNG encoder before starting.' ); }
			$id = bin2hex( random_bytes( 16 ) );
			$job = array( 'id' => $id, 'request_id' => $request, 'status' => 'discovering', 'created' => time(),
				'settings' => OriginalCompressor::settings( $params ), 'ids' => array_values( array_filter( array_map( 'absint', (array) ( $params['ids'] ?? array() ) ) ) ),
				'exclude' => array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $params['exclude'] ?? '' ) ) ) ) ),
				'mime' => in_array( $params['mime'] ?? '', array( 'image/jpeg', 'image/png' ), true ) ? $params['mime'] : '',
				'min_bytes' => max( 0, (int) ( $params['min_bytes'] ?? 0 ) ), 'max_bytes' => max( 0, (int) ( $params['max_bytes'] ?? 0 ) ),
				'date_from' => self::date( $params['date_from'] ?? '' ), 'date_to' => self::date( $params['date_to'] ?? '' ),
				'max_id' => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ),
				'discovery_cursor' => 0, 'cursor' => 0, 'eligible' => 0, 'processed' => 0, 'optimized' => 0,
				'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'before' => 0, 'after' => 0, 'saved' => 0, 'restored' => 0,
				'restore_failed' => 0, 'last_error' => '', 'recovery_history' => array() );
			self::save( $job ); $index[] = $id; update_option( self::INDEX, $index, false );
			self::schedule( $id ); return self::summary( $job );
		} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
	}

	private static function date( $value ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/D', (string) $value ) ? $value : '';
	}

	/** Discovery uses stable ID keysets and writes pages, never a changing NOT EXISTS queue. */
	private static function discover( array &$job ) {
		global $wpdb;
		$where = $wpdb->prepare( "post_type = 'attachment' AND post_status = 'inherit' AND ID > %d AND ID <= %d", $job['discovery_cursor'], $job['max_id'] );
		$where .= $job['mime'] ? $wpdb->prepare( ' AND post_mime_type = %s', $job['mime'] ) : " AND post_mime_type IN ('image/jpeg','image/png')";
		if ( $job['ids'] ) { $where .= ' AND ID IN (' . implode( ',', $job['ids'] ) . ')'; }
		if ( $job['exclude'] ) { $where .= ' AND ID NOT IN (' . implode( ',', $job['exclude'] ) . ')'; }
		if ( $job['date_from'] ) { $where .= $wpdb->prepare( ' AND post_date >= %s', $job['date_from'] . ' 00:00:00' ); }
		if ( $job['date_to'] ) { $where .= $wpdb->prepare( ' AND post_date <= %s', $job['date_to'] . ' 23:59:59' ); }
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID ASC LIMIT 100" );
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			$job['discovery_cursor'] = $id;
			try {
				$path = OriginalCompressor::source( $id );
				clearstatcache( true, $path );
				$size = filesize( $path );
				if ( $size < $job['min_bytes'] || ( $job['max_bytes'] && $size > $job['max_bytes'] ) ) {
					continue;
				}
			} catch ( \Throwable $e ) { /* Queue path issues so the engine can report the failure accurately during processing */ }
			self::write_json( self::file( $job['id'], $job['eligible'], 'queue' ), array( 'id' => $id ) );
			++$job['eligible'];
		}
		if ( count( $ids ) < 100 ) { $job['status'] = $job['eligible'] ? 'running' : 'completed'; }
	}

	private static function file( $id, $cursor, $kind ) {
		self::key( $id );
		return OriginalCompressor::storage() . '/' . $id . '-' . (int) $cursor . '-' . $kind . '.json';
	}

	private static function write_json( $file, array $data ) {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0700, true );
		}
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			throw new \RuntimeException( 'Could not encode job checkpoint JSON.' );
		}
		$tmp = $file . '.tmp.' . bin2hex( random_bytes( 4 ) );
		if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
			if ( false === @file_put_contents( $file, $json, LOCK_EX ) ) {
				throw new \RuntimeException( 'Could not persist job checkpoint.' );
			}
			return;
		}
		if ( ! @rename( $tmp, $file ) ) {
			@copy( $tmp, $file );
			@unlink( $tmp );
		}
	}

	/** One image per checkpoint; up to three small images within an eight-second budget. */
	public static function tick( $id ) {
		$lock = null;
		try {
			$lock = self::lock( $id ); $job = self::read( $id );
			if ( ! in_array( $job['status'], array( 'running', 'discovering', 'restoring' ), true ) ) { return; }
			// Schedule recovery BEFORE work, so a killed PHP process does not strand the job.
			self::schedule( $id );
			if ( 'discovering' === $job['status'] ) { self::discover( $job ); self::save( $job ); return; }
			$engine = new OriginalCompressor(); $start = microtime( true ); $passes = 0;
			do {
				if ( 'restoring' === $job['status'] ) { self::restore_step( $job, $engine ); self::save( $job ); }
				else {
					$cursor = $job['cursor'];
					if ( $cursor >= $job['eligible'] ) { $job['status'] = 'completed'; self::save( $job ); break; }
					$file = self::file( $id, $cursor, 'result' );
					if ( is_file( $file ) ) {
						$result = json_decode( file_get_contents( $file ), true );
					} else {
						$queue_file = self::file( $id, $cursor, 'queue' );
						$entry = is_file( $queue_file ) ? json_decode( file_get_contents( $queue_file ), true ) : null;
						$attachment_id = ( is_array( $entry ) && ! empty( $entry['id'] ) ) ? absint( $entry['id'] ) : 0;
						if ( ! $attachment_id ) {
							$result = array(
								'id'             => 0,
								'file'           => '',
								'before'         => 0,
								'after'          => 0,
								'saved'          => 0,
								'status'         => 'skipped_invalid',
								'message'        => 'Queue entry missing or invalid.',
								'target'         => $job['settings']['target'] ?? 102400,
								'target_reached' => false,
							);
							self::write_json( $file, $result );
						} else {
							$result = $engine->process( $attachment_id, $job['settings'], $id );
							if ( 'busy' === $result['status'] ) { break; }
							self::write_json( $file, $result );
						}
					}
					++$job['cursor']; ++$job['processed'];
					$job['before'] += $result['before']; $job['after'] += $result['after']; $job['saved'] += $result['saved'];
					if ( 'optimized' === $result['status'] ) { ++$job['optimized']; }
					elseif ( 'failed' === $result['status'] ) { ++$job['failed']; }
					elseif ( 0 === strpos( $result['status'], 'skipped_' ) ) { ++$job['skipped']; }
					else { ++$job['unchanged']; }
					if ( $job['cursor'] >= $job['eligible'] ) { $job['status'] = 'completed'; }
					self::save( $job );
				}
				++$passes;
			} while ( in_array( $job['status'], array( 'running', 'restoring' ), true ) && $passes < 3 && microtime( true ) - $start < 8 && memory_get_usage( true ) < 134217728 );
		} catch ( \Throwable $e ) {
			if ( isset( $job ) && is_array( $job ) ) {
				$job['resume_status'] = $job['status']; $job['status'] = 'paused'; $job['last_error'] = $e->getMessage();
				self::save( $job );
			}
		} finally { if ( is_resource( $lock ) ) { flock( $lock, LOCK_UN ); fclose( $lock ); } }
	}

	private static function restore_step( array &$job, OriginalCompressor $engine ) {
		if ( $job['restore_cursor'] >= $job['cursor'] ) { $job['status'] = 'completed'; return; }
		$cursor = $job['restore_cursor'];
		$file = self::file( $job['id'], $cursor, 'result' );
		$result = json_decode( file_get_contents( $file ), true );
		if ( ! empty( $result['restored'] ) && ( $result['restore_run'] ?? '' ) === $job['restore_run'] ) {
			++$job['restored']; ++$job['restore_cursor'];
			$job['recovery_history'][] = array( 'id' => $result['id'], 'at' => $result['restored'], 'status' => 'restored' );
			return;
		}
		if ( ! empty( $result['recovery']['backup'] ) && empty( $result['restored'] ) &&
			( ! $job['restore_ids'] || in_array( $result['id'], $job['restore_ids'], true ) ) ) {
			$image_lock = fopen( OriginalCompressor::storage() . '/images.lock', 'c' );
			try {
				if ( ! flock( $image_lock, LOCK_EX | LOCK_NB ) ) { return; }
				$record = $result['recovery'];
				// A restore whose checkpoint write was interrupted is idempotent.
				if ( hash_file( 'sha256', OriginalCompressor::source( $result['id'] ) ) !== $record['source_hash'] ) {
					$engine->restore_record( $result['id'], $record );
				}
				$result['restored'] = time(); $result['restore_run'] = $job['restore_run']; self::write_json( $file, $result );
				++$job['restored'];
				$job['recovery_history'][] = array( 'id' => $result['id'], 'at' => time(), 'status' => 'restored' );
			} catch ( \Throwable $e ) {
				++$job['restore_failed']; $job['recovery_history'][] = array( 'id' => $result['id'], 'at' => time(), 'status' => 'failed', 'message' => $e->getMessage() );
			} finally { flock( $image_lock, LOCK_UN ); fclose( $image_lock ); }
			$job['recovery_history'] = array_slice( $job['recovery_history'], -100 );
		}
		++$job['restore_cursor'];
	}

	private static function summary( array $job, array $params = array() ) {
		$out = array_intersect_key( $job, array_flip( array( 'id', 'status', 'created', 'eligible', 'processed', 'optimized', 'unchanged', 'skipped', 'failed', 'before', 'after', 'saved', 'restored', 'restore_failed', 'last_error', 'recovery_history', 'settings' ) ) );
		$out['job_id'] = $job['id'];
		$out['ratio'] = $job['before'] ? round( 100 * $job['saved'] / $job['before'], 2 ) : 0;
		$out['done'] = in_array( $job['status'], array( 'completed', 'cancelled', 'paused' ), true );
		$out['results'] = array();
		$offset = max( 0, (int) ( $params['result_offset'] ?? max( 0, $job['cursor'] - 50 ) ) );
		for ( $i = $offset; $i < min( $offset + 50, $job['cursor'] ); ++$i ) {
			$row = json_decode( file_get_contents( self::file( $job['id'], $i, 'result' ) ), true );
			unset( $row['recovery'] ); $out['results'][] = $row;
		}
		$out['result_offset'] = $offset;
		$out['message'] = sprintf( '%s: %d optimized, %d unchanged, %d skipped, %d failed; %d verified bytes saved.', $job['status'], $job['optimized'], $job['unchanged'], $job['skipped'], $job['failed'], $job['saved'] );
		return $out;
	}

	/** Retention removes only explicitly recorded backups; job measurements remain historical. */
	public static function prune() {
		foreach ( (array) get_option( self::INDEX, array() ) as $id ) {
			try {
				$lock = self::lock( $id );
				try {
					$job = self::read( $id );
					if ( ! in_array( $job['status'], array( 'completed', 'cancelled' ), true ) ) { continue; }
					for ( $i = 0; $i < $job['cursor']; ++$i ) {
						$file = self::file( $id, $i, 'result' ); $row = json_decode( file_get_contents( $file ), true );
						$r = $row['recovery'] ?? array();
						if ( ! empty( $r['backup'] ) && $r['expires'] < time() && OriginalCompressor::inside( $r['backup'], OriginalCompressor::storage() ) ) {
							unlink( $r['backup'] ); $row['recovery']['backup'] = ''; self::write_json( $file, $row );
						}
					}
				} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
			} catch ( \Throwable $e ) { error_log( 'Omninox compression retention: ' . $e->getMessage() ); }
		}
	}
}
