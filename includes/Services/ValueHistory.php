<?php
/**
 * Remembers what a value was before the plugin changed it.
 *
 * Every editable setting routes through here so the interface can always show
 * "was 128M, changed 3 days ago" next to the current reading, and so a change
 * can be reverted without the person having to remember the old number.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Services;

defined( 'ABSPATH' ) || exit;

class ValueHistory {

	const OPTION = 'omninox_value_history';

	/**
	 * How many changes to keep per key.
	 */
	const DEPTH = 10;

	/**
	 * Record a change.
	 *
	 * @param string $key   Value identifier, e.g. "memory_limit".
	 * @param mixed  $from  Previous value.
	 * @param mixed  $to    New value.
	 * @param string $label Human readable name.
	 * @return array The stored entry.
	 */
	public static function record( $key, $from, $to, $label = '' ) {
		$key   = sanitize_key( $key );
		$store = (array) get_option( self::OPTION, array() );

		$entry = array(
			'from'  => self::flatten( $from ),
			'to'    => self::flatten( $to ),
			'label' => sanitize_text_field( $label ),
			'at'    => current_time( 'mysql' ),
			'user'  => get_current_user_id(),
			'who'   => self::current_user_name(),
		);

		if ( ! isset( $store[ $key ] ) || ! is_array( $store[ $key ] ) ) {
			$store[ $key ] = array();
		}

		array_unshift( $store[ $key ], $entry );

		$store[ $key ] = array_slice( $store[ $key ], 0, self::DEPTH );

		update_option( self::OPTION, $store, false );

		/**
		 * Fires after a tracked value changes.
		 *
		 * @param string $key   Value key.
		 * @param array  $entry Stored entry.
		 */
		do_action( 'omninox_value_changed', $key, $entry );

		return $entry;
	}

	/**
	 * The most recent change for a key.
	 *
	 * @param string $key Value key.
	 * @return array|null
	 */
	public static function last( $key ) {
		$store = (array) get_option( self::OPTION, array() );
		$key   = sanitize_key( $key );

		return isset( $store[ $key ][0] ) ? $store[ $key ][0] : null;
	}

	/**
	 * The value a key held before the most recent change.
	 *
	 * @param string $key Value key.
	 * @return string
	 */
	public static function previous( $key ) {
		$last = self::last( $key );

		return $last ? (string) $last['from'] : '';
	}

	/**
	 * A short description of the last change, ready for the interface.
	 *
	 * @param string $key Value key.
	 * @return string
	 */
	public static function summary( $key ) {
		$last = self::last( $key );

		if ( ! $last ) {
			return '';
		}

		return sprintf(
			/* translators: 1: previous value, 2: relative time, 3: user name */
			__( 'Was %1$s until %2$s ago, changed by %3$s', 'omninox-site-toolkit'),
			$last['from'] !== '' ? $last['from'] : __( 'unset', 'omninox-site-toolkit'),
			human_time_diff( strtotime( $last['at'] ), current_time( 'timestamp' ) ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			$last['who'] ? $last['who'] : __( 'a scheduled task', 'omninox-site-toolkit')
		);
	}

	/**
	 * Full history for a key, newest first.
	 *
	 * @param string $key Value key.
	 * @return array
	 */
	public static function all( $key ) {
		$store = (array) get_option( self::OPTION, array() );

		return (array) ( $store[ sanitize_key( $key ) ] ?? array() );
	}

	/**
	 * Recent changes across every tracked key.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function recent( $limit = 25 ) {
		$store = (array) get_option( self::OPTION, array() );
		$rows  = array();

		foreach ( $store as $key => $entries ) {
			foreach ( (array) $entries as $entry ) {
				$entry['key'] = $key;
				$rows[]       = $entry;
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $b['at'], $a['at'] );
			}
		);

		return array_slice( $rows, 0, absint( $limit ) );
	}

	/**
	 * Attach the previous value and a summary to a check payload.
	 *
	 * @param array  $issue Issue array passed by reference shape.
	 * @param string $key   Value key.
	 * @return array
	 */
	public static function decorate( array $issue, $key ) {
		$last = self::last( $key );

		if ( ! $last ) {
			return $issue;
		}

		$issue['previous']         = $last['from'];
		$issue['previous_summary'] = self::summary( $key );

		return $issue;
	}

	/**
	 * Forget the history for one key, or everything.
	 *
	 * @param string $key Value key, empty for all.
	 */
	public static function forget( $key = '' ) {
		if ( '' === $key ) {
			delete_option( self::OPTION );

			return;
		}

		$store = (array) get_option( self::OPTION, array() );
		unset( $store[ sanitize_key( $key ) ] );

		update_option( self::OPTION, $store, false );
	}

	/**
	 * Reduce a value to something printable.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function flatten( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'on', 'omninox-site-toolkit') : __( 'off', 'omninox-site-toolkit');
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return wp_json_encode( $value );
	}

	/**
	 * Display name of whoever is making the change.
	 *
	 * @return string
	 */
	private static function current_user_name() {
		$user = wp_get_current_user();

		return ( $user && $user->exists() ) ? $user->display_name : '';
	}
}
