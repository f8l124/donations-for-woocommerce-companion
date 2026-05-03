<?php
/**
 * Cause_Closure_Service — read-only service that combines goal storage
 * (Cause_Goals_Schema) + raised aggregation (Cause_Raised_Aggregator) +
 * resolved closure mode into a single "open or closed?" decision per
 * (campaign, cause) pair.
 *
 * The donor renderer, Submit_Guard, and admin diagnostics all consult
 * this single source of truth so closure semantics stay consistent
 * across read sites.
 *
 * A cause is "closed" when ALL three conditions hold:
 *   1. Master toggle (`enable_cause_goals`) is on.
 *   2. The cause has `enabled = true` + `goal_amount > 0` configured.
 *   3. The cause's raised total >= goal amount.
 *
 * Returned closure decisions carry the resolved mode (no `inherit`
 * sentinel) and, for mode B (redirect_to_cause), the list of OTHER
 * open causes on the same campaign so the renderer can show
 * alternatives without re-querying.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Cause_Closure_Service {

	/**
	 * True when this specific cause is closed (raised >= goal AND
	 * tracking is enabled). Returns false when the master toggle is off,
	 * when the cause hasn't been configured for tracking, or when the
	 * goal amount is zero.
	 */
	public static function is_closed( int $campaign_id, string $cause_id ): bool {
		if ( ! Cause_Goals_Schema::feature_enabled() ) {
			return false;
		}
		if ( $campaign_id < 1 || '' === $cause_id ) {
			return false;
		}
		$row = Cause_Goals_Schema::for_cause( $campaign_id, $cause_id );
		if ( ! $row['enabled'] || $row['goal_amount'] <= 0.0 ) {
			return false;
		}
		$raised = Cause_Raised_Aggregator::for_cause( $campaign_id, $cause_id );
		return $raised >= $row['goal_amount'];
	}

	/**
	 * Full closure decision for a single cause. Always returns the
	 * canonical shape. `is_closed = false` means the donor flow is
	 * unaffected; the other fields are still populated for completeness
	 * but callers are expected to short-circuit on is_closed.
	 *
	 * @return array{
	 *     is_closed: bool,
	 *     mode: string,
	 *     goal_amount: float,
	 *     raised_amount: float,
	 *     alternatives: array<int,array{cause_id:string,name:string}>
	 * }
	 */
	public static function decision( int $campaign_id, string $cause_id ): array {
		$row    = Cause_Goals_Schema::for_cause( $campaign_id, $cause_id );
		$raised = '' !== $cause_id && $campaign_id > 0
			? Cause_Raised_Aggregator::for_cause( $campaign_id, $cause_id )
			: 0.0;

		$is_closed = self::is_closed( $campaign_id, $cause_id );

		$alternatives = array();
		if ( $is_closed && Cause_Goals_Schema::CLOSURE_MODE_REDIRECT_TO_CAUSE === $row['closure_mode'] ) {
			$alternatives = self::open_alternatives( $campaign_id, $cause_id );
		}

		return array(
			'is_closed'     => $is_closed,
			'mode'          => $row['closure_mode'],
			'goal_amount'   => $row['goal_amount'],
			'raised_amount' => $raised,
			'alternatives'  => $alternatives,
		);
	}

	/**
	 * Map every cause on a campaign to its closure decision. Used by
	 * the donor renderer to emit `data-closed-causes` JSON in one pass
	 * and by the admin diagnostics surface.
	 *
	 * Returns only the causes that are actually closed — open causes
	 * are absent from the map (smaller payload, simpler client logic).
	 *
	 * @return array<string,array{
	 *     mode:string,
	 *     goal_amount:float,
	 *     raised_amount:float,
	 *     alternatives:array<int,array{cause_id:string,name:string}>
	 * }>
	 */
	public static function closed_causes_for_campaign( int $campaign_id ): array {
		if ( ! Cause_Goals_Schema::feature_enabled() || $campaign_id < 1 ) {
			return array();
		}

		$ids = Cause_Identity::for_campaign( $campaign_id );
		if ( empty( $ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $cause_id ) {
			$cause_id = (string) $cause_id;
			if ( ! self::is_closed( $campaign_id, $cause_id ) ) {
				continue;
			}
			$decision               = self::decision( $campaign_id, $cause_id );
			$out[ $cause_id ]       = array(
				'name'          => (string) Cause_Identity::name_for_id( $campaign_id, $cause_id ),
				'mode'          => $decision['mode'],
				'goal_amount'   => $decision['goal_amount'],
				'raised_amount' => $decision['raised_amount'],
				'alternatives'  => $decision['alternatives'],
			);
		}
		return $out;
	}

	/**
	 * Compile a list of OTHER open causes on the same campaign, with
	 * their human-readable names. Used by mode B (redirect_to_cause)
	 * so the donor sees a "pick one of these instead" prompt.
	 *
	 * @return array<int,array{cause_id:string,name:string}>
	 */
	public static function open_alternatives( int $campaign_id, string $excluded_cause_id ): array {
		$ids = Cause_Identity::for_campaign( $campaign_id );
		$out = array();
		foreach ( $ids as $cause_id ) {
			$cause_id = (string) $cause_id;
			if ( $cause_id === $excluded_cause_id ) {
				continue;
			}
			if ( self::is_closed( $campaign_id, $cause_id ) ) {
				continue;
			}
			$name = (string) Cause_Identity::name_for_id( $campaign_id, $cause_id );
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'cause_id' => $cause_id,
				'name'     => $name,
			);
		}
		return $out;
	}
}
