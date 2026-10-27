<?php
/**
 * Weighted scoring shared by every module.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Services;

defined( 'ABSPATH' ) || exit;

class ScoreCalculator {

	/**
	 * Penalty applied per severity.
	 *
	 * @var array
	 */
	private static $penalty = array(
		'critical' => 1.0,
		'warning'  => 0.5,
		'notice'   => 0.15,
		'pass'     => 0.0,
	);

	/**
	 * Derive a 0-100 score from a set of issues.
	 *
	 * @param array $issues Issues.
	 * @return int
	 */
	public static function from_issues( array $issues ) {
		if ( empty( $issues ) ) {
			return 100;
		}

		$total = 0.0;
		$lost  = 0.0;

		foreach ( $issues as $issue ) {
			$weight   = max( 0.1, (float) ( $issue['weight'] ?? 1 ) );
			$severity = $issue['severity'] ?? 'notice';
			$total   += $weight;
			$lost    += $weight * ( self::$penalty[ $severity ] ?? 0.15 );
		}

		if ( $total <= 0 ) {
			return 100;
		}

		return (int) max( 0, min( 100, round( ( 1 - ( $lost / $total ) ) * 100 ) ) );
	}

	/**
	 * Letter grade for a score.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	public static function grade( $score ) {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 80 ) {
			return 'B';
		}
		if ( $score >= 70 ) {
			return 'C';
		}
		if ( $score >= 60 ) {
			return 'D';
		}

		return 'F';
	}

	/**
	 * Count issues by severity.
	 *
	 * @param array $issues Issues.
	 * @return array
	 */
	public static function summarise( array $issues ) {
		$counts = array(
			'critical' => 0,
			'warning'  => 0,
			'notice'   => 0,
			'pass'     => 0,
			'total'    => count( $issues ),
		);

		foreach ( $issues as $issue ) {
			$severity = $issue['severity'] ?? 'notice';

			if ( isset( $counts[ $severity ] ) ) {
				++$counts[ $severity ];
			}
		}

		return $counts;
	}

	/**
	 * Weighted overall score across modules.
	 *
	 * @param array $scores Module id => score.
	 * @return int
	 */
	public static function overall( array $scores ) {
		$weights = apply_filters(
			'omninox_overall_weights',
			array(
				'security'      => 3,
				'performance'   => 3,
				'health'        => 2,
				'seo'           => 2,
				'database'      => 1,
				'media'         => 1,
				'accessibility' => 1,
			)
		);

		$sum   = 0;
		$total = 0;

		foreach ( $scores as $module => $score ) {
			$weight = $weights[ $module ] ?? 1;
			$sum   += $score * $weight;
			$total += $weight;
		}

		return $total ? (int) round( $sum / $total ) : 0;
	}
}
