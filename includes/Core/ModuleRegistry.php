<?php
/**
 * Discovers, holds and dispatches to modules.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Core;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Interfaces\ModuleInterface;
use OmninoxSiteToolkit\License\LicenseManager;
use OmninoxSiteToolkit\License\FeatureGate;

defined( 'ABSPATH' ) || exit;

class ModuleRegistry {

	use Singleton;

	/**
	 * Registered modules keyed by id.
	 *
	 * @var ModuleInterface[]
	 */
	private $modules = array();

	/**
	 * Default class map. Third parties can extend it via the filter below.
	 *
	 * @return array
	 */
	public static function class_map() {
		$map = array(
			'health'        => '\OmninoxSiteToolkit\Modules\Health\HealthModule',
			'performance'   => '\OmninoxSiteToolkit\Modules\Performance\PerformanceModule',
			'assets'        => '\OmninoxSiteToolkit\Modules\Performance\AssetManagerModule',
			'database'      => '\OmninoxSiteToolkit\Modules\Database\DatabaseModule',
			'media'         => '\OmninoxSiteToolkit\Modules\Media\MediaModule',
			'seo'           => '\OmninoxSiteToolkit\Modules\SEO\SeoModule',
			'accessibility' => '\OmninoxSiteToolkit\Modules\Accessibility\AccessibilityModule',
			'security'      => '\OmninoxSiteToolkit\Modules\Security\SecurityModule',
			'conflicts'     => '\OmninoxSiteToolkit\Modules\Performance\ConflictModule',
			'analytics'     => '\OmninoxSiteToolkit\Modules\Analytics\AnalyticsModule',
			'errors'        => '\OmninoxSiteToolkit\Modules\Errors\ErrorLogModule',
			'redirects'     => '\OmninoxSiteToolkit\Modules\Redirects\RedirectsModule',
			'developer'     => '\OmninoxSiteToolkit\Modules\Developer\DeveloperModule',
			'backup'        => '\OmninoxSiteToolkit\Modules\Backup\BackupGate',
			'search'        => '\OmninoxSiteToolkit\Modules\Search\SearchModule',
		);

		/**
		 * Filter the module class map.
		 *
		 * @param array $map Module id => class name.
		 */
		return apply_filters( 'omninox_module_class_map', $map );
	}

	/**
	 * Instantiate and register every available module.
	 */
	public function discover() {
		foreach ( self::class_map() as $id => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$module = new $class();

			if ( ! $module instanceof ModuleInterface || ! $module->is_available() ) {
				continue;
			}

			$this->modules[ $id ] = $module;
			$module->register();
		}

		do_action( 'omninox_modules_registered', $this );
	}

	/**
	 * Explicitly register or replace a module in the registry.
	 *
	 * @param string          $id     Module ID.
	 * @param ModuleInterface $module Module instance.
	 */
	public function register( $id, ModuleInterface $module ) {
		$this->modules[ $id ] = $module;
		$module->register();
	}

	/**
	 * All modules.
	 *
	 * @return ModuleInterface[]
	 */
	public function all() {
		return $this->modules;
	}

	/**
	 * Get one module.
	 *
	 * @param string $id Module id.
	 * @return ModuleInterface|null
	 */
	public function get( $id ) {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Lightweight descriptor list for the UI.
	 *
	 * @return array
	 */
	public function describe() {
		$out     = array();
		$premium = LicenseManager::instance()->is_premium();

		foreach ( $this->modules as $id => $module ) {
			$module_pro = FeatureGate::is_module_pro( $id );

			$actions = method_exists( $module, 'get_actions' ) ? (array) $module->get_actions() : array();

			// Decorate every action with its tier and a locked flag so the UI
			// can render Pro badges / lock overlays without a second lookup.
			foreach ( $actions as &$action ) {
				$action_id = isset( $action['id'] ) ? $action['id'] : null;
				$tier      = FeatureGate::tier( $id, $action_id );

				$action['tier']   = $tier;
				$action['locked'] = ( FeatureGate::TIER_PRO === $tier ) && ! $premium;
			}
			unset( $action );

			$out[] = array(
				'id'       => $id,
				'label'    => $module->get_label(),
				'icon'     => method_exists( $module, 'get_icon' ) ? $module->get_icon() : 'admin-generic',
				'premium'  => $module_pro,
				'tier'     => $module_pro ? FeatureGate::TIER_PRO : FeatureGate::TIER_FREE,
				'unlocked' => ! $module_pro || $premium,
				'locked'   => $module_pro && ! $premium,
				'actions'  => $actions,
			);
		}

		return $out;
	}
}
