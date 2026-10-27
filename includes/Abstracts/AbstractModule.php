<?php
/**
 * Base class shared by all modules.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Abstracts;

use OmninoxSiteToolkit\Interfaces\ModuleInterface;
use OmninoxSiteToolkit\Services\ScoreCalculator;
use OmninoxSiteToolkit\Database\ScanRepository;

defined( 'ABSPATH' ) || exit;

abstract class AbstractModule implements ModuleInterface {

	const SEV_CRITICAL = 'critical';
	const SEV_WARNING  = 'warning';
	const SEV_NOTICE   = 'notice';
	const SEV_PASS     = 'pass';

	/**
	 * Issues collected during the current scan.
	 *
	 * @var array
	 */
	protected $issues = array();

	/**
	 * Machine id.
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Label.
	 *
	 * @var string
	 */
	protected $label = '';

	/**
	 * Dashicon name for the admin UI.
	 *
	 * @var string
	 */
	protected $icon = 'admin-generic';

	/**
	 * Whether the module needs a premium licence.
	 *
	 * @var bool
	 */
	protected $premium = false;

	public function get_id() {
		return $this->id;
	}

	public function get_label() {
		return ! empty( $this->label ) ? $this->label : $this->id;
	}

	public function get_icon() {
		return $this->icon;
	}

	public function is_premium() {
		return (bool) $this->premium;
	}

	/**
	 * Whether the module should load at all (dependency check).
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Default: nothing to hook.
	 */
	public function register() {}

	/**
	 * Fixes are opt-in per module.
	 *
	 * @param string $action Action id.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		return new \WP_Error(
			'omninox_unsupported_action',
			/* translators: %s: action name */
			sprintf( __( 'The action "%s" is not supported by this module.', 'omninox-site-toolkit'), $action ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Actions this module exposes to the UI.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array();
	}

	/**
	 * Record a check result.
	 *
	 * @param array $issue Issue definition.
	 */
	protected function add( array $issue ) {
		$this->issues[] = wp_parse_args(
			$issue,
			array(
				'id'          => '',
				'label'       => '',
				'severity'    => self::SEV_NOTICE,
				'value'       => '',
				'expected'    => '',
				'description' => '',
				'fix'         => '',
				'fix_params'  => array(),
				'previous'    => '',
				'previous_summary' => '',
				'weight'      => 1,
				'group'       => '',
				'context'     => array(),
			)
		);
	}

	/**
	 * Convenience: pass/fail check.
	 *
	 * @param string $id        Check id.
	 * @param string $label     Label.
	 * @param bool   $passed    Result.
	 * @param array  $overrides Extra fields.
	 */
	protected function assert( $id, $label, $passed, array $overrides = array() ) {
		$this->add(
			array_merge(
				array(
					'id'       => $id,
					'label'    => $label,
					'severity' => $passed ? self::SEV_PASS : self::SEV_WARNING,
				),
				$overrides
			)
		);
	}

	/**
	 * Record a check and attach the value it held before we changed it.
	 *
	 * @param array  $issue       Issue definition.
	 * @param string $history_key Key used by the value history service.
	 */
	protected function add_tracked( array $issue, $history_key ) {
		$this->add( \OmninoxSiteToolkit\Services\ValueHistory::decorate( $issue, $history_key ) );
	}

	/**
	 * Build the normalised result payload and persist it.
	 *
	 * @param array $extra Extra payload (charts, tables...).
	 * @return array
	 */
	protected function result( array $extra = array() ) {
		$score = ScoreCalculator::from_issues( $this->issues );

		$payload = array_merge(
			array(
				'module'     => $this->get_id(),
				'label'      => $this->get_label(),
				'score'      => $score,
				'grade'      => ScoreCalculator::grade( $score ),
				'summary'    => ScoreCalculator::summarise( $this->issues ),
				'issues'     => array_values( $this->issues ),
				'actions'    => $this->get_actions(),
				'scanned_at' => current_time( 'mysql' ),
			),
			$extra
		);

		/**
		 * Filter a module scan result before it is stored.
		 *
		 * @param array          $payload Result payload.
		 * @param AbstractModule $module  Module instance.
		 */
		$payload = apply_filters( 'omninox_module_result', $payload, $this );

		ScanRepository::save( $this->get_id(), $payload );

		$this->issues = array();

		return $payload;
	}
}
