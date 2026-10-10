<?php
/**
 * Business overhead expenses for the "Business Results" dashboard tab.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  HOW TO EDIT
 * ──────────────────────────────────────────────────────────────────────────
 *  This is a plain list you can safely edit by hand. To change an amount, edit
 *  the number. To add a cost, copy a line and change it. To remove one, delete
 *  its line. Save the file and reload the dashboard — no rebuild needed.
 *
 *  Each expense is one array with these keys:
 *
 *    'name'      (required)  Label shown on the dashboard. e.g. 'Elementor Pro'
 *    'category'              Group used in the breakdown. Pick any name you like
 *                            and reuse it to group costs together, e.g.
 *                            'Software', 'Hosting', 'Marketing', 'Staff',
 *                            'Platform', 'Services', 'Other'. Defaults to 'Other'.
 *    'amount'    (required)  The cost for ONE 'frequency' period, in 'currency'.
 *    'frequency' (required)  How often you pay it:
 *                              'monthly'  → charged every month
 *                              'yearly'   → charged once a year (shown as /12 per month)
 *                              'one_time' → a single one-off cost (needs a 'start' date)
 *    'quantity'              Multiplier, e.g. 2 licences at the same price.
 *                            The dashboard uses amount × quantity. Default 1.
 *    'currency'              Keep this the same as the reporting currency below
 *                            (EUR). Revenue and expenses are only combined when
 *                            they share the currency.
 *    'start'                 Optional 'YYYY-MM-DD' — first month the cost applies.
 *                            Leave empty for "always on". Required for 'one_time'.
 *    'end'                   Optional 'YYYY-MM-DD' — last month the cost applies
 *                            (for a subscription you have since cancelled).
 *    'notes'                 Optional free text shown in the item table.
 *
 *  Prefer editing in code? You can also add/override items without touching this
 *  file via the 'bokun_business_expenses' filter (see includes/bokun-business.php).
 * ──────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	// Reporting currency. Revenue is combined with expenses only for bookings
	// in this currency; keep every expense below in the same currency.
	'currency' => 'EUR',

	'items'    => array(

		array(
			'name'      => 'Elementor Pro',
			'category'  => 'Software',
			'amount'    => 100,
			'frequency' => 'yearly',
			'notes'     => 'Page-builder licence',
		),
		array(
			'name'      => 'AI assistants',
			'category'  => 'Software',
			'amount'    => 40,
			'frequency' => 'monthly',
			'notes'     => 'AI tools subscription',
		),
		array(
			'name'      => 'Bokun accounts',
			'category'  => 'Platform',
			'amount'    => 100,
			'frequency' => 'monthly',
			'notes'     => 'Two Bokun accounts (combined monthly cost)',
		),
		array(
			'name'      => 'DreamHost hosting',
			'category'  => 'Hosting',
			'amount'    => 100,
			'frequency' => 'yearly',
			'notes'     => 'Website hosting',
		),
		array(
			'name'      => 'Domains',
			'category'  => 'Hosting',
			'amount'    => 250,
			'frequency' => 'yearly',
			'notes'     => 'Domain name registrations',
		),
		array(
			'name'      => 'Advertising',
			'category'  => 'Marketing',
			'amount'    => 100,
			'frequency' => 'monthly',
			'notes'     => 'Online ads spend',
		),
		array(
			'name'      => 'Assistants / employees',
			'category'  => 'Staff',
			'amount'    => 200,
			'frequency' => 'monthly',
			'notes'     => 'Part-time help',
		),

		// ── Add any other / smaller recurring costs below. Examples: ──
		// array( 'name' => 'Email / newsletter', 'category' => 'Software',  'amount' => 15,  'frequency' => 'monthly' ),
		// array( 'name' => 'Accountant',         'category' => 'Services',  'amount' => 300, 'frequency' => 'yearly'  ),
		// array( 'name' => 'Bank / payment fees','category' => 'Services',  'amount' => 10,  'frequency' => 'monthly' ),
		// array( 'name' => 'New laptop',         'category' => 'Equipment', 'amount' => 900, 'frequency' => 'one_time', 'start' => '2026-01-15' ),
	),
);
