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
function setup(data = rows) {
    const rendered = spawnSync(process.env.BOKUN_TEST_PHP || 'php',
        [...JSON.parse(process.env.BOKUN_TEST_PHP_ARGS || '[]'), path.join(__dirname, 'analytics-fixture.php')],
        {input: JSON.stringify({rows: data}), encoding: 'utf8'});
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
    return {dom, q, item, count, records, set, drill};
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
ui.dom.window.close();
console.log(`${checks} Analytics interaction checks passed.`);
