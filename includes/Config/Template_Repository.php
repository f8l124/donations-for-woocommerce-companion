<?php
/**
 * Template_Repository — read/write the templates option.
 *
 * All templates live in a single autoload-off option `dfwc_companion_templates`
 * keyed by template ID. Stored shape:
 *
 *   [
 *     'version'   => 1,
 *     'templates' => [
 *       'school_sponsorship' => [ 'id' => ..., 'name' => ..., 'config' => ... ],
 *       ...
 *     ],
 *   ]
 *
 * Single-option storage trades scale (cap ~50 templates before option size
 * matters) for trivial enumeration and atomic save. Documented in
 * plans/v2/04-phase-3-templates-and-defaults.md §4.1 — when count grows past
 * ~50 the path forward is migration to a custom post type, not table sharding.
 *
 * On every save, registers the template's translatable strings with WPML
 * String Translation. Idempotent and a no-op when WPML inactive.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\I18n\WPML_Strings;

final class Template_Repository {

	public const OPTION_KEY      = 'dfwc_companion_templates';
	private const SCHEMA_VERSION = 1;

	/**
	 * Return all stored templates as a map of id => Template_Config.
	 *
	 * @return array<string,Template_Config>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored['templates'] ) || ! is_array( $stored['templates'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored['templates'] as $id => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			// Force the array's key onto the data, defending against drift.
			$data['id']     = (string) $id;
			$out[ (string) $id ] = Template_Config::from_array( $data );
		}
		return $out;
	}

	/**
	 * Fetch one template by ID. Null when absent.
	 */
	public function get( string $id ): ?Template_Config {
		$all = $this->all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Persist a template. Creates if new, replaces if existing.
	 *
	 * Side effects:
	 * - Touches the template's modified_at via the Template_Config mutator
	 *   (caller's responsibility); save() doesn't auto-touch to keep the
	 *   "save unchanged template" path side-effect-free.
	 * - Registers translatable strings with WPML String Translation.
	 * - Fires `dfwc_companion_template_saved` action on successful save.
	 */
	public function save( Template_Config $template ): bool {
		if ( '' === $template->id ) {
			return false;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored['version']                       = self::SCHEMA_VERSION;
		$stored['templates'][ $template->id ]    = $template->to_array();

		// Autoload off — the option is only read on admin pages and during
		// donor-form render. WP would otherwise load every templates option
		// on every page including the front of the site.
		$result = update_option( self::OPTION_KEY, $stored, false );
		if ( ! $result ) {
			return false;
		}

		$this->register_strings_with_wpml( $template );

		/**
		 * Fires after a template is successfully saved.
		 *
		 * @param string          $template_id
		 * @param Template_Config $template
		 */
		do_action( 'dfwc_companion_template_saved', $template->id, $template );

		return true;
	}

	/**
	 * Delete a template by ID. Returns false when the ID didn't exist.
	 *
	 * Note: campaigns referencing the deleted template via
	 * `_dfwc_companion_template_id` will resolve to global defaults at next
	 * read. The admin UI surfaces this in Templates_Page's delete-confirm
	 * dialog (Phase 3.10).
	 */
	public function delete( string $id ): bool {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored['templates'][ $id ] ) ) {
			return false;
		}
		unset( $stored['templates'][ $id ] );
		$result = update_option( self::OPTION_KEY, $stored, false );
		if ( ! $result ) {
			return false;
		}

		/**
		 * Fires after a template is successfully deleted.
		 *
		 * @param string $template_id
		 */
		do_action( 'dfwc_companion_template_deleted', $id );
		return true;
	}

	public function exists( string $id ): bool {
		return null !== $this->get( $id );
	}

	public function count(): int {
		return count( $this->all() );
	}

	/**
	 * Generate a stable template ID from a name. Suffixes `-2`, `-3`, etc.
	 * on collision so concurrent admin saves don't clobber.
	 */
	public function generate_id( string $name ): string {
		$base = sanitize_key( $name );
		if ( '' === $base ) {
			$base = 'template';
		}
		$candidate = $base;
		$counter   = 2;
		while ( $this->exists( $candidate ) ) {
			$candidate = $base . '-' . $counter;
			++$counter;
			if ( $counter > 1000 ) {
				// Defensive cap; unrealistic but bounds the loop.
				return $base . '-' . wp_rand( 1000, 999999 );
			}
		}
		return $candidate;
	}

	/**
	 * List campaign post IDs currently using a given template. Used by
	 * Templates_Page's delete confirmation flow + by Bulk_Actions for
	 * impact-aware messaging.
	 *
	 * @return array<int,int>
	 */
	public function campaign_ids_using( string $template_id ): array {
		if ( '' === $template_id ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => 'wc-donation',
				'post_status'      => 'any',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'         => Config_Resolver::META_KEY_TEMPLATE,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $template_id,
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Register a template's translatable strings with WPML's String
	 * Translation. Walk the config and register each known translatable field
	 * under a predictable key. Idempotent.
	 *
	 * Per AA-wpml-integration.md §3, registered names follow:
	 *   <template_id>.<interval>.<field>
	 *   <template_id>.<interval>.presets.<idx>.<field>
	 *   <template_id>.display.cause_heading
	 */
	private function register_strings_with_wpml( Template_Config $template ): void {
		if ( ! WPML_Strings::wpml_active() ) {
			return;
		}

		// Template name + description as standalone admin texts.
		WPML_Strings::register( $template->id . '.name', $template->name );
		WPML_Strings::register( $template->id . '.description', $template->description );

		// Walk all 7 intervals — advanced ones may have admin-defined strings
		// that need translation regardless of whether the global toggle is on
		// at the moment of save.
		$intervals = Config_Resolver::intervals( true );
		foreach ( $intervals as $interval ) {
			$block = $template->config[ $interval ] ?? null;
			if ( ! is_array( $block ) ) {
				continue;
			}
			$translatable_fields = array( 'cta_template', 'subtitle', 'annual_equivalency', 'custom_amount_impact_label' );
			if ( Config_Resolver::INTERVAL_CUSTOM === $interval ) {
				$translatable_fields[] = 'custom_label';
			}
			foreach ( $translatable_fields as $field ) {
				if ( ! empty( $block[ $field ] ) ) {
					WPML_Strings::register(
						$template->id . '.' . $interval . '.' . $field,
						(string) $block[ $field ]
					);
				}
			}
			foreach ( (array) ( $block['presets'] ?? array() ) as $idx => $preset ) {
				if ( ! is_array( $preset ) ) {
					continue;
				}
				foreach ( array( 'label', 'impact_label' ) as $preset_field ) {
					if ( ! empty( $preset[ $preset_field ] ) ) {
						WPML_Strings::register(
							$template->id . '.' . $interval . '.presets.' . (int) $idx . '.' . $preset_field,
							(string) $preset[ $preset_field ]
						);
					}
				}
			}
		}

		// Display options.
		if ( ! empty( $template->config['display']['cause_heading'] ) ) {
			WPML_Strings::register(
				$template->id . '.display.cause_heading',
				(string) $template->config['display']['cause_heading']
			);
		}
	}
}
