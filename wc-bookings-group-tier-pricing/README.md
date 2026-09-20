# WC Bookings Group Tier Pricing

Adds attendee-count pricing tiers to [WooCommerce Bookings](https://woocommerce.com/products/woocommerce-bookings/). Lets the price of a Person Type (Adult, Child...) change based on the **total number of people in a single booking**, not just the person type itself.

Example: a tasting experience where the "Adult" price is 20 € for groups up to 14 people, and drops to 15 € once the group reaches 15–20 people — while Child and Toddler prices stay fixed. WooCommerce Bookings has no built-in way to do this: each Person Type only supports one fixed price.

## The problem this solves

WooCommerce Bookings' Persons feature lets you set a fixed cost per person type. It has no concept of "the price of type A depends on how many people (of any type) are in this booking." This has been [requested since 2018](https://woocommerce.com/feature-request/bookings-plugin-adding-pricing-rules-to-individual-person-types/) and still isn't supported natively, in this or in any other WooCommerce booking plugin as far as I've checked (Amelia, Bookly, FooEvents...).

This plugin hooks into the `woocommerce_bookings_calculated_booking_cost` filter and recalculates the total cost from a tier table you define, based on the total attendee count.

## Requirements

- WordPress
- WooCommerce
- [WooCommerce Bookings](https://woocommerce.com/products/woocommerce-bookings/) (official extension)
- A bookable product with **Person Types** enabled

## Installation

1. Download/clone this repo into `wp-content/plugins/wc-bookings-group-tier-pricing`.
2. Activate it from **Plugins**.
3. On its own, it does nothing — it ships with no tiers and no person type mapping. Configure both via the filters below, in your theme's `functions.php` or a site-specific plugin.

## Configuration

### 1. Find your Person Type IDs

Person Type IDs are generated per product when you create them in the product's **Persons** tab — they're not fixed values you can guess. The easiest way to find them:

1. Open the product's public booking form (front end).
2. View the page source and locate the person-count inputs — their `name` attribute includes the Person Type ID, e.g. `wc_booking_person_ids[123]`.
3. Match each ID to the label you gave it (Adult, Child...).

### 2. Map Person Type IDs to role keys

```php
add_filter( 'wcbgt_person_type_map', function ( $map, $product ) {
	// Only apply this map to a specific bookable product, if needed:
	// if ( $product->get_id() !== 1234 ) { return $map; }

	return array(
		101 => 'adult',   // Person Type ID => role key
		102 => 'child',
		103 => 'toddler',
	);
}, 10, 2 );
```

### 3. Define the tiers

```php
add_filter( 'wcbgt_tiers', function ( $tiers, $product ) {
	return array(
		array(
			'max_total' => 14, // up to 14 attendees total
			'prices'    => array(
				'adult'   => 20,
				'child'   => 10,
				'toddler' => 0,
			),
		),
		array(
			'max_total' => 20, // 15 to 20 attendees total
			'prices'    => array(
				'adult'   => 15,
				'child'   => 10,
				'toddler' => 0,
			),
		),
	);
}, 10, 2 );
```

Tiers are evaluated in order; the first one whose `max_total` is greater than or equal to the total attendee count wins. A booking with 12 adults + 2 children + 1 toddler = 15 people would match the second tier: `12 × 15 + 2 × 10 + 1 × 0 = 200`.

### 4. Capacity per session

This plugin only handles price. To cap attendees per time slot, use WooCommerce Bookings' own settings: enable **Count persons as bookings** and set **Max per block** to your capacity — this already counts every person type, including free ones, against the limit.

## Known limitations

- **Live price preview**: WooCommerce Bookings recalculates the displayed cost via an AJAX call that runs through this same PHP calculation, so the tiered price should already show correctly on the product page as the customer changes quantities. Test this on your specific WooCommerce Bookings version before going live — if a future version ever estimates cost client-side without a server round trip, the preview could lag behind the final cart price.
- Tiers apply per product. If you need different tier tables per bookable product, use the `$product` argument passed to both filters.

## License

GPL v2 or later.

## Support

Need help or have suggestions?

- [Official website](https://servicios.ayudawp.com)
- [YouTube channel](https://www.youtube.com/AyudaWordPressES)
- [Documentation and tutorials](https://ayudawp.com)

Love the plugin? Please star the repo and help spread the word!

## About AyudaWP.com

We are specialists in WordPress security, SEO, AI and performance optimization plugins. We create tools that solve real problems for WordPress site owners while maintaining the highest coding standards and accessibility requirements.
