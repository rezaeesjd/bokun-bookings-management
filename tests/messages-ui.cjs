const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const dom = new JSDOM('<section data-bokun-messages data-api="/messages/" data-nonce="test"><select data-msg-account><option value="">All</option><option value="florence-adventures">Florence</option><option value="via-florence">Via</option></select><select data-msg-filter><option value="attention">Attention</option><option value="closed">Handled</option></select><input data-msg-search><p data-msg-status></p><p data-msg-counts></p><div data-msg-connections></div><button data-msg-sync>Sync both mailboxes</button><button data-msg-reload>Refresh inbox</button><div data-msg-list></div><button data-msg-prev>Previous</button><span data-msg-page></span><button data-msg-next>Next</button><div data-msg-detail></div></section>', {url: 'http://messages.example.test/', runScripts: 'outside-only'});
Object.defineProperty(dom.window.document, 'hidden', {value: false});
const window = dom.window;
window.HTMLElement.prototype.scrollIntoView = function () {};
window.TextEncoder = TextEncoder;
const calls = [];
let allowDiscard = false;
let poll;
let releaseRevision;
let delayRevision = false;
let checks = 0;
window.confirm = () => allowDiscard;
window.setInterval = callback => { poll = callback; };
const accounts = {'florence-adventures': {name: 'Florence Adventures', email: 'amerigovespuccitours@gmail.com', connected: true, sync: {at: '2026-10-22T02:00:00Z', message: 'Imported 0 new messages.'}}, 'via-florence': {name: 'Via Florence', connected: true}};
function fixture(id, account) {
    return {conversation: {id, account, reference: String(9000000000 + id), subject: 'Conversation with Test Guest ' + id + ' about Viator booking BR-' + (9000000000 + id), state: 'needs_reply', version: 1, notes: ''}, account: accounts[account], messages: [{kind: 'customer', sent_at: '2026-10-21 11:00:00', body: 'Please confirm the refund. <img src=x onerror=alert(1)>', attachments: '[]'}, {kind: 'support', sent_at: '2026-10-21 12:00:00', body: 'Support details '.repeat(70), attachments: JSON.stringify([{name: 'attachment.png'}])}], context: {hash: 'context-' + id, booking_checked_at: 1792580000, reply_to: 'NOT-test@expmessaging.tripadvisor.com', warnings: ['Email history may omit replies made in other mailboxes or Viator. Review coverage before approving.'], live_booking: {confirmation: 'VIA-TEST-' + id, traveler_name: 'Test Guest ' + id, products: [{title: 'Synthetic tour', status: 'CANCELLED', option: 'Standard', date: '2026-11-12T09:00:00+01:00', fields: {priceCategoryBookings: [{quantity: 1, pricingCategory: {fullTitle: 'Adult'}}, {quantity: 1, pricingCategory: {fullTitle: 'Adult'}}]}, notes: ['Full booking context '.repeat(40)], catalog: {timeZone: 'Europe/Rome', included: 'Guide', excluded: 'Transport'}}]}, local_booking: {operator_labels: ['CONFIRMED', 'Not Available']}}, draft: {id: id * 10, body: 'Thank you. We will check the refund status.', context_hash: 'context-' + id, status: 'draft', analysis: JSON.stringify({summary: 'Refund question.', reply_needed: true, decision_reason: 'Customer is waiting.', next_step: 'Check refund status.', facts_used: ['live product cancelled'], missing_information: ['Refund confirmation']})}, draft_versions: [{id: id * 10, body: 'Thank you. We will check the refund status.', status: 'draft', created_at: '2026-10-21 12:00:00'}, {id: id * 10 - 1, body: 'Earlier saved wording.', status: 'draft', created_at: '2026-10-21 11:00:00'}]};
}
const records = {1: fixture(1, 'florence-adventures'), 2: fixture(2, 'via-florence')};
accounts['via-florence'].sync = [];
const clone = value => JSON.parse(JSON.stringify(value));
window.fetch = async (url, options) => {
    const parsed = new URL(url);
    const action = parsed.pathname.split('/').pop();
    const data = options.method === 'GET' ? Object.fromEntries(parsed.searchParams) : JSON.parse(options.body);
    calls.push({action, data});
    let result;
    if (action === 'inbox') {
        result = {accounts, counts: {needs_reply: 2}, conversations: Object.values(records).filter(record => !data.account || record.conversation.account === data.account).map(record => ({...record.conversation, latest_at: '2026-10-21 12:00:00', latest_draft_id: record.draft.id, preview: record.messages[0].body})), total: 2};
    } else if (action === 'sync') { result = {imported: 0}; }
    else {
        const record = records[data.id];
        if (action === 'revise') {
            if (delayRevision) { await new Promise(resolve => { releaseRevision = resolve; }); }
            record.draft = {...record.draft, id: record.draft.id + 1, body: 'Revised safe reply.'};
        } else if (action === 'save') { record.draft = {...record.draft, id: record.draft.id + 1, body: data.body, status: 'draft', context_hash: record.context.hash}; }
        else if (action === 'notes') { record.conversation.notes = data.notes; record.conversation.version++; record.context.hash += '-notes'; }
        else if (action === 'close') { record.conversation.state = 'closed'; record.conversation.version++; }
        else if (action === 'reopen') { record.conversation.state = 'needs_reply'; record.conversation.version++; }
        result = record;
    }
    return {ok: true, json: async () => clone(result)};
};
window.eval(fs.readFileSync(path.join(__dirname, '../assets/js/bokun-messages.js'), 'utf8'));
const document = window.document;
const query = selector => document.querySelector(selector);
const named = name => [...document.querySelectorAll('button')].find(button => button.textContent === name);
async function settle() { for (let turn = 0; turn < 6; turn++) { await new Promise(resolve => setImmediate(resolve)); } }
async function click(name) { assert.ok(named(name), 'Button exists: ' + name); named(name).click(); await settle(); }
function check(condition, label) { assert.ok(condition, label); checks++; console.log('PASS ' + label); }
function input(selector, value) { query(selector).value = value; query(selector).dispatchEvent(new window.Event('input', {bubbles: true})); }
(async () => {
    await settle();
    check(!query('[data-msg-connections]').textContent.includes('function'), 'empty sync arrays never display JavaScript method text');
    accounts['via-florence'].sync = {at: '2026-10-22T02:00:00Z', message: 'Service unavailable; reconnect the mailbox.'};
    poll(); await settle();
    check(query('[data-msg-connections]').textContent.includes('Service unavailable; reconnect the mailbox.') && !query('[data-msg-connections]').textContent.includes('Synced '), 'failed sync messages remain visible and attempts are not mislabeled as successful syncs');
    query('.bokun-messages__item').click(); await settle();
    check(query('.bokun-messages__main').contains(query('[aria-label="Reply composer"]')), 'composer shares the main column with history rather than waiting below booking details');
    check(query('[aria-label="Booking context"]').textContent.includes('2 × Adult'), 'duplicate participant rows are aggregated without losing travelers');
    check(![...document.querySelectorAll('summary')].find(node => node.textContent === 'Product details & booking notes').parentElement.open, 'verbose product and booking context is collapsed by default');
    check(document.querySelectorAll('.bokun-messages__message img').length === 0, 'message HTML remains inert text');
    check([...document.querySelectorAll('summary')].some(node => node.textContent === 'Read full message'), 'long support messages retain a full-text disclosure');
    check(query('[data-msg-send]').disabled && calls.every(call => call.action !== 'send'), 'opening a conversation never approves or sends');
    check([...document.querySelectorAll('summary')].some(node => node.textContent.startsWith('Draft history')), 'immutable draft history is available for review');
    input('[data-msg-reply]', 'My unsaved edit.');
    await click('Reload conversation');
    check(query('[data-msg-reply]').value === 'My unsaved edit.', 'cancelled reload keeps unsaved reply text');
    const unload = new window.Event('beforeunload', {cancelable: true});
    window.dispatchEvent(unload);
    check(unload.defaultPrevented, 'browser navigation warns when edits are unsaved');
    const queryAll = [...document.querySelectorAll('.bokun-messages__item')];
    queryAll[1].click(); await settle();
    check(query('[data-msg-reply]').value === 'My unsaved edit.', 'cancelled conversation switch does not lose edits');
    await click('Next in this view');
    check(query('[data-msg-reply]').value === 'My unsaved edit.', 'next-in-view navigation also protects unsaved text');
    input('[data-msg-notes]', 'Verified operator instruction.');
    await click('Save operator context');
    check(query('[data-msg-reply]').value === 'My unsaved edit.' && records[1].conversation.notes === 'Verified operator instruction.', 'saving operator notes preserves unsaved reply text');
    await click('Save edited draft');
    check(query('[data-msg-reply]').value === 'My unsaved edit.' && query('[data-msg-send]').disabled, 'manual save creates a saved but unapproved draft');
    query('[data-msg-approval]').checked = true;
    query('[data-msg-approval]').dispatchEvent(new window.Event('change'));
    check(!query('[data-msg-send]').disabled, 'only explicit approval enables the current saved reply');
    input('[data-msg-notes]', 'Changed unsaved note.');
    check(query('[data-msg-send]').disabled && !query('[data-msg-approval]').checked, 'editing operator notes clears approval and blocks send');
    input('[data-msg-notes]', records[1].conversation.notes);
    input('[data-msg-revision]', 'Shorter.');
    delayRevision = true;
    named('Rewrite with AI').click(); await settle();
    check(query('[data-msg-reply]').disabled && query('[data-msg-notes]').disabled, 'editing is frozen while a foreground AI operation is pending');
    releaseRevision(); await settle(); delayRevision = false;
    check(query('[data-msg-reply]').value === 'Revised safe reply.' && !query('[data-msg-reply]').disabled && query('[data-msg-send]').disabled, 'AI completion restores editable fields and keeps approval off');
    [...document.querySelectorAll('details')].find(panel => panel.querySelector('p') && panel.querySelector('p').textContent === 'Earlier saved wording.').querySelector('button').click();
    check(query('[data-msg-reply]').value === 'Earlier saved wording.' && query('[data-msg-send]').disabled, 'using an earlier draft only edits the composer and requires a new save');
    input('[data-msg-reply]', records[1].draft.body);
    records[1].conversation.version++;
    poll(); await settle();
    check(query('[data-msg-send]').disabled && query('[data-msg-status]').textContent.includes('New messages'), 'background changes invalidate client approval without replacing text');
    await click('Reload conversation');
    await click('Sync both mailboxes');
    check(calls.filter(call => call.action === 'sync').length === 2 && calls.every(call => call.action !== 'send'), 'All businesses sync handles both mailboxes without sending');
    await click('Mark handled without sending');
    check(named('Reopen conversation'), 'handled conversations offer a reversible reopen action');
    await click('Reopen conversation');
    check(records[1].conversation.state === 'needs_reply', 'reopen restores review without sending');
    await click('Next in this view');
    check(query('[data-msg-detail] h3').textContent === 'Test Guest 2' && query('[data-msg-send]').disabled, 'next-in-view opens the next conversation without copying approval or sending');
    await click('Next in this view');
    check(query('[data-msg-status]').textContent.includes('end of this view'), 'the queue ends explicitly instead of reopening a handled conversation');
    document.querySelector('.bokun-messages__item').click(); await settle();
    records[1].draft = {...records[1].draft, body: '', status: 'review', analysis: JSON.stringify({reply_needed: false, summary: 'Clear acknowledgement.', decision_reason: 'No outstanding question.', next_step: 'Review and mark handled.'})};
    await click('Reload conversation');
    check(query('[data-msg-reply]').value === '' && query('[data-msg-approval]').disabled && query('[data-msg-send]').disabled, 'analysis-only recommendations never create sendable placeholder text');
    input('[data-msg-reply]', 'A manually chosen response.');
    await click('Save edited draft');
    check(!query('[data-msg-approval]').disabled && !query('[data-msg-approval]').checked && query('[data-msg-send]').disabled, 'manual override of analysis-only review creates a new separately approved draft');
    input('[data-msg-reply]', 'Unsaved cross-business edit.');
    query('[data-msg-account]').value = 'via-florence';
    query('[data-msg-account]').dispatchEvent(new window.Event('change')); await settle();
    check(query('[data-msg-account]').value === 'florence-adventures' && query('[data-msg-reply]').value === 'Unsaved cross-business edit.', 'cancelled business change restores the correct mailbox and preserves text');
    allowDiscard = true;
    query('[data-msg-account]').value = 'via-florence';
    query('[data-msg-account]').dispatchEvent(new window.Event('change')); await settle();
    check(!query('[data-msg-reply]') && query('[data-msg-detail]').textContent.includes('Select a conversation'), 'confirmed business change clears the previous business context');
    check(calls.every(call => call.action !== 'send'), 'no UI regression test sends a customer message');
    console.log('Completed ' + checks + ' UI regression checks.');
    window.close();
})().catch(error => { console.error(error); window.close(); process.exitCode = 1; });
