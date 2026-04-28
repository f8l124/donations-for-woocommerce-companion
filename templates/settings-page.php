<?php
/**
 * Settings page template (admin).
 *
 * Variables in scope: none — page renders entirely from registered settings.
 *
 * @package DFWC\Companion
 */

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Admin\Settings_Page;
?>
<div class="wrap dfwc-settings">
	<h1><?php esc_html_e( 'Donations Companion — Settings', 'dfwc-companion' ); ?></h1>

	<?php settings_errors(); ?>

	<form method="post" action="options.php">
		<?php
		settings_fields( Settings_Page::OPTION_GROUP );
		do_settings_sections( 'dfwc-companion' );
		submit_button();
		?>
	</form>

	<?php
	/**
	 * Fires below the settings form. Phase 8 Preview_Controller hooks
	 * here to render the live preview pane showing what new campaigns
	 * inherit by default.
	 */
	do_action( 'dfwc_companion_after_settings_form' );
	?>
</div>
