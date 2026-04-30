<?php
/**
 * Stock_Pledge_Post_Type — registers the `dfwc_stock_pledge` custom post type.
 *
 * Each pledge is a discrete record with its own status workflow:
 *   pledged    →   in_transit   →   received   →   (terminal)
 *                                       ↘
 *                                        cancelled
 *
 * Status is tracked in the post's `_dfwc_stock_pledge_status` meta key (NOT
 * the WP `post_status`, which stays `publish` throughout — this is so the
 * post is queryable via standard WP_Query without filtering on `any` status).
 *
 * Why a CPT and not a WC order: stock donations don't move through WC's
 * cart/checkout pipeline. The donor pledges, the broker transfers shares
 * later (days to weeks), the admin reconciles when shares arrive. A CPT
 * lets us model that workflow without fighting WC's order state machine
 * or HPOS migration paths. Phase 9 hooks fire when the admin marks
 * received — that's when the donation is "real" from a CRM/analytics POV.
 *
 * Admin-only post type — donors don't read or edit pledge posts directly;
 * the donor flow uses the dedicated REST endpoint and email notifications.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Stock;

defined( 'ABSPATH' ) || exit;

final class Stock_Pledge_Post_Type {

	public const POST_TYPE = 'dfwc_stock_pledge';

	public const STATUS_PLEDGED    = 'pledged';
	public const STATUS_IN_TRANSIT = 'in_transit';
	public const STATUS_RECEIVED   = 'received';
	public const STATUS_CANCELLED  = 'cancelled';

	// Meta keys stored on the pledge post.
	public const META_DONOR_NAME       = '_dfwc_stock_pledge_donor_name';
	public const META_DONOR_EMAIL      = '_dfwc_stock_pledge_donor_email';
	public const META_DONOR_PHONE      = '_dfwc_stock_pledge_donor_phone';
	public const META_BROKER_NAME      = '_dfwc_stock_pledge_broker_name';
	public const META_TICKER           = '_dfwc_stock_pledge_ticker';
	public const META_SHARES           = '_dfwc_stock_pledge_shares';
	public const META_ESTIMATED_VALUE  = '_dfwc_stock_pledge_estimated_value';
	public const META_ACTUAL_VALUE     = '_dfwc_stock_pledge_actual_value';
	public const META_RECEIVED_AT      = '_dfwc_stock_pledge_received_at';
	public const META_CAMPAIGN_ID      = '_dfwc_stock_pledge_campaign_id';
	public const META_STATUS           = '_dfwc_stock_pledge_status';
	public const META_DONOR_NOTES      = '_dfwc_stock_pledge_donor_notes';
	public const META_ADMIN_NOTES      = '_dfwc_stock_pledge_admin_notes';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Stock Pledges', 'dfwc-companion' ),
					'singular_name'      => __( 'Stock Pledge', 'dfwc-companion' ),
					'menu_name'          => __( 'Stock Pledges', 'dfwc-companion' ),
					'name_admin_bar'     => __( 'Stock Pledge', 'dfwc-companion' ),
					'add_new'            => __( 'Add New', 'dfwc-companion' ),
					'add_new_item'       => __( 'Add New Pledge', 'dfwc-companion' ),
					'edit_item'          => __( 'Edit Pledge', 'dfwc-companion' ),
					'new_item'           => __( 'New Pledge', 'dfwc-companion' ),
					'view_item'          => __( 'View Pledge', 'dfwc-companion' ),
					'search_items'       => __( 'Search Pledges', 'dfwc-companion' ),
					'not_found'          => __( 'No pledges found.', 'dfwc-companion' ),
					'not_found_in_trash' => __( 'No pledges found in Trash.', 'dfwc-companion' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,  // mounted under Donations Companion submenu via Admin_Menu
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'capability_type'     => 'post',
				'capabilities'        => array(
					'create_posts' => 'do_not_allow',  // admins create via reconciliation UI; donors via REST
				),
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'custom-fields' ),
				'menu_icon'           => 'dashicons-money-alt',
			)
		);
	}

	/**
	 * Allow-listed status values. Used by sanitizers + admin UI.
	 *
	 * @return array<int,string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PLEDGED,
			self::STATUS_IN_TRANSIT,
			self::STATUS_RECEIVED,
			self::STATUS_CANCELLED,
		);
	}

	/**
	 * Human-readable label for a status. Translatable.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case self::STATUS_PLEDGED:
				return __( 'Pledged', 'dfwc-companion' );
			case self::STATUS_IN_TRANSIT:
				return __( 'In transit', 'dfwc-companion' );
			case self::STATUS_RECEIVED:
				return __( 'Received', 'dfwc-companion' );
			case self::STATUS_CANCELLED:
				return __( 'Cancelled', 'dfwc-companion' );
			default:
				return $status;
		}
	}
}
