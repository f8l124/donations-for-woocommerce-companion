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
</div>
