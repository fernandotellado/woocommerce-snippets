<?php
/**
 * Plugin Name:       WC Bookings Group Tier Pricing
 * Plugin URI:        https://github.com/YOUR-USERNAME/wc-bookings-group-tier-pricing
 * Description:       Adds attendee-count pricing tiers to WooCommerce Bookings. Lets the price per person type change based on the TOTAL number of people in a single booking, not just the person type itself (e.g. "Adult" drops from 20 to 15 once the group reaches 15 people).
 * Version:           1.0.0
 * Author:            Fernando Tellado
 * Author URI:        https://plugins.ayudawp.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-bookings-group-tier-pricing
 *
 * Requires:          WooCommerce, WooCommerce Bookings
 *
 * @package WC_Bookings_Group_Tier_Pricing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recalculate the booking cost based on total attendee count instead of a
 * fixed price per person type.
 *
 * WooCommerce Bookings prices each Person Type (Adult, Child...) with a
 * single fixed cost defined on the product. It has no built-in way to
 * change that price based on how many people are in the SAME booking.
 * This filter overrides the calculated cost using a tier table you
 * configure via the `wcbgt_tiers` filter.
 *
 * @param float      $cost        Cost calculated by WooCommerce Bookings.
 * @param WC_Product $product     The bookable product.
 * @param array      $posted_data Data submitted from the booking form.
 * @return float
 */
function wcbgt_apply_group_tier_pricing( $cost, $product, $posted_data ) {

	if ( empty( $posted_data['_wc_booking_person_counts'] ) || ! is_array( $posted_data['_wc_booking_person_counts'] ) ) {
		return $cost;
	}

	$counts_by_id = $posted_data['_wc_booking_person_counts'];
	$person_types = $product->get_person_types();

	if ( empty( $person_types ) ) {
		return $cost;
	}

	// Map each Person Type ID to a role key ('adult', 'child'...) and sum
	// quantities per role plus the overall attendee total.
	$type_map    = wcbgt_get_person_type_map( $product );
	$counts      = array();
	$total_count = 0;

	foreach ( $person_types as $type ) {
		$type_id = $type->get_id();
		$qty     = isset( $counts_by_id[ $type_id ] ) ? (int) $counts_by_id[ $type_id ] : 0;
		$role    = isset( $type_map[ $type_id ] ) ? $type_map[ $type_id ] : null;

		if ( $role ) {
			$counts[ $role ] = ( isset( $counts[ $role ] ) ? $counts[ $role ] : 0 ) + $qty;
		}

		$total_count += $qty;
	}

	if ( $total_count < 1 ) {
		return $cost;
	}

	$tier = wcbgt_get_matching_tier( $total_count, $product );

	if ( ! $tier || empty( $tier['prices'] ) ) {
		return $cost; // No tier configured or matched — keep the default WC Bookings cost.
	}

	$new_cost = 0;

	foreach ( $counts as $role => $qty ) {
		$price     = isset( $tier['prices'][ $role ] ) ? (float) $tier['prices'][ $role ] : 0;
		$new_cost += $qty * $price;
	}

	return $new_cost;
}
add_filter( 'woocommerce_bookings_calculated_booking_cost', 'wcbgt_apply_group_tier_pricing', 20, 3 );

/**
 * Map WooCommerce Bookings Person Type IDs to the role keys used in the
 * tier configuration (e.g. 'adult', 'child', 'toddler').
 *
 * Person Type IDs are generated per product and don't exist until you
 * create the types in the product's Persons tab, so this plugin ships
 * with no default map. See README.md for how to find your IDs and set
 * this filter.
 *
 * @param WC_Product $product The bookable product.
 * @return array Person Type ID => role key.
 */
function wcbgt_get_person_type_map( $product ) {
	return apply_filters( 'wcbgt_person_type_map', array(), $product );
}

/**
 * Get the pricing tiers configuration.
 *
 * Tiers are evaluated in order; the first one whose 'max_total' is
 * greater than or equal to the total attendee count wins. Ships empty —
 * set this via the `wcbgt_tiers` filter. See README.md for an example.
 *
 * @param int        $total_count Total attendees in this booking.
 * @param WC_Product $product     The bookable product.
 * @return array|null Matching tier (['max_total' => int, 'prices' => [role => price]]), or null.
 */
function wcbgt_get_matching_tier( $total_count, $product ) {
	$tiers = apply_filters( 'wcbgt_tiers', array(), $product );

	foreach ( $tiers as $tier ) {
		if ( $total_count <= $tier['max_total'] ) {
			return $tier;
		}
	}

	return null;
}
