<?php
/**
 * Server-rendered Gutenberg block: `dfwc-companion/recurring-donation`.
 *
 * Editor JS is registered manually so we can specify dependencies (the
 * `editorScript` field in block.json supports a registered handle name).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Block {

	public const NAME           = 'dfwc-companion/recurring-donation';
	public const EDITOR_HANDLE  = 'dfwc-companion-block-editor';

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		// Editor script registered manually so we can declare deps. block.json's
		// `editorScript` field references this handle name.
		wp_register_script(
			self::EDITOR_HANDLE,
			DFWC_COMPANION_URL . 'assets/blocks/recurring-donation/index.js',
			[
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-i18n',
				'wp-server-side-render',
				'wp-core-data',
			],
			DFWC_COMPANION_VERSION,
			true
		);

		register_block_type(
			DFWC_COMPANION_PATH . 'assets/blocks/recurring-donation',
			[ 'render_callback' => [ $this, 'render' ] ]
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function render( $attributes ): string {
		$campaign_id = isset( $attributes['campaignId'] ) ? absint( $attributes['campaignId'] ) : 0;

		if ( $campaign_id < 1 ) {
			return '<div class="dfwc-form__placeholder">'
				. esc_html__( 'Select a donation campaign in the block sidebar.', 'dfwc-companion' )
				. '</div>';
		}

		// Block render runs late enough that wp_enqueue_scripts has fired —
		// pull in the assets explicitly (registered earlier by Frontend\Assets).
		Assets::enqueue();

		return Renderer::render( $campaign_id );
	}
}
