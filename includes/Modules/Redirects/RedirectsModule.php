<?php
/**
 * Redirects: turn logged 404s into working redirects.
 *
 * The error log already records every 404 with a hit count, which is exactly
 * the data needed to decide what is worth redirecting. This module reads that
 * and applies the rules, so the two features reinforce each other instead of
 * duplicating storage.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Redirects;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Database\Schema;

defined( 'ABSPATH' ) || exit;

class RedirectsModule extends AbstractModule {

	protected $id   = 'redirects';
	protected $icon = 'randomize';

	const OPTION = 'omninox_redirects';

	public function get_label() {
		return __( 'Redirects & 404s', 'omninox-site-toolkit' );
	}

	/**
	 * Apply redirects early, before WordPress decides the request is a 404.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Send the visitor on if a rule matches the current request.
	 */
	public function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}

		$rules = $this->rules();

		if ( empty( $rules ) ) {
			return;
		}

		$path = $this->current_path();

		$regex_allowed = \OmninoxSiteToolkit\License\FeatureGate::can_use( 'redirects', 'regex_redirect' );

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			// Regex / wildcard matching is a Pro capability; on free installs
			// such rules are simply skipped, plain 301/302 rules keep working.
			if ( ! empty( $rule['regex'] ) && ! $regex_allowed ) {
				continue;
			}

			$matched = ! empty( $rule['regex'] )
				? @preg_match( '#' . str_replace( '#', '\#', $rule['from'] ) . '#i', $path ) // phpcs:ignore WordPress.PHP.NoSilencedErrors
				: ( untrailingslashit( $rule['from'] ) === untrailingslashit( $path ) );

			if ( ! $matched ) {
				continue;
			}

			$target = ! empty( $rule['regex'] )
				? preg_replace( '#' . str_replace( '#', '\#', $rule['from'] ) . '#i', $rule['to'], $path )
				: $rule['to'];

			$this->count_hit( $rule['from'] );

			$destination = wp_validate_redirect( $this->absolute( $target ), home_url() );
			wp_safe_redirect( $destination, (int) $rule['status'] );
			exit;
		}
	}

	/**
	 * Actions exposed to the interface.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array(
			array(
				'id'     => 'list_404s',
				'label'  => __( 'Most requested missing URLs', 'omninox-site-toolkit'),
				'type'   => 'inspector',
				'notice' => __( 'Collected by the error log. The highest hit counts are usually old URLs from a previous site or a broken external link worth reclaiming.', 'omninox-site-toolkit'),
			),
			array(
				'id'    => 'list_redirects',
				'label' => __( 'Active redirect rules', 'omninox-site-toolkit'),
				'type'  => 'inspector',
			),
			array(
				'id'     => 'add_redirect',
				'label'  => __( 'Add a redirect', 'omninox-site-toolkit'),
				'type'   => 'form',
				'submit' => __( 'Add rule', 'omninox-site-toolkit'),
				'notice' => __( 'Use 301 for a permanent move so search engines transfer the ranking. Use 302 only while something is temporarily elsewhere.', 'omninox-site-toolkit'),
				'fields' => array(
					array( 'id' => 'from', 'label' => __( 'Old path', 'omninox-site-toolkit'), 'type' => 'text', 'placeholder' => '/old-page/' ),
					array( 'id' => 'to', 'label' => __( 'New path or URL', 'omninox-site-toolkit'), 'type' => 'text', 'placeholder' => '/new-page/' ),
					array(
						'id'      => 'status',
						'label'   => __( 'Type', 'omninox-site-toolkit'),
						'type'    => 'select',
						'options' => array(
							array( 'value' => 301, 'label' => __( '301 permanent', 'omninox-site-toolkit') ),
							array( 'value' => 302, 'label' => __( '302 temporary', 'omninox-site-toolkit') ),
							array( 'value' => 410, 'label' => __( '410 gone', 'omninox-site-toolkit') ),
						),
					),
				),
			),
		);
	}

	/**
	 * Report on 404 volume and redirect coverage.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$missing = $this->top_404s( 50 );
		$rules   = $this->rules();
		$total   = 0;

		foreach ( $missing as $row ) {
			$total += (int) $row['hits'];
		}

		$this->add(
			array(
				'id'          => 'not_found_requests',
				'label'       => __( 'Requests for missing pages, last 30 days', 'omninox-site-toolkit'),
				'value'       => number_format_i18n( $total ),
				'expected'    => '< 50',
				'weight'      => 2,
				'severity'    => $total < 50 ? self::SEV_PASS : ( $total < 500 ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'description' => __( 'Each of these is a visitor or a search engine hitting a dead end. High counts on a single path usually mean an old URL that still has links pointing at it.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => array_slice( $missing, 0, 30 ) ),
			)
		);

		$unhandled = array();

		foreach ( $missing as $row ) {
			if ( (int) $row['hits'] < 5 ) {
				continue;
			}

			$covered = false;

			foreach ( $rules as $rule ) {
				if ( untrailingslashit( $rule['from'] ) === untrailingslashit( $row['path'] ) ) {
					$covered = true;
					break;
				}
			}

			if ( ! $covered ) {
				$unhandled[] = $row;
			}
		}

		$this->add(
			array(
				'id'          => 'unhandled_404s',
				'label'       => __( 'Frequently missed URLs with no redirect', 'omninox-site-toolkit'),
				'value'       => (string) count( $unhandled ),
				'expected'    => '0',
				'weight'      => 2,
				'severity'    => empty( $unhandled ) ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'Anything hit five or more times is worth pointing somewhere useful rather than leaving as a dead end.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => array_slice( $unhandled, 0, 30 ) ),
			)
		);

		$this->add(
			array(
				'id'       => 'redirect_rules',
				'label'    => __( 'Redirect rules in place', 'omninox-site-toolkit'),
				'value'    => (string) count( $rules ),
				'weight'   => 1,
				'severity' => self::SEV_PASS,
				'context'  => array( 'items' => array_slice( $rules, 0, 40 ) ),
			)
		);

		$loops = $this->detect_loops( $rules );

		$this->add(
			array(
				'id'          => 'redirect_loops',
				'label'       => __( 'Redirect loops and chains', 'omninox-site-toolkit'),
				'value'       => (string) count( $loops ),
				'expected'    => '0',
				'weight'      => 3,
				'severity'    => empty( $loops ) ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => __( 'A rule pointing at a path that is itself redirected wastes a round trip. A rule pointing back at its own source makes the page unreachable entirely.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => $loops ),
			)
		);

		return $this->result( array( 'rules' => $rules ) );
	}

	/**
	 * Handle the redirect actions.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		switch ( $action ) {
			case 'list_404s':
				return array( 'rows' => $this->top_404s( 100 ) );

			case 'list_redirects':
				// Include module id so the admin UI can render per-row actions.
				return array( 'rows' => $this->rules(), 'module' => $this->id );

			case 'add_redirect':
				return $this->add_rule( $params );

			case 'edit_redirect':
				return $this->edit_rule( $params );

			case 'delete_redirect':
				return $this->remove_rule( $params['from'] ?? '' );

			case 'toggle_redirect':
				return $this->toggle_rule( $params['from'] ?? '', isset( $params['enabled'] ) ? (bool) $params['enabled'] : false );
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Store a new rule.
	 *
	 * @param array $params Rule data.
	 * @return array|\WP_Error
	 */
	private function add_rule( array $params ) {
		$from = $this->normalise_path( $params['from'] ?? '' );
		$to   = trim( (string) ( $params['to'] ?? '' ) );

		if ( '' === $from ) {
			return new \WP_Error( 'omninox_missing_from', __( 'Enter the old path, starting with a slash.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		$status = (int) ( $params['status'] ?? 301 );

		if ( ! in_array( $status, array( 301, 302, 410 ), true ) ) {
			$status = 301;
		}

		if ( 410 !== $status && '' === $to ) {
			return new \WP_Error( 'omninox_missing_to', __( 'Enter where the visitor should be sent.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		if ( untrailingslashit( $from ) === untrailingslashit( $this->normalise_path( $to ) ) ) {
			return new \WP_Error( 'omninox_self_redirect', __( 'That rule points at itself, which would make the page unreachable.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		$rules = $this->rules();

		foreach ( $rules as $index => $rule ) {
			if ( untrailingslashit( $rule['from'] ) === untrailingslashit( $from ) ) {
				unset( $rules[ $index ] );
			}
		}

		$rules[] = array(
			'from'    => $from,
			'to'      => $to,
			'status'  => $status,
			'regex'   => ! empty( $params['regex'] ),
			'enabled' => true,
			'hits'    => 0,
			'created' => current_time( 'mysql' ),
		);

		update_option( self::OPTION, array_values( $rules ), false );

		return array(
			'message' => sprintf(
				/* translators: 1: old path, 2: new path, 3: status code */
				__( 'Redirecting %1$s to %2$s with a %3$d.', 'omninox-site-toolkit'),
				$from,
				$to ? $to : __( 'nowhere (gone)', 'omninox-site-toolkit'),
				$status
			),
			'rows'    => $this->rules(),
		);
	}


	/**
	 * Edit an existing rule.
	 *
	 * Accepted params: original (the existing from path), from, to, status, regex, enabled
	 *
	 * @param array $params Rule data.
	 * @return array|\WP_Error
	 */
	private function edit_rule( array $params ) {
		$original = $this->normalise_path( $params['original'] ?? ( $params['from'] ?? '' ) );

		if ( '' === $original ) {
			return new \WP_Error( 'omninox_missing_original', __( 'Specify the original path to edit.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		// Remove the original rule if it exists.
		$rules = $this->rules();
		$kept  = array();
		$found = false;

		foreach ( $rules as $rule ) {
			if ( untrailingslashit( $rule['from'] ) === untrailingslashit( $original ) ) {
				$found = true;
				continue;
			}
			$kept[] = $rule;
		}

		if ( ! $found ) {
			return new \WP_Error( 'omninox_rule_missing', __( 'No rule matches that path.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
		}

		// Build the updated rule using the same validation as add_rule.
		$from = $this->normalise_path( $params['from'] ?? $original );
		$to   = trim( (string) ( $params['to'] ?? '' ) );

		if ( '' === $from ) {
			return new \WP_Error( 'omninox_missing_from', __( 'Enter the old path, starting with a slash.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		$status = (int) ( $params['status'] ?? 301 );

		if ( ! in_array( $status, array( 301, 302, 410 ), true ) ) {
			$status = 301;
		}

		if ( 410 !== $status && '' === $to ) {
			return new \WP_Error( 'omninox_missing_to', __( 'Enter where the visitor should be sent.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		if ( untrailingslashit( $from ) === untrailingslashit( $to ) ) {
			return new \WP_Error( 'omninox_self_redirect', __( 'That rule points at itself, which would make the page unreachable.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		$kept[] = array(
			'from'    => $from,
			'to'      => $to,
			'status'  => $status,
			'regex'   => ! empty( $params['regex'] ),
			'enabled' => isset( $params['enabled'] ) ? (bool) $params['enabled'] : true,
			'hits'    => 0,
			'created' => current_time( 'mysql' ),
		);

		update_option( self::OPTION, array_values( $kept ), false );

		return array(
			'message' => __( 'Rule updated.', 'omninox-site-toolkit'),
			'rows'    => $this->rules(),
		);
	}

	/**
	 * Toggle a rule's enabled state.
	 *
	 * @param string $from    Source path.
	 * @param bool   $enabled Enabled state.
	 * @return array|\WP_Error
	 */
	private function toggle_rule( $from, $enabled ) {
		$from  = $this->normalise_path( $from );
		$rules = $this->rules();
		$changed = false;

		foreach ( $rules as $index => $rule ) {
			if ( untrailingslashit( $rule['from'] ) === untrailingslashit( $from ) ) {
				$rules[ $index ]['enabled'] = (bool) $enabled;
				$changed = true;
				break;
			}
		}

		if ( ! $changed ) {
			return new \WP_Error( 'omninox_rule_missing', __( 'No rule matches that path.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
		}

		update_option( self::OPTION, $rules, false );

		return array( 'message' => $enabled ? __( 'Rule enabled.', 'omninox-site-toolkit') : __( 'Rule disabled.', 'omninox-site-toolkit'), 'rows' => $rules );
	}

	/**
	 * Delete a rule.
	 *
	 * @param string $from Source path.
	 * @return array|\WP_Error
	 */
	private function remove_rule( $from ) {
		$from  = $this->normalise_path( $from );
		$rules = $this->rules();
		$kept  = array();

		foreach ( $rules as $rule ) {
			if ( untrailingslashit( $rule['from'] ) !== untrailingslashit( $from ) ) {
				$kept[] = $rule;
			}
		}

		if ( count( $kept ) === count( $rules ) ) {
			return new \WP_Error( 'omninox_rule_missing', __( 'No rule matches that path.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
		}

		update_option( self::OPTION, $kept, false );

		return array( 'message' => __( 'Rule removed.', 'omninox-site-toolkit'), 'rows' => $kept );
	}

	/**
	 * Stored rules.
	 *
	 * @return array
	 */
	private function rules() {
		return (array) get_option( self::OPTION, array() );
	}

	/**
	 * Increment the hit counter for a rule.
	 *
	 * @param string $from Source path.
	 */
	private function count_hit( $from ) {
		$rules = $this->rules();

		foreach ( $rules as $index => $rule ) {
			if ( $rule['from'] === $from ) {
				$rules[ $index ]['hits'] = (int) ( $rule['hits'] ?? 0 ) + 1;
				break;
			}
		}

		update_option( self::OPTION, $rules, false );
	}

	/**
	 * The most requested missing paths from the error log.
	 *
	 * @param int $limit Row limit.
	 * @return array
	 */
	private function top_404s( $limit = 50 ) {
		global $wpdb;

		$table = Schema::table( 'logs' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT request_uri, message, hits, created_at FROM {$table}
				 WHERE type = '404' AND created_at >= %s
				 ORDER BY hits DESC LIMIT %d",
				gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ),
				absint( $limit )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$path = $this->normalise_path( $row['request_uri'] );

			if ( '' === $path ) {
				continue;
			}

			$out[] = array(
				'path'      => $path,
				'hits'      => (int) $row['hits'],
				'last_seen' => $row['created_at'],
			);
		}

		return $out;
	}

	/**
	 * Rules whose target is itself redirected, or which point at themselves.
	 *
	 * @param array $rules Rules.
	 * @return array
	 */
	private function detect_loops( array $rules ) {
		$targets = array();
		$found   = array();

		foreach ( $rules as $rule ) {
			$targets[ untrailingslashit( $this->normalise_path( $rule['to'] ) ) ] = $rule['from'];
		}

		foreach ( $rules as $rule ) {
			$source = untrailingslashit( $rule['from'] );
			$target = untrailingslashit( $this->normalise_path( $rule['to'] ) );

			if ( $source === $target ) {
				$found[] = array( 'from' => $rule['from'], 'to' => $rule['to'], 'problem' => __( 'points at itself', 'omninox-site-toolkit') );
				continue;
			}

			foreach ( $rules as $other ) {
				if ( untrailingslashit( $other['from'] ) === $target ) {
					$found[] = array(
						'from'    => $rule['from'],
						'to'      => $rule['to'],
						'problem' => sprintf(
							/* translators: %s: final destination */
							__( 'chains onward to %s', 'omninox-site-toolkit'),
							$other['to']
						),
					);
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * The path of the current request.
	 *
	 * @return string
	 */
	private function current_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return $this->normalise_path( $request_uri );
	}

	/**
	 * Reduce a URL or path to a leading-slash path with no query string.
	 *
	 * @param string $value URL or path.
	 * @return string
	 */
	private function normalise_path( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $value ) ) {
			$value = (string) wp_parse_url( $value, PHP_URL_PATH );
		}

		$value = strtok( $value, '?' );
		$value = strtok( $value, '#' );

		return '/' . ltrim( (string) $value, '/' );
	}

	/**
	 * Turn a stored target into something safe to redirect to.
	 *
	 * @param string $target Path or URL.
	 * @return string
	 */
	private function absolute( $target ) {
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}

		return home_url( '/' . ltrim( (string) $target, '/' ) );
	}
}
