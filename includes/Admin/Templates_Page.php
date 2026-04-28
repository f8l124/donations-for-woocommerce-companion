<?php
/**
 * Templates_Page — list + edit UI for named templates.
 *
 * Single page slug (`dfwc-companion-templates`); list mode is the default,
 * edit mode triggered by `?action=edit&id=<template_id>` or
 * `?action=new`. Save submits via admin-post.php to `dfwc_save_template`,
 * delete via `dfwc_delete_template` — both nonce + cap gated.
 *
 * Tight integration: the list view uses `<table class="wp-list-table widefat
 * fixed striped">` (admins recognize it instantly); the edit form uses
 * `<table class="form-table">` and `submit_button()`. No custom UI framework.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Config\Defaults;
use DFWC\Companion\Config\Template_Config;
use DFWC\Companion\Config\Template_Repository;
use DFWC\Companion\I18n\WPML_Strings;

final class Templates_Page {

	public const SAVE_ACTION    = 'dfwc_save_template';
	public const DELETE_ACTION  = 'dfwc_delete_template';
	public const SAVE_NONCE     = '_dfwc_template_nonce';
	public const DELETE_NONCE   = '_dfwc_template_delete_nonce';

	public function __construct() {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dfwc-companion' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing
		if ( 'edit' === $action || 'new' === $action ) {
			self::render_edit( $action );
			return;
		}
		self::render_list();
	}

	private static function render_list(): void {
		$repo      = new Template_Repository();
		$templates = $repo->all();
		$flash     = self::flash_message();

		include DFWC_COMPANION_PATH . 'templates/templates-page-list.php';
	}

	private static function render_edit( string $mode ): void {
		$repo    = new Template_Repository();
		$is_new  = 'new' === $mode;
		$tpl     = null;
		$id_in   = '';

		if ( ! $is_new ) {
			$id_in = isset( $_GET['id'] ) ? sanitize_key( (string) wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing
			$tpl   = '' !== $id_in ? $repo->get( $id_in ) : null;
			if ( ! $tpl ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . Admin_Menu::TEMPLATES_SLUG . '&error=not_found' ) );
				exit;
			}
		}

		// For new templates, build a skeleton with default config. Empty id +
		// auto-generated when admin saves.
		if ( $is_new ) {
			$tpl = new Template_Config( '', '', '', time(), time(), Defaults::for_campaign() );
		}

		$flash = self::flash_message();

		include DFWC_COMPANION_PATH . 'templates/templates-page-edit.php';
	}

	/**
	 * Save handler. Validates nonce + cap, sanitizes the form, persists via
	 * Template_Repository, redirects back to the edit screen with a flash.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}
		check_admin_referer( self::SAVE_ACTION, self::SAVE_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above
		$raw = isset( $_POST['template'] ) && is_array( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : array();
		// phpcs:enable

		$repo = new Template_Repository();

		$id          = isset( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '';
		$name        = isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : '';
		$description = isset( $raw['description'] ) ? sanitize_textarea_field( (string) $raw['description'] ) : '';

		if ( '' === $name ) {
			$this->redirect_with_flash( $id, 'error', __( 'Template name is required.', 'dfwc-companion' ) );
			return;
		}

		$config = $this->sanitize_config_from_post( $raw['config'] ?? array() );

		// New template: generate a unique ID from the name.
		if ( '' === $id ) {
			$id  = $repo->generate_id( $name );
			$now = time();
			$tpl = new Template_Config( $id, $name, $description, $now, $now, $config );
		} else {
			$existing = $repo->get( $id );
			if ( ! $existing ) {
				$this->redirect_with_flash( '', 'error', __( 'Template not found.', 'dfwc-companion' ) );
				return;
			}
			$tpl = new Template_Config(
				$id,
				$name,
				$description,
				$existing->created_at,
				time(),
				$config
			);
		}

		if ( ! $repo->save( $tpl ) ) {
			$this->redirect_with_flash( $id, 'error', __( 'Failed to save template.', 'dfwc-companion' ) );
			return;
		}

		$this->redirect_with_flash( $id, 'updated', __( 'Template saved.', 'dfwc-companion' ) );
	}

	/**
	 * Delete handler. Confirmation happens client-side; server validates
	 * nonce + cap and deletes.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'dfwc-companion' ) );
		}

		$id = isset( $_GET['id'] ) ? sanitize_key( (string) wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked next line
		check_admin_referer( self::DELETE_ACTION . '_' . $id, self::DELETE_NONCE );

		$repo = new Template_Repository();
		$ok   = $repo->delete( $id );

		$flash_key  = $ok ? 'updated' : 'error';
		$flash_msg  = $ok
			? __( 'Template deleted.', 'dfwc-companion' )
			: __( 'Failed to delete template.', 'dfwc-companion' );

		wp_safe_redirect(
			add_query_arg(
				array( $flash_key => rawurlencode( $flash_msg ) ),
				admin_url( 'admin.php?page=' . Admin_Menu::TEMPLATES_SLUG )
			)
		);
		exit;
	}

	/**
	 * Sanitize the per-template config blob from POST.
	 *
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_config_from_post( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return Defaults::for_campaign();
		}

		$config = Defaults::for_campaign();

		// Default interval (allow-listed).
		if ( isset( $raw['default_interval'] ) ) {
			$value = sanitize_key( (string) $raw['default_interval'] );
			if ( in_array( $value, Config_Resolver::intervals(), true ) ) {
				$config['default_interval'] = $value;
			}
		}

		foreach ( Config_Resolver::intervals() as $interval ) {
			$block_raw          = is_array( $raw[ $interval ] ?? null ) ? $raw[ $interval ] : array();
			$config[ $interval ] = $this->sanitize_interval_block( $block_raw, $config[ $interval ] );
		}

		// Display options.
		$display_raw = is_array( $raw['display'] ?? null ) ? $raw['display'] : array();
		$config['display'] = array(
			'show_title'    => ! empty( $display_raw['show_title'] ),
			'show_image'    => ! empty( $display_raw['show_image'] ),
			'cause_heading' => isset( $display_raw['cause_heading'] )
				? sanitize_text_field( (string) $display_raw['cause_heading'] )
				: '',
		);

		return $config;
	}

	/**
	 * Sanitize a single interval block. Mirrors Meta_Box::sanitize_interval_block
	 * but lives here so the Templates_Page is self-contained for testing.
	 *
	 * @param array<string,mixed> $raw
	 * @param array<string,mixed> $defaults Block defaults to fall back to.
	 * @return array<string,mixed>
	 */
	private function sanitize_interval_block( array $raw, array $defaults ): array {
		$enabled               = ! empty( $raw['enabled'] );
		$custom_amount_enabled = ! empty( $raw['custom_amount_enabled'] );

		$presets_raw = isset( $raw['presets'] ) && is_array( $raw['presets'] ) ? $raw['presets'] : array();
		$presets     = array();
		foreach ( $presets_raw as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$amount_str = isset( $row['amount'] ) ? (string) $row['amount'] : '';
			$amount     = (float) wc_format_decimal( $amount_str, wc_get_price_decimals() );
			if ( $amount <= 0 ) {
				continue;
			}
			$presets[] = array(
				'amount'       => $amount,
				'label'        => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'impact_label' => isset( $row['impact_label'] ) ? sanitize_text_field( (string) $row['impact_label'] ) : '',
				'is_featured'  => ! empty( $row['is_featured'] ),
				'sort_order'   => isset( $row['sort_order'] ) ? (int) $row['sort_order'] : ( ( $idx + 1 ) * 10 ),
			);
		}

		// Enforce single-featured per interval (last-write-wins).
		$found_featured = false;
		foreach ( $presets as $i => $preset ) {
			if ( $preset['is_featured'] && $found_featured ) {
				$presets[ $i ]['is_featured'] = false;
			} elseif ( $preset['is_featured'] ) {
				$found_featured = true;
			}
		}

		$min = isset( $raw['min'] ) ? (float) wc_format_decimal( (string) $raw['min'], wc_get_price_decimals() ) : (float) $defaults['min'];
		$max = isset( $raw['max'] ) ? (float) wc_format_decimal( (string) $raw['max'], wc_get_price_decimals() ) : (float) $defaults['max'];
		if ( $min < 0.01 ) {
			$min = 0.01;
		}
		if ( $max < $min ) {
			$max = $min;
		}

		$default_index = isset( $raw['default_index'] ) ? (int) $raw['default_index'] : 0;
		$preset_count  = count( $presets );
		if ( $preset_count > 0 ) {
			$default_index = max( 0, min( $default_index, $preset_count - 1 ) );
		} else {
			$default_index = 0;
		}

		$cta = isset( $raw['cta_template'] ) ? sanitize_text_field( (string) $raw['cta_template'] ) : (string) $defaults['cta_template'];

		// Phase 5 — donor impact messaging fields.
		$subtitle                   = isset( $raw['subtitle'] ) ? sanitize_text_field( (string) $raw['subtitle'] ) : (string) ( $defaults['subtitle'] ?? '' );
		$annual_equivalency         = isset( $raw['annual_equivalency'] ) ? sanitize_text_field( (string) $raw['annual_equivalency'] ) : (string) ( $defaults['annual_equivalency'] ?? '' );
		$custom_amount_impact_label = isset( $raw['custom_amount_impact_label'] ) ? sanitize_text_field( (string) $raw['custom_amount_impact_label'] ) : (string) ( $defaults['custom_amount_impact_label'] ?? '' );
		$impact_mode                = isset( $raw['impact_display_mode'] ) ? sanitize_key( (string) $raw['impact_display_mode'] ) : (string) ( $defaults['impact_display_mode'] ?? 'below_button' );
		if ( ! in_array( $impact_mode, Defaults::impact_display_modes(), true ) ) {
			$impact_mode = 'below_button';
		}

		$currency_overrides = Currency_Preset_Resolver::sanitize_currency_overrides(
			$raw['currency_overrides'] ?? null,
			count( $presets )
		);

		return array(
			'enabled'                    => $enabled,
			'presets'                    => $presets,
			'min'                        => $min,
			'max'                        => $max,
			'default_index'              => $default_index,
			'cta_template'               => $cta,
			'custom_amount_enabled'      => $custom_amount_enabled,
			'subtitle'                   => $subtitle,
			'annual_equivalency'         => $annual_equivalency,
			'impact_display_mode'        => $impact_mode,
			'custom_amount_impact_label' => $custom_amount_impact_label,
			'currency_overrides'         => $currency_overrides,
		);
	}

	/**
	 * Build a delete-confirm URL with a per-id nonce.
	 */
	public static function delete_url( string $template_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DELETE_ACTION,
					'id'     => $template_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::DELETE_ACTION . '_' . $template_id,
			self::DELETE_NONCE
		);
	}

	/**
	 * Build a duplicate URL — POSTs to save handler with a fresh ID.
	 * For simplicity Phase 3 uses a pre-population redirect: clicking
	 * "Duplicate" routes the admin to the edit screen of a brand-new
	 * template seeded with the source's config.
	 */
	public static function duplicate_url( string $template_id ): string {
		return add_query_arg(
			array(
				'page'       => Admin_Menu::TEMPLATES_SLUG,
				'action'     => 'new',
				'duplicate'  => $template_id,
			),
			admin_url( 'admin.php' )
		);
	}

	private function redirect_with_flash( string $id, string $type, string $message ): void {
		$args = array( $type => rawurlencode( $message ) );
		if ( '' !== $id ) {
			$args['action'] = 'edit';
			$args['id']     = $id;
		}
		wp_safe_redirect(
			add_query_arg( $args, admin_url( 'admin.php?page=' . Admin_Menu::TEMPLATES_SLUG ) )
		);
		exit;
	}

	/**
	 * Read flash message from query string. Returns
	 * `[ type => 'updated'|'error'|null, message => string ]`.
	 *
	 * @return array{type:?string,message:string}
	 */
	private static function flash_message(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash from redirect
		if ( isset( $_GET['updated'] ) ) {
			return array(
				'type'    => 'updated',
				'message' => sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['updated'] ) ) ),
			);
		}
		if ( isset( $_GET['error'] ) ) {
			return array(
				'type'    => 'error',
				'message' => sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['error'] ) ) ),
			);
		}
		// phpcs:enable
		return array(
			'type' => null,
			'message' => '',
		);
	}
}
