<?php
/**
 * Goal_State — read-only view onto the parent plugin's campaign goal +
 * raised-amount tracking.
 *
 * The parent stores:
 * - Goal target on the campaign post:   `wc-donation-goal-fixed-amount-field`
 * - Initial seed on the campaign post:  `wc-donation-goal-fixed-initial-amount-field`
 * - Goal type on the campaign post:     `wc-donation-goal-display-type`
 *   (`fixed_amount` | `percentage_amount` | `no_of_donation` | `no_of_days` | '')
 * - Toggle on the campaign post:        `wc-donation-goal-display-option`
 * - Linked product id on the campaign:  `wc_donation_product`
 * - Total raised on the linked PRODUCT: `total_donation_amount`
 *
 * The parent's own progress formula (see frontend-donation-goal-disp.php
 * line 10) treats the seed as additive to raised:
 *
 *     progress = ( total_donation_amount + fixed_initial_amount ) / fixed_amount
 *
 * We mirror that here so the companion's "remaining" calculation matches
 * what donors see in parent's own progress bar.
 *
 * Per-request cached via the static `$cache` map keyed by campaign_id;
 * parent meta reads are cheap individually but Phase 13 features call
 * for_campaign() once per render and once per submit, so the cache
 * collapses repeat reads on the same request.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Goal_State {

	private const META_GOAL_ENABLED      = 'wc-donation-goal-display-option';
	private const META_GOAL_TYPE         = 'wc-donation-goal-display-type';
	private const META_GOAL_AMOUNT       = 'wc-donation-goal-fixed-amount-field';
	private const META_GOAL_INITIAL      = 'wc-donation-goal-fixed-initial-amount-field';
	private const META_LINKED_PRODUCT    = 'wc_donation_product';
	private const META_RAISED_ON_PRODUCT = 'total_donation_amount';

	/** @var array<int,self> */
	private static array $cache = array();

	private int $campaign_id;
	private bool $goal_enabled;
	private string $goal_type;
	private float $goal_amount;
	private float $initial_amount;
	private float $raised_amount;

	private function __construct(
		int $campaign_id,
		bool $goal_enabled,
		string $goal_type,
		float $goal_amount,
		float $initial_amount,
		float $raised_amount
	) {
		$this->campaign_id     = $campaign_id;
		$this->goal_enabled    = $goal_enabled;
		$this->goal_type       = $goal_type;
		$this->goal_amount     = $goal_amount;
		$this->initial_amount  = $initial_amount;
		$this->raised_amount   = $raised_amount;
	}

	public static function for_campaign( int $campaign_id ): self {
		if ( isset( self::$cache[ $campaign_id ] ) ) {
			return self::$cache[ $campaign_id ];
		}

		$goal_enabled = '' !== (string) get_post_meta( $campaign_id, self::META_GOAL_ENABLED, true );
		$goal_type    = (string) get_post_meta( $campaign_id, self::META_GOAL_TYPE, true );
		$goal_amount  = (float) get_post_meta( $campaign_id, self::META_GOAL_AMOUNT, true );
		$initial      = (float) get_post_meta( $campaign_id, self::META_GOAL_INITIAL, true );

		// The "raised" tally lives on the linked WC product, not the campaign.
		$product_id    = (int) get_post_meta( $campaign_id, self::META_LINKED_PRODUCT, true );
		$raised_total  = $product_id > 0
			? (float) get_post_meta( $product_id, self::META_RAISED_ON_PRODUCT, true )
			: 0.0;

		self::$cache[ $campaign_id ] = new self(
			$campaign_id,
			$goal_enabled,
			$goal_type,
			max( 0.0, $goal_amount ),
			max( 0.0, $initial ),
			max( 0.0, $raised_total )
		);

		return self::$cache[ $campaign_id ];
	}

	/**
	 * Reset the per-request cache. Tests use this; production code
	 * shouldn't need to.
	 */
	public static function reset_cache(): void {
		self::$cache = array();
	}

	public function campaign_id(): int {
		return $this->campaign_id;
	}

	public function goal_enabled(): bool {
		return $this->goal_enabled;
	}

	public function goal_type(): string {
		return $this->goal_type;
	}

	/**
	 * True when the goal is denominated in dollars (fixed_amount or
	 * percentage_amount). Phase 13's clamp + fully-funded logic applies
	 * only when this is true; donation-count goals and time-bound goals
	 * don't have a "remaining dollars to raise" answer.
	 */
	public function is_amount_goal(): bool {
		return $this->goal_enabled
			&& in_array( $this->goal_type, array( 'fixed_amount', 'percentage_amount' ), true )
			&& $this->goal_amount > 0;
	}

	/**
	 * The configured goal target. 0 when goal is disabled or non-amount.
	 */
	public function goal_amount(): float {
		return $this->is_amount_goal() ? $this->goal_amount : 0.0;
	}

	/**
	 * Total raised — matches the parent plugin's own progress formula:
	 * `total_donation_amount + fixed_initial_amount`. The seed lets
	 * orgs prime the bar without faking donations (e.g., when migrating
	 * from a legacy system).
	 */
	public function raised_amount(): float {
		return $this->raised_amount + $this->initial_amount;
	}

	/**
	 * Dollars left until the goal is met. 0 when goal is disabled, the
	 * goal type isn't dollar-denominated, or the campaign is already
	 * fully funded.
	 */
	public function remaining_amount(): float {
		if ( ! $this->is_amount_goal() ) {
			return 0.0;
		}
		return max( 0.0, $this->goal_amount - $this->raised_amount() );
	}

	/**
	 * True when the campaign has met or exceeded its dollar goal.
	 */
	public function is_fully_funded(): bool {
		return $this->is_amount_goal() && $this->raised_amount() >= $this->goal_amount;
	}

	/**
	 * Apply the goal-based clamp to a configured max value. Returns the
	 * smaller of (configured_max, remaining_amount) when this is an
	 * amount-goal campaign that isn't yet fully funded; otherwise returns
	 * the configured max unchanged.
	 *
	 * Both `Frontend\Renderer::build_form_config` (donor-side max shipped
	 * to overlay JS) and `Frontend\Submit_Guard::enforce` (server-side
	 * validation) call this so they apply identical clamping.
	 */
	public function clamp_max( float $configured_max ): float {
		if ( ! $this->is_amount_goal() || $this->is_fully_funded() ) {
			return $configured_max;
		}
		return min( $configured_max, $this->remaining_amount() );
	}
}
