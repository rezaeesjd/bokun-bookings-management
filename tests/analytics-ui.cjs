const assert = require('node:assert/strict');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {JSDOM} = require('jsdom');

const base = {created_datetime: '2026-10-01 12:00:00', travel_datetime: '2026-11-01 12:00:00',
    product_title: 'Tour A', channel_title: 'Viator.com', pb_status: 'CONFIRMED', result: 'full',
    currency: 'EUR', payment_method: 'Card', language: 'English', adult_participants: 2, price_amount: 100};
const rows = [
    {...base, confirmation_code: 'TEST-1'},
    {...base, confirmation_code: 'TEST-2', result: '', language: 'French'},
    {...base, confirmation_code: 'TEST-3', product_title: 'Tour B', result: 'partial', payment_method: 'Cash'},
    {...base, confirmation_code: 'TEST-4', channel_title: 'Direct', pb_status: 'CANCELLED', result: 'partial'},
    {...base, confirmation_code: 'TEST-5', channel_title: 'Direct', product_title: 'Tour B', language: 'French', currency: 'USD'},
    {...base, confirmation_code: 'TEST-6', product_title: 'Tour C', channel_title: 'Direct', result: null,
        created_datetime: '', travel_datetime: '', language: 'French'},
    {...base, confirmation_code: 'TEST-7', product_title: '<Tour "D">', channel_title: 'Direct',
        created_datetime: '2026-09-01 12:00:00', travel_datetime: '2026-09-15 12:00:00'},
];
function setup(data = rows, extra = {}) {
    const rendered = spawnSync(process.env.BOKUN_TEST_PHP || 'php',
        [...JSON.parse(process.env.BOKUN_TEST_PHP_ARGS || '[]'), path.join(__dirname, 'analytics-fixture.php')],
        {input: JSON.stringify({rows: data, ...extra}), encoding: 'utf8'});
    assert.equal(rendered.status, 0, rendered.error?.message || rendered.stderr || rendered.stdout);
    const dom = new JSDOM(rendered.stdout, {runScripts: 'outside-only', url: 'https://analytics.example.test'});
    const {window} = dom;
    const NativeDate = window.Date;
    window.Date = class extends NativeDate {
        constructor(...args) { super(...(args.length ? args : ['2026-10-25T12:00:00Z'])); }
    };
    window.document.querySelectorAll('script:not([type])').forEach(script => window.eval(script.textContent));
    const q = sel => window.document.querySelector(sel);
    const item = (key, value) => [...window.document.querySelectorAll(`[data-facet="${key}"] .bokun-andash__item`)].find(el => el.dataset.val === value);
    const count = (key, value) => Number(item(key, value).querySelector('.cnt').textContent.replaceAll(',', ''));
    const records = () => [...q('[data-detail] tbody').querySelectorAll('tr')].map(row => row.cells[2]?.textContent).filter(Boolean);
    const set = (sel, value, event = 'change') => { q(sel).value = value; q(sel).dispatchEvent(new window.Event(event, {bubbles: true})); };
    const drill = (container, key, value) => [...q(container).querySelectorAll('[data-filter-dim]')]
        .find(el => el.dataset.filterDim === key && el.dataset.filterValue === value);
    return {dom, q, item, count, records, set, drill, raw: rendered.stdout};
}
let checks = 0;
function check(label, test) { test(); checks++; console.log('PASS ' + label); }
const ui = setup();
const {q, item, count, records, set, drill} = ui;
const reset = () => q('[data-reset]').click();
check('initial counts and live summary reflect all records', () => {
    assert.equal(count('product_title', 'Tour A'), 3);
    assert.equal(count('result', ''), 2);
    assert.equal(q('[data-filter-summary]').textContent, 'Bookings: 7 / 7');
});
check('channel updates other counts and keeps same-facet alternatives', () => {
    item('channel_title', 'Viator.com').focus();
    item('channel_title', 'Viator.com').click();
    assert.equal(count('product_title', 'Tour A'), 2);
    assert.equal(count('product_title', 'Tour C'), 0);
    assert.equal(item('product_title', 'Tour C').disabled, true);
    assert.equal(count('channel_title', 'Direct'), 4);
    assert.equal(item('channel_title', 'Viator.com').getAttribute('aria-pressed'), 'true');
    assert.equal(ui.dom.window.document.activeElement, item('channel_title', 'Viator.com'));
    assert.deepEqual(records(), ['TEST-1', 'TEST-2', 'TEST-3']);
    assert.equal(q('[data-detail-count]').textContent, '(3)');
    assert.equal(q('[data-kpis] .bokun-andash__kpi-value').textContent, '3');
});
check('multi-select OR and cross-dimension AND agree with counts', () => {
    item('product_title', 'Tour B').click();
    assert.deepEqual(records(), ['TEST-3']);
    assert.equal(count('product_title', 'Tour A'), 2);
    item('product_title', 'Tour A').click();
    assert.equal(records().length, 3);
    item('channel_title', 'Direct').click();
    assert.equal(records().length, 5);
});
check('dropdowns update counts, disable unavailable options and allow switching', () => {
    reset(); item('product_title', 'Tour B').click();
    const language = q('[data-f-dim="language"]');
    assert.equal(language.options[1].textContent, 'English (1)');
    assert.equal(language.options[2].textContent, 'French (1)');
    set('[data-f-dim="language"]', 'French');
    assert.deepEqual(records(), ['TEST-5']);
    assert.equal(language.options[1].textContent, 'English (1)');
    item('channel_title', 'Direct').click();
    assert.equal(language.options[1].disabled, true);
    assert.equal(language.options[2].disabled, false);
});
check('no-result rows include null and empty values', () => {
    reset(); item('result', '').click();
    assert.deepEqual(records(), ['TEST-2', 'TEST-6']);
    assert.equal(count('product_title', 'Tour A'), 1);
});
check('search updates counts and selected zero-count items remain removable', () => {
    reset(); item('channel_title', 'Viator.com').click();
    set('[data-f-search]', 'TEST-6', 'input');
    assert.equal(records().length, 0);
    assert.equal(count('channel_title', 'Viator.com'), 0);
    assert.equal(item('channel_title', 'Viator.com').disabled, false);
    item('channel_title', 'Viator.com').click();
    assert.deepEqual(records(), ['TEST-6']);
});
check('a selected zero-count dropdown remains selected until explicitly cleared', () => {
    reset(); set('[data-f-dim="language"]', 'French');
    set('[data-f-search]', 'TEST-1', 'input');
    assert.equal(records().length, 0);
    const language = q('[data-f-dim="language"]');
    assert.equal(language.value, 'French');
    assert.equal(language.selectedOptions[0].disabled, false);
    assert.equal(language.selectedOptions[0].textContent, 'French (0)');
    set('[data-f-dim="language"]', '');
    assert.deepEqual(records(), ['TEST-1']);
});
check('dates exclude missing values and clear stale preset highlights', () => {
    reset(); set('[data-f-date="created_from"]', '2026-10-01');
    assert.equal(records().length, 5);
    assert.equal(count('product_title', 'Tour C'), 0);
    assert.equal(q('[data-preset].is-active'), null);
    reset(); set('[data-f-date="travel_to"]', '2026-10-01');
    assert.deepEqual(records(), ['TEST-7']);
    reset(); q('[data-preset="7"]').click();
    assert.equal(records().length, 0);
    q('[data-preset="0"]').click();
    assert.equal(records().length, 7);
});
check('quick filter updates counts and manual changes clear its active state', () => {
    reset(); q('[data-quick]').click();
    assert.deepEqual(records(), ['TEST-1', 'TEST-3']);
    assert.equal(count('result', ''), 1);
    item('result', '').click();
    assert.equal(q('[data-quick]').getAttribute('aria-pressed'), 'false');
    assert.equal(records().length, 3);
});
check('chart buttons share filter state and toggle a drilldown off', () => {
    reset(); drill('[data-topproducts]', 'product_title', 'Tour A').click();
    assert.equal(records().length, 3);
    assert.equal(ui.dom.window.document.activeElement, drill('[data-topproducts]', 'product_title', 'Tour A'));
    assert.equal(item('product_title', 'Tour A').getAttribute('aria-pressed'), 'true');
    drill('[data-topchannels]', 'channel_title', 'Viator.com').click();
    assert.deepEqual(records(), ['TEST-1', 'TEST-2']);
    drill('[data-resultmix]', 'result', 'full').click();
    assert.deepEqual(records(), ['TEST-1']);
    drill('[data-paymentmix]', 'payment_method', 'Card').click();
    assert.equal(item('payment_method', 'Card').getAttribute('aria-pressed'), 'true');
    drill('[data-topproducts]', 'product_title', 'Tour A').click();
    assert.equal(item('product_title', 'Tour A').getAttribute('aria-pressed'), 'false');
});
check('counts stay booking counts when switching metrics', () => {
    reset(); q('[data-metric="participants"]').click();
    assert.equal(count('product_title', 'Tour A'), 3);
    assert.equal(q('[data-kpis] .bokun-andash__kpi-value').textContent, '7');
    assert.equal(drill('[data-topproducts]', 'product_title', 'Tour A').querySelector('.val').textContent, '6');
});
check('chart labels preserve special characters without creating HTML', () => {
    reset(); drill('[data-topproducts]', 'product_title', '<Tour "D">').click();
    assert.deepEqual(records(), ['TEST-7']);
    assert.equal(q('tour'), null);
});
check('reset restores every count, selection and dropdown', () => {
    reset();
    assert.equal(records().length, 7);
    assert.equal(count('product_title', 'Tour A'), 3);
    assert.equal(q('.bokun-andash__item[aria-pressed="true"]'), null);
    assert.equal(q('[data-f-dim="language"]').value, '');
    assert.equal(q('[data-facet="product_title"] button:disabled'), null);
});
check('CSV export uses the same filtered records as the dashboard', () => {
    reset(); item('channel_title', 'Viator.com').click();
    let csv = '';
    ui.dom.window.Blob = class { constructor(parts) { csv = parts.join(''); } };
    ui.dom.window.URL.createObjectURL = () => 'blob:synthetic';
    ui.dom.window.HTMLAnchorElement.prototype.click = () => {};
    q('[data-export]').click();
    assert.match(csv, /TEST-1/);
    assert.match(csv, /TEST-3/);
    assert.doesNotMatch(csv, /TEST-[4-7]/);
});
check('aggregate Other bucket does not act as a real product filter', () => {
    const other = setup(Array.from({length: 10}, (_, i) => ({...base, product_title: 'Tour ' + i})));
    assert.equal(other.q('[data-topproducts]').querySelectorAll('[data-filter-dim]').length, 8);
    assert.match(other.q('[data-topproducts]').lastElementChild.lastElementChild.textContent, /Other/);
    other.dom.window.close();
});
check('quick filter without matching channel does not drop the constraint', () => {
    const empty = setup([{...base, confirmation_code: 'TEST-EMPTY', channel_title: 'Direct'}]);
    empty.q('[data-quick]').click();
    assert.equal(empty.records().length, 0);
    empty.q('[data-reset]').click();
    assert.equal(empty.records().length, 1);
    empty.dom.window.close();
});
check('default view shows the last N months by creation date and All expands it', () => {
    const recent = {...base, confirmation_code: 'RECENT', created_datetime: '2026-10-01 12:00:00'};
    const old = {...base, confirmation_code: 'OLD', created_datetime: '2026-05-01 12:00:00'};
    const d = setup([recent, old], {default_view_months: 3});
    // On load the default view is the last 3 months (vs 2026-10-25), so the
    // ~5-month-old booking is excluded and the 3m chip is highlighted.
    assert.deepEqual(d.records(), ['RECENT']);
    assert.equal(d.q('[data-preset-months="3"]').classList.contains('is-active'), true);
    // "All" widens to the full retained window and brings the older row back.
    d.q('[data-preset="0"]').click();
    assert.deepEqual([...d.records()].sort(), ['OLD', 'RECENT']);
    assert.equal(d.q('[data-preset-months="3"]').classList.contains('is-active'), false);
    // Reset returns to the default 3-month view.
    d.q('[data-reset]').click();
    assert.deepEqual(d.records(), ['RECENT']);
    d.dom.window.close();
});
check('Business Results metric shows a margin breakdown that follows the filters', () => {
    reset();
    q('[data-metric="bizresults"]').click();
    const rootEl = ui.dom.window.document.querySelector('.bokun-andash');
    assert.equal(rootEl.classList.contains('is-bizmetric'), true);
    const bookingsValue = () => {
        const tile = [...q('[data-bizresults]').querySelectorAll('.bokun-andash__kpi')]
            .find(k => /bookings/i.test(k.querySelector('.bokun-andash__kpi-label').textContent));
        return tile.querySelector('.bokun-andash__kpi-value').textContent;
    };
    assert.match(q('[data-bizresults]').textContent, /net revenue is calculated/i);
    assert.equal(bookingsValue(), '7');
    item('channel_title', 'Viator.com').click();
    assert.equal(bookingsValue(), '3');
    q('[data-metric="netrev"]').click();
    assert.equal(rootEl.classList.contains('is-bizmetric'), false);
});
const bizTileVal = (panel, label) => {
    const t = [...panel.querySelectorAll('.bokun-andash__kpi')]
        .find(k => k.querySelector('.bokun-andash__kpi-label').textContent === label);
    return t && t.querySelector('.bokun-andash__kpi-value').textContent;
};
check('Business Results reconciles gross − cost = margin and keeps unmatched gross visible', () => {
    const mk = (code, over) => ({created_datetime: '2026-10-01 12:00:00', travel_datetime: '2026-11-01 12:00:00',
        product_title: 'Tour', channel_title: 'Direct', pb_status: 'CONFIRMED', result: 'full', currency: 'EUR',
        payment_method: 'Card', adult_participants: 2, confirmation_code: code, ...over});
    const d = setup([
        mk('MATCHED', {partner_page_id: '10', price_amount: 100}),  // gross 100, cost 40 -> margin 60
        mk('NOGROSS', {partner_page_id: '10', price_amount: null}), // matched but unparseable gross -> excluded
        mk('UNCAT', {price_amount: 70})                             // not in catalog -> unmatched gross
    ], {partners: {'10': {net_price: 20, title: 'Tour', commission: 0, departure_city: '', partner_page_id: '10'}}});
    d.q('[data-metric="bizresults"]').click();
    const panel = d.q('[data-bizresults]');
    // The waterfall sums over the computable-margin row only, so it reconciles.
    assert.match(bizTileVal(panel, 'Gross earned'), /100/);
    assert.match(bizTileVal(panel, 'Partner cost'), /40/);
    assert.match(bizTileVal(panel, 'Net margin'), /60/);
    assert.match(bizTileVal(panel, 'Avg net margin / booking'), /60/);
    // Uncatalogued gross is surfaced rather than silently dropped.
    assert.match(panel.textContent, /70[\s\S]*not in the partners catalog/);
    d.dom.window.close();
});
check('Business Results shows unmatched gross even when nothing has net revenue', () => {
    const d = setup([{created_datetime: '2026-10-01 12:00:00', product_title: 'X', channel_title: 'Direct',
        pb_status: 'CONFIRMED', result: 'full', currency: 'EUR', adult_participants: 2, price_amount: 100, confirmation_code: 'U1'}]);
    d.q('[data-metric="bizresults"]').click();
    assert.match(d.q('[data-bizresults]').textContent, /100[\s\S]*not in the partners catalog/);
    d.dom.window.close();
});
check('Business Results average margin is per selected-currency booking, not all currencies', () => {
    const mk = (code, cur) => ({created_datetime: '2026-10-01 12:00:00', product_title: 'Tour', channel_title: 'Direct',
        pb_status: 'CONFIRMED', result: 'full', currency: cur, adult_participants: 2, partner_page_id: '10', price_amount: 100, confirmation_code: code});
    const d = setup([mk('EUR1', 'EUR'), mk('USD1', 'USD')],
        {partners: {'10': {net_price: 20, title: 'Tour', commission: 0, departure_city: '', partner_page_id: '10'}}});
    d.q('[data-metric="bizresults"]').click();
    // Each booking earns margin 60 in its own currency; the average is 60 (one
    // booking in the selected currency), not 30 (60 split across both matches).
    assert.match(bizTileVal(d.q('[data-bizresults]'), 'Avg net margin / booking'), /60/);
    d.dom.window.close();
});
check('Business Results subtracts business-wide overhead and shows the monthly run-rate', () => {
    const mk = (code, month) => ({created_datetime: '2026-' + month + '-01 12:00:00', product_title: 'Tour', channel_title: 'Direct',
        pb_status: 'CONFIRMED', result: 'full', currency: 'EUR', adult_participants: 2, partner_page_id: '10', price_amount: 100, confirmation_code: code});
    // Two matched EUR bookings (margin 60 each = 120) across two distinct
    // creation months, with 100/month business-wide overhead.
    const d = setup([mk('SEP', '09'), mk('OCT', '10')], {
        monthly_overhead: 100,
        partners: {'10': {net_price: 20, title: 'Tour', commission: 0, departure_city: '', partner_page_id: '10'}}
    });
    d.q('[data-metric="bizresults"]').click();
    const panel = d.q('[data-bizresults]');
    // Net margin 120; period overhead = 100 x 2 months = 200; op profit = -80.
    assert.match(bizTileVal(panel, 'Net margin'), /120/);
    assert.match(bizTileVal(panel, 'Operating profit'), /-?80/);
    // Monthly run-rate: 2 months, avg margin 60, overhead 100, profit -40.
    assert.match(bizTileVal(panel, 'Months covered'), /2/);
    assert.match(bizTileVal(panel, 'Avg monthly margin'), /60/);
    assert.match(bizTileVal(panel, 'Monthly overhead'), /100/);
    assert.match(bizTileVal(panel, 'Monthly operating profit'), /-?40/);
    // The overhead waterfall rows reconcile margin -> operating profit.
    assert.match(panel.textContent, /Operating profit/);
    d.dom.window.close();
});
check('record links enrichment uses one bulk meta query, not per-row lookups', () => {
    const mk = (id, code) => ({post_id: id, confirmation_code: code, external_booking_reference: 'EXT' + id,
        created_datetime: '2026-10-01 12:00:00', travel_datetime: '2026-11-01 12:00:00', product_title: 'Tour',
        channel_title: 'Direct', pb_status: 'CONFIRMED', result: 'full', currency: 'EUR', adult_participants: 2, price_amount: 100});
    const data = Array.from({length: 6}, (_, i) => mk(100 + i, 'B' + i));
    const d = setup(data, {wpdb_meta: [
        {post_id: 100, meta_key: 'productBookings_0_parentBookingId', meta_value: 'PB100'},
        {post_id: 101, meta_key: '_user_channel_refunded', meta_value: '1'}
    ]});
    // One query for the whole set — never one per booking. This is the guard for
    // the large-import regression that exhausted memory and blanked the page.
    assert.match(d.raw, /<!--wpdb-queries:1-->/);
    const linksCell = [...d.q('[data-detail] tbody').querySelectorAll('tr')]
        .map(r => r.querySelector('.bokun-andash__links')).find(Boolean);
    assert.ok(/Viator/.test(linksCell.textContent));
    assert.ok(/WP/.test(linksCell.textContent));
    assert.ok(/Booking/.test(linksCell.textContent));
    d.dom.window.close();
});
check('records table shows the tour leader (lead traveller) name', () => {
    const d = setup([{...base, confirmation_code: 'LEAD-1', customer_name: 'Mario Rossi'}]);
    const headers = [...d.dom.window.document.querySelectorAll('[data-detail] thead th')].map(th => th.textContent);
    assert.ok(headers.includes('Tour leader'), 'Tour leader column header present');
    const idx = headers.indexOf('Tour leader');
    const cells = d.q('[data-detail] tbody tr').cells;
    assert.equal(cells[idx].textContent, 'Mario Rossi');
    d.dom.window.close();
});
check('period presets can filter by travel date, not just created date', () => {
    // now is stubbed to 2026-10-25; the 30d preset window starts 2026-09-25.
    const d = setup([
        {...base, confirmation_code: 'TRV-IN', created_datetime: '2026-10-01 12:00:00', travel_datetime: '2026-10-20 09:00:00'},
        {...base, confirmation_code: 'TRV-OUT', created_datetime: '2026-10-01 12:00:00', travel_datetime: '2026-08-01 09:00:00'},
    ]);
    // No default window in the fixture, so both are visible to start.
    assert.deepEqual(d.records().sort(), ['TRV-IN', 'TRV-OUT']);
    // Switch the preset basis to Travel, then apply the 30d preset: only the
    // booking whose TRAVEL date is within 30 days survives (both were created
    // on the same recent day, so a created-date window would keep both).
    d.q('[data-preset-basis="travel"]').click();
    d.q('[data-preset="30"]').click();
    assert.deepEqual(d.records(), ['TRV-IN']);
    d.dom.window.close();
});
check('switching range basis carries both date bounds, not just the start', () => {
    const d = setup([
        {...base, confirmation_code: 'SEP', created_datetime: '2026-09-10 12:00:00'},
        {...base, confirmation_code: 'OCT', created_datetime: '2026-10-10 12:00:00'},
    ]);
    // A bounded Created window of September only.
    d.set('[data-f-date="created_from"]', '2026-09-01');
    d.set('[data-f-date="created_to"]', '2026-09-30');
    // Switch to Travel basis: both travel dates are in November, so a window
    // that correctly carried Sep 1–30 keeps zero travel rows — but the key
    // regression check is that the upper bound transferred (not open-ended).
    d.q('[data-preset-basis="travel"]').click();
    assert.equal(d.q('[data-f-date="travel_from"]').value, '2026-09-01');
    assert.equal(d.q('[data-f-date="travel_to"]').value, '2026-09-30');
    assert.equal(d.q('[data-f-date="created_from"]').value, '');
    assert.equal(d.q('[data-f-date="created_to"]').value, '');
    d.dom.window.close();
});
check('search spans all data, ignoring the active date window', () => {
    const d = setup([
        {...base, confirmation_code: 'RECENT', created_datetime: '2026-10-01 12:00:00'},
        {...base, confirmation_code: 'ANCIENT', product_title: 'Zzz Special', created_datetime: '2026-01-05 12:00:00', travel_datetime: '2026-01-10 12:00:00'},
    ], {default_view_months: 3});
    // Default view = last 3 months by created date (from ~2026-07-25), so the
    // January booking is hidden until searched for.
    assert.deepEqual(d.records(), ['RECENT']);
    d.set('[data-f-search]', 'zzz special', 'input');
    assert.ok(d.records().includes('ANCIENT'), 'search finds a booking outside the date window');
    assert.match(d.q('[data-filter-summary]').textContent, /search spans all dates/);
    d.dom.window.close();
});
ui.dom.window.close();
console.log(`${checks} Analytics interaction checks passed.`);
