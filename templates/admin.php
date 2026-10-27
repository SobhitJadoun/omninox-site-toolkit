<?php
/**
 * Admin app shell.
 *
 * @package OmninoxSiteToolkit
 * @var array $branding Branding values.
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	class="wrap omninox-wrap"
	id="omninox-root"
	data-accent="<?php echo esc_attr( $branding['accent'] ); ?>"
>
	<noscript>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'This screen needs JavaScript. Enable it and reload the page.', 'omninox-site-toolkit'); ?></p>
		</div>
	</noscript>

	<div class="omninox-boot" role="status">
		<span class="omninox-spinner" aria-hidden="true"></span>
		<?php esc_html_e( 'Loading toolkit…', 'omninox-site-toolkit'); ?>
	</div>
</div>
