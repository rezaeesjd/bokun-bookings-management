<?php
/**
 * Business results data layer.
 *
 * Loads the operator's overhead expenses (from includes/data/business-expenses.php)
 * and normalizes them for the "Business Results" dashboard tab, which combines
 * them with the booking revenue/net-revenue already computed by the analytics
 * layer to show operating profit, a layered P&L, an expense breakdown and a
 * monthly trend.
 *
 * Expenses are config, not stored data — there is no table and no admin CRUD.
 * Edit the data file by hand, or override programmatically via the
 * 'bokun_business_expenses' filter. All amounts are read as the reporting
 * currency (EUR by default); revenue is combined with expenses only for
 * bookings in that same currency, so money is never mixed across currencies.
 *
 * @package Bokun_Bookings_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load and cache the raw expenses config array.
 *
 * @return array{currency?:string,items?:array} The config array, or empty.
 */
function bokun_business_load_config() {
	static $cfg = null;

	if ( null !== $cfg ) {
		return $cfg;
	}

	$cfg  = array();
	$file = BOKUN_INCLUDES_DIR . 'data/business-expenses.php';

	if ( file_exists( $file ) ) {
		$loaded = include $file;
		if ( is_array( $loaded ) ) {
			$cfg = $loaded;
		}
	}

	return $cfg;
}

/**
 * The reporting currency for the Business Results tab (uppercase, default EUR).
 *
 * @return string
 */
function bokun_business_reporting_currency() {
	$cfg = bokun_business_load_config();
	$cur = isset( $cfg['currency'] ) ? strtoupper( sanitize_text_field( (string) $cfg['currency'] ) ) : '';

	/**
	 * Filter the Business Results reporting currency.
	 *
	 * @param string $cur Three-letter currency code.
	 */
	$cur = strtoupper( (string) apply_filters( 'bokun_business_reporting_currency', $cur ? $cur : 'EUR' ) );

	return $cur ? $cur : 'EUR';
}

/**
 * Normalized list of overhead expenses.
 *
 * Each returned item is an array with:
 *   name, category, amount (float, per period), quantity (int>=1),
 *   total (amount*quantity), currency, frequency (monthly|yearly|one_time),
 *   monthly (float monthly-equivalent; 0 for one_time), annual (float
 *   annual-equivalent; 0 for one_time), start ('YYYY-MM-DD'|''),
 *   end ('YYYY-MM-DD'|''), notes.
 *
 * @return array<int,array<string,mixed>>
 */
function bokun_business_get_expenses() {
	$cfg       = bokun_business_load_config();
	$items     = ( isset( $cfg['items'] ) && is_array( $cfg['items'] ) ) ? $cfg['items'] : array();
	$default_c = bokun_business_reporting_currency();
	$allowed_f = array( 'monthly', 'yearly', 'one_time' );
	$out       = array();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$name = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
		if ( '' === $name ) {
			continue;
		}

		$amount = isset( $item['amount'] ) ? (float) $item['amount'] : 0.0;
		if ( $amount < 0 ) {
			$amount = 0.0;
		}

		$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
		if ( $qty < 1 ) {
			$qty = 1;
		}

		$freq = isset( $item['frequency'] ) ? strtolower( sanitize_text_field( (string) $item['frequency'] ) ) : 'monthly';
		if ( ! in_array( $freq, $allowed_f, true ) ) {
			$freq = 'monthly';
		}

		$total = $amount * $qty;

		// Monthly- and annual-equivalents power the run-rate figures. A one-off
		// cost has no run-rate (it lands in a single month), so both are 0 and
		// it is prorated to its own month in the monthly series instead.
		if ( 'yearly' === $freq ) {
			$monthly = $total / 12;
			$annual  = $total;
		} elseif ( 'monthly' === $freq ) {
			$monthly = $total;
			$annual  = $total * 12;
		} else {
			$monthly = 0.0;
			$annual  = 0.0;
		}

		$out[] = array(
			'name'      => $name,
			'category'  => ( isset( $item['category'] ) && '' !== $item['category'] )
				? sanitize_text_field( (string) $item['category'] )
				: __( 'Other', 'BOKUN_txt_domain' ),
			'amount'    => $amount,
			'quantity'  => $qty,
			'total'     => $total,
			'currency'  => isset( $item['currency'] )
				? strtoupper( sanitize_text_field( (string) $item['currency'] ) )
				: $default_c,
			'frequency' => $freq,
			'monthly'   => $monthly,
			'annual'    => $annual,
			'start'     => isset( $item['start'] ) ? sanitize_text_field( (string) $item['start'] ) : '',
			'end'       => isset( $item['end'] ) ? sanitize_text_field( (string) $item['end'] ) : '',
			'notes'     => isset( $item['notes'] ) ? sanitize_text_field( (string) $item['notes'] ) : '',
		);
	}

	/**
	 * Filter the normalized Business Results expenses.
	 *
	 * Return value must keep the same item shape as produced here. Use it to add,
	 * change or remove expenses without editing the data file.
	 *
	 * @param array $out Normalized expense items.
	 * @param array $cfg Raw config array.
	 */
	$out = apply_filters( 'bokun_business_expenses', $out, $cfg );

	return is_array( $out ) ? $out : array();
}

/**
 * Fixed monthly overhead run-rate: the sum of the monthly-equivalents of every
 * recurring expense (one-off costs excluded).
 *
 * @return float
 */
function bokun_business_monthly_overhead() {
	$sum = 0.0;

	foreach ( bokun_business_get_expenses() as $expense ) {
		$sum += (float) $expense['monthly'];
	}

	return $sum;
}
