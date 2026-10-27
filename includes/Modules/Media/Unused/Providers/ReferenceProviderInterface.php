<?php
/**
 * Unused Media Reference Provider Interface.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused\Providers;

use OmninoxSiteToolkit\Modules\Media\Unused\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

interface ReferenceProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable provider label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Collect references into the index.
	 *
	 * @param ReferenceIndex $index Reference index instance.
	 * @param array          $options Scan options.
	 * @return void
	 */
	public function collect_references( ReferenceIndex $index, array $options = array() ): void;
}
