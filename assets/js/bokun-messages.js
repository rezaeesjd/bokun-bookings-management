(function () {
    'use strict';
    document.querySelectorAll('[data-bokun-messages]').forEach(function (root) {
        var account = root.querySelector('[data-msg-account]');
        var filter = root.querySelector('[data-msg-filter]');
        var sort = root.querySelector('[data-msg-sort]');
        var search = root.querySelector('[data-msg-search]');
        var status = root.querySelector('[data-msg-status]');
        var list = root.querySelector('[data-msg-list]');
        var detail = root.querySelector('[data-msg-detail]');
        var conversations = [];
        var selected = null;
        var page = 1;
        var total = 0;
        var busy = false;
        var staleSelection = false;
        var inboxSequence = 0;
        var pendingInboxReload = false;
        var searchTimer;
        var accountNames = {};
        var stateNames = {needs_reply: 'Needs reply', needs_review: 'Needs review', answered: 'Answered', closed: 'Handled'};

        function element(tag, text, className) {
            var node = document.createElement(tag);
            if (text !== undefined) { node.textContent = text; }
            if (className) { node.className = className; }
            return node;
        }

        function message(text, failed) {
            status.textContent = text;
            status.classList.toggle('is-error', Boolean(failed));
        }

        function json(value, fallback) {
            try { return typeof value === 'string' ? JSON.parse(value) : (value || fallback); } catch (error) { return fallback; }
        }

        function localDate(value) {
            if (!value) { return ''; }
            var date = new Date(/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value) ? value.replace(' ', 'T') + 'Z' : value);
            return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString(undefined, {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short'});
        }

        function titleFor(item) {
            var customer = (item.subject || '').match(/Conversation with (.+?) about Viator booking/i);
            return customer ? customer[1] : (item.reference ? 'Booking BR-' + item.reference : 'Reference needs review');
        }

        function badge(text, kind) {
            return element('span', text, 'bokun-messages__badge bokun-messages__badge--' + String(kind || '').toLowerCase());
        }

        function disclosure(title) {
            var node = element('details');
            node.append(element('summary', title));
            return node;
        }

        function hasDirty() {
            if (!selected) { return false; }
            var reply = detail.querySelector('[data-msg-reply]');
            var notes = detail.querySelector('[data-msg-notes]');
            var revision = detail.querySelector('[data-msg-revision]');
            return Boolean((reply && reply.value !== (selected.draft ? selected.draft.body : '')) || (notes && notes.value !== (selected.conversation.notes || '')) || (revision && revision.value.trim()));
        }

        function canDiscard() {
            return !hasDirty() || window.confirm('You have unsaved reply text, operator notes, or AI editing instructions. Discard these changes and continue?');
        }

        async function api(action, data, method) {
            method = method || 'POST';
            var options = {method: method, credentials: 'same-origin', headers: {'X-WP-Nonce': root.dataset.nonce}};
            var url = new URL(root.dataset.api + action, window.location.href);
            if (method === 'GET') {
                Object.entries(data || {}).forEach(function (entry) { url.searchParams.set(entry[0], entry[1]); });
            } else {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(data || {});
            }
            var response = await fetch(url.toString(), options);
            var payload;
            try { payload = await response.json(); } catch (error) { throw new Error('The server returned an unexpected response. Your text is still here; check your administrator session before retrying.'); }
            if (response.status === 401 || response.status === 403) { throw new Error('Your administrator session needs refreshing. Keep a copy of unsaved text before reloading or signing in again.'); }
            if (!response.ok || payload.code) { throw new Error(payload.message || 'The request failed.'); }
            return payload;
        }

        async function run(action, freeze) {
            if (busy) { return; }
            busy = true;
            root.setAttribute('aria-busy', 'true');
            root.classList.add('is-busy');
            var controls = freeze ? Array.from(root.querySelectorAll('button,input,select,textarea')).map(function (control) {
                var state = {control: control, disabled: control.disabled};
                control.disabled = true;
                return state;
            }) : [];
            try { await action(); } catch (error) { message(error.message, true); }
            finally {
                controls.forEach(function (state) { if (state.control.isConnected) { state.control.disabled = state.disabled; } });
                if (staleSelection) {
                    var send = detail.querySelector('[data-msg-send]');
                    var approval = detail.querySelector('[data-msg-approval]');
                    if (send) { send.disabled = true; }
                    if (approval) { approval.checked = false; }
                }
                root.querySelector('[data-msg-prev]').disabled = page <= 1;
                root.querySelector('[data-msg-next]').disabled = page * 50 >= total;
                busy = false;
                root.setAttribute('aria-busy', 'false');
                root.classList.remove('is-busy');
                if (pendingInboxReload) { pendingInboxReload = false; run(loadInbox); }
            }
        }

        function button(text, action, primary) {
            var node = element('button', text, primary ? 'bokun-messages__primary' : '');
            node.type = 'button';
            node.addEventListener('click', function () { run(action, true); });
            return node;
        }

        function reloadInbox() {
            page = 1;
            inboxSequence += 1;
            if (busy) { pendingInboxReload = true; } else { run(loadInbox); }
        }

        function renderList() {
            list.replaceChildren();
            conversations.forEach(function (item) {
                var node = element('button', undefined, 'bokun-messages__item');
                node.type = 'button';
                node.setAttribute('aria-pressed', selected && Number(selected.conversation.id) === Number(item.id) ? 'true' : 'false');
                node.append(element('strong', titleFor(item)));
                node.append(element('small', (item.reference ? 'BR-' + item.reference : 'Unassigned') + ' · ' + (accountNames[item.account] || item.account)));
                if (item.preview) { node.append(element('span', item.preview, 'bokun-messages__preview')); }
                node.append(badge(stateNames[item.state] || item.state, item.state));
                node.append(element('small', localDate(item.latest_at)));
                node.addEventListener('click', function () { run(function () { return openConversation(item.id); }, true); });
                list.append(node);
            });
            if (!conversations.length) { list.append(element('p', 'No conversations match this view. Try another filter or synchronize the mailbox.')); }
            root.querySelector('[data-msg-page]').textContent = 'Page ' + page + ' · ' + total + ' conversations';
            root.querySelector('[data-msg-prev]').disabled = page <= 1;
            root.querySelector('[data-msg-next]').disabled = page * 50 >= total;
        }

        async function loadInbox() {
            var sequence = ++inboxSequence;
            var data = await api('inbox', {account: account.value, state: filter.value, sort: sort ? sort.value : 'recent', search: search.value, page: page}, 'GET');
            if (sequence !== inboxSequence) { return; }
            conversations = data.conversations;
            total = data.total;
            var counts = data.counts || {};
            root.querySelector('[data-msg-counts]').textContent = (counts.needs_reply || 0) + ' need reply · ' + (counts.needs_review || 0) + ' need review · ' + (counts.answered || 0) + ' answered · ' + (counts.closed || 0) + ' handled';
            if (selected) {
                var current = conversations.find(function (item) { return Number(item.id) === Number(selected.conversation.id); });
                if (current && (Number(current.version) !== Number(selected.conversation.version) || Number(current.latest_draft_id || 0) !== Number(selected.draft ? selected.draft.id : 0))) {
                    staleSelection = true;
                    var approval = detail.querySelector('[data-msg-approval]');
                    var send = detail.querySelector('[data-msg-send]');
                    if (approval) { approval.checked = false; }
                    if (send) { send.disabled = true; }
                    message('New messages, booking context, or a newer draft are available. Reload this conversation before approving. Unsaved text is kept until you choose to discard it.');
                }
            }
            var connections = root.querySelector('[data-msg-connections]');
            connections.replaceChildren();
            Object.entries(data.accounts).forEach(function (entry) {
                var item = entry[1];
                accountNames[entry[0]] = item.name;
                var text = item.name + ' · ' + (item.connected ? 'Connected' : 'Not connected');
                if (item.sync && typeof item.sync.message === 'string' && item.sync.message) { text += ' · ' + item.sync.message; }
                if (item.sync && typeof item.sync.at === 'string' && item.sync.at) { text += ' · Checked ' + localDate(item.sync.at); }
                var connection = element('p', text);
                connection.title = item.sync ? item.sync.message || '' : '';
                if (!item.connected || (item.sync && item.sync.error)) { connection.classList.add('bokun-messages__warning'); }
                connections.append(connection);
            });
            renderList();
        }

        async function openConversation(id, discardChecked) {
            if (!discardChecked && !canDiscard()) { return; }
            selected = await api('conversation', {id: id}, 'GET');
            staleSelection = false;
            renderDetail();
            renderList();
            message('Conversation loaded. Review the latest message and booking facts.');
        }

        async function openNextConversation() {
            if (!canDiscard()) { return; }
            var currentIndex = conversations.findIndex(function (item) { return selected && Number(item.id) === Number(selected.conversation.id); });
            var next = conversations[currentIndex + 1];
            if (!next && page * 50 < total) {
                page += 1;
                try { await loadInbox(); } catch (error) { page -= 1; throw error; }
                next = conversations[0];
            }
            if (!next) { message('You reached the end of this view. Change the filter or refresh the inbox to check for more.'); return; }
            await openConversation(next.id, true);
            detail.querySelector('h3').scrollIntoView({block: 'nearest'});
        }

        function factRow(parent, label, value) {
            if (value === undefined || value === null || value === '') { return; }
            var row = element('p', undefined, 'bokun-messages__fact');
            row.append(element('strong', label), document.createTextNode(String(value)));
            parent.append(row);
        }

        function displayDate(value, timezone) {
            if (!value) { return ''; }
            var numeric = Number(value);
            var date = new Date(Number.isFinite(numeric) && numeric > 1000000000 ? (numeric > 1000000000000 ? numeric : numeric * 1000) : value);
            if (Number.isNaN(date.getTime())) { return String(value); }
            if (!timezone) { return String(value) + ' (timezone not supplied)'; }
            try { return date.toLocaleString(undefined, {timeZone: timezone}) + ' · ' + timezone; } catch (error) { return String(value); }
        }

        function bookingFacts(parent, context) {
            var booking = context.live_booking || {};
            var local = context.local_booking || {};
            var summary = element('div', undefined, 'bokun-messages__facts');
            factRow(summary, 'Booking code', booking.confirmation || local._confirmation_code);
            factRow(summary, 'Lead traveler', booking.traveler_name);
            if (booking.status) { factRow(summary, 'Live booking status', booking.status); }
            parent.append(summary);
            if (local.url && /^https?:\/\//i.test(local.url)) {
                var link = element('a', 'Open booking record');
                link.href = local.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                parent.append(link);
            }
            if (!booking.products || !booking.products.length) { factRow(parent, 'Stored tour', local._product_title); }
            (booking.products || []).forEach(function (product) {
                var catalog = product.catalog || {};
                var fields = product.fields || {};
                parent.append(element('h5', product.title || 'Booked product'));
                if (product.status) { parent.append(badge('Live product: ' + product.status, product.status)); }
                factRow(parent, 'Booked option', product.option);
                factRow(parent, 'Departure', displayDate(product.date, catalog.timeZone));
                var participants = {};
                (fields.priceCategoryBookings || []).forEach(function (category) {
                    var label = (category.pricingCategory || {}).fullTitle || 'Guests';
                    participants[label] = (participants[label] || 0) + Number(category.quantity || 0);
                });
                factRow(parent, 'Participants', Object.entries(participants).map(function (entry) { return entry[1] + ' × ' + entry[0]; }).join(' · '));
                var more = disclosure('Product details & booking notes');
                factRow(more, 'Stored meeting point (verify)', local.bk_meetingpointtitle);
                Array.from(new Set(product.notes || [])).forEach(function (note) { more.append(element('p', note, 'bokun-messages__body')); });
                factRow(more, 'Current product inclusions', catalog.included);
                factRow(more, 'Current product exclusions', catalog.excluded);
                if (catalog.description) { more.append(element('p', catalog.description, 'bokun-messages__body')); }
                (catalog.agendaItems || []).forEach(function (item) {
                    more.append(element('strong', item.title || 'Itinerary stop'), element('p', item.description || item.excerpt || '', 'bokun-messages__body'));
                });
                if (catalog.warning) { more.append(element('p', catalog.warning, 'bokun-messages__warning')); }
                parent.append(more);
            });
            if ((local.operator_labels || []).length) {
                var workflow = disclosure('Stored workflow labels');
                workflow.append(element('p', local.operator_labels.join(' · ')));
                workflow.append(element('p', 'Local operational labels are separate from live Bókun status. They do not confirm a refund.', 'bokun-messages__muted'));
                parent.append(workflow);
            }
            (context.warnings || []).filter(function (warning) { return !warning.startsWith('Email history may omit'); }).forEach(function (warning) { parent.append(element('p', warning, 'bokun-messages__warning')); });
        }

        function messageCard(item) {
            var card = element('article', undefined, 'bokun-messages__message bokun-messages__message--' + item.kind);
            var names = {customer: 'Customer', outgoing: 'Our reply', support: 'Viator support'};
            card.append(element('strong', (names[item.kind] || 'Needs review') + ' · ' + localDate(item.sent_at)));
            if (item.review_reason) { card.append(element('p', item.review_reason, 'bokun-messages__warning')); }
            var body = item.body || '(No text; see attachment metadata.)';
            if (body.length > 650) {
                card.append(element('p', body.slice(0, 280).trim() + '…', 'bokun-messages__body'));
                var full = disclosure('Read full message');
                full.append(element('p', body, 'bokun-messages__body'));
                card.append(full);
            } else { card.append(element('p', body, 'bokun-messages__body')); }
            var attachments = json(item.attachments, []);
            if (attachments.length) {
                var files = disclosure(attachments.length + ' attachments · contents not read');
                attachments.forEach(function (attachment) { files.append(element('p', attachment.name, 'bokun-messages__muted')); });
                card.append(files);
            }
            return card;
        }

        function renderDetail() {
            detail.replaceChildren();
            var data = selected;
            var conversation = data.conversation;
            var context = data.context;
            var titlebar = element('div', undefined, 'bokun-messages__titlebar');
            var title = element('div');
            title.append(element('h3', titleFor(conversation)), element('p', data.account.name + ' · ' + (conversation.reference ? 'BR-' + conversation.reference : 'Unassigned reference'), 'bokun-messages__muted'), badge(stateNames[conversation.state] || conversation.state, conversation.state));
            var navigation = element('div', undefined, 'bokun-messages__actions');
            navigation.append(button('Reload conversation', function () { return openConversation(conversation.id); }), button('Next in this view', openNextConversation));
            titlebar.append(title, navigation);
            detail.append(titlebar);
            var columns = element('div', undefined, 'bokun-messages__review');
            var main = element('div', undefined, 'bokun-messages__main');
            var history = element('section', undefined, 'bokun-messages__history');
            history.setAttribute('aria-label', 'Conversation history');
            history.tabIndex = 0;
            history.append(element('h4', 'Conversation history'));
            if (data.messages.length > 2) {
                var older = disclosure((data.messages.length - 2) + ' earlier messages');
                data.messages.slice(0, -2).forEach(function (item) { older.append(messageCard(item)); });
                history.append(older);
            }
            data.messages.slice(-2).forEach(function (item) { history.append(messageCard(item)); });
            main.append(history);
            var facts = element('section', undefined, 'bokun-messages__context');
            facts.setAttribute('aria-label', 'Booking context');
            facts.append(element('h4', 'Booking context'));
            facts.append(button('Refresh from Bókun', async function () {
                var carry = detail.querySelector('[data-msg-reply]').value;
                var carryRevision = detail.querySelector('[data-msg-revision]').value;
                requireSavedNotes();
                message('Refreshing this booking from Bókun…');
                selected = await api('refresh', {id: conversation.id});
                staleSelection = false;
                renderDetail();
                restoreReply(carry);
                detail.querySelector('[data-msg-revision]').value = carryRevision;
                message('Booking refreshed. Your reply text is preserved; review changes before saving or sending.');
            }));
            facts.append(element('p', context.booking_checked_at ? 'Checked ' + localDate(new Date(context.booking_checked_at * 1000).toISOString()) : 'Live lookup required before drafting.', 'bokun-messages__muted'));
            bookingFacts(facts, context);
            var notesPanel = disclosure('Verified operator context');
            var notesLabel = element('label', 'Verified operator context for this booking');
            var notes = element('textarea');
            notes.dataset.msgNotes = '';
            notes.rows = 4;
            notes.value = conversation.notes || '';
            notesLabel.append(notes);
            notesPanel.append(notesLabel, element('p', 'Add confirmed facts or handling instructions. Save before generating or sending a reply.', 'bokun-messages__muted'));
            notesPanel.append(button('Save operator context', async function () {
                var carry = reply.value;
                var carryRevision = revision.value;
                selected = await api('notes', {id: conversation.id, context_hash: context.hash, notes: notes.value});
                staleSelection = false;
                renderDetail();
                restoreReply(carry);
                detail.querySelector('[data-msg-revision]').value = carryRevision;
                message('Operator context saved. Reply text is preserved; review it and save a new draft.');
            }));
            facts.append(notesPanel);
            var coverage = disclosure('Conversation coverage');
            coverage.append(element('p', 'Email history may omit replies made in other mailboxes or Viator. Check that history before approving. Attachment contents are not read.', 'bokun-messages__muted'));
            facts.append(coverage);

            function requireSavedNotes() {
                if (notes.value !== (conversation.notes || '')) { throw new Error('Save your operator context before this action so AI and approval use those facts.'); }
            }

            function restoreReply(value) {
                var current = detail.querySelector('[data-msg-reply]');
                if (current && value !== current.value) { current.value = value; current.dispatchEvent(new Event('input', {bubbles: true})); }
            }

            var draftArea = element('section', undefined, 'bokun-messages__draft');
            draftArea.setAttribute('aria-label', 'Reply composer');
            draftArea.append(element('h4', 'Reply composer'));
            draftArea.append(button(data.draft ? 'Regenerate with AI' : 'Analyze & draft reply', async function () {
                requireSavedNotes();
                if (!canDiscard()) { return; }
                message('Preparing a reply and internal action plan…');
                selected = await api('draft', {id: conversation.id, context_hash: context.hash});
                staleSelection = false;
                renderDetail();
                await loadInbox();
                message(selected.draft && selected.draft.status === 'review' ? 'AI review saved without reply text. Review the recommendation, then mark handled or write and save a reply. Nothing was sent.' : 'AI draft saved. Review it; nothing has been sent.');
            }));
            var analysis = data.draft ? json(data.draft.analysis, {}) : {};
            if (analysis.summary) {
                var assessment = element('div', undefined, 'bokun-messages__analysis');
                assessment.append(element('strong', analysis.reply_needed === false ? 'AI: review whether a reply is needed' : 'Customer response recommended'), element('p', analysis.summary));
                if (analysis.decision_reason) { assessment.append(element('p', analysis.decision_reason, 'bokun-messages__muted')); }
                if (analysis.next_step) { assessment.append(element('p', 'Next step: ' + analysis.next_step)); }
                if (analysis.edited_by) { assessment.append(element('p', 'Manually edited since this analysis. Recheck the final text.', 'bokun-messages__muted')); }
                var checks = Array.from(new Set((analysis.missing_information || []).filter(function (warning) { return !(context.warnings || []).includes(warning); })));
                if (checks.length) {
                    var checkBox = element('div', undefined, 'bokun-messages__warning');
                    checkBox.append(element('strong', 'Verify before replying'));
                    var checkList = element('ul');
                    checks.forEach(function (check) { checkList.append(element('li', check)); });
                    checkBox.append(checkList);
                    assessment.append(checkBox);
                }
                var sources = disclosure('Source facts (' + (analysis.facts_used || []).length + ')');
                (analysis.facts_used || []).forEach(function (fact) { sources.append(element('p', fact, 'bokun-messages__muted')); });
                assessment.append(sources);
                draftArea.append(assessment);
            }
            if (data.draft && data.draft.context_hash !== context.hash) { draftArea.append(element('p', 'This draft predates updated context or drafting safeguards. Regenerate with AI, or review and save a new manual version before approval.', 'bokun-messages__warning')); }
            var recipient = disclosure('Sending from / to');
            factRow(recipient, 'From', data.account.email);
            factRow(recipient, 'To', context.reply_to || 'No verified customer relay address');
            draftArea.append(recipient);
            var replyLabel = element('label', 'Reply text');
            var reply = element('textarea');
            reply.dataset.msgReply = '';
            reply.rows = 9;
            reply.value = data.draft ? data.draft.body : '';
            replyLabel.append(reply);
            draftArea.append(replyLabel);
            var dirty = element('p', undefined, 'bokun-messages__dirty');
            dirty.setAttribute('aria-live', 'polite');
            draftArea.append(dirty);
            var revisionLabel = element('label', 'Ask AI to edit this reply');
            var revision = element('textarea');
            revision.dataset.msgRevision = '';
            revision.rows = 2;
            revision.maxLength = 2000;
            revision.placeholder = 'Describe a change, or choose a shortcut below.';
            revisionLabel.append(revision);
            draftArea.append(revisionLabel);
            var presets = element('div', undefined, 'bokun-messages__actions');
            [['Shorter', 'Make this reply shorter and direct, preserving verified facts.'], ['Warmer', 'Make this reply warmer and more natural without adding promises.'], ['Italian', 'Translate this reply into natural Italian, preserving verified facts.'], ['English', 'Translate this reply into natural English, preserving verified facts.']].forEach(function (preset) {
                var shortcut = element('button', preset[0]);
                shortcut.type = 'button';
                shortcut.addEventListener('click', function () { revision.value = preset[1]; revision.focus(); });
                presets.append(shortcut);
            });
            draftArea.append(presets);
            var actions = element('div', undefined, 'bokun-messages__actions');
            actions.append(button('Rewrite with AI', async function () {
                requireSavedNotes();
                if (!reply.value.trim() || !revision.value.trim()) { throw new Error('Write a reply and describe how AI should edit it.'); }
                message('Rewriting with verified booking context. Nothing is being sent.');
                selected = await api('revise', {id: conversation.id, context_hash: context.hash, base_draft_id: data.draft ? data.draft.id : 0, body: reply.value, instructions: revision.value});
                staleSelection = false;
                renderDetail();
                await loadInbox();
                message('AI revision saved as a new unapproved version.');
            }));
            actions.append(button('Save edited draft', async function () {
                requireSavedNotes();
                selected = await api('save', {id: conversation.id, context_hash: context.hash, body: reply.value});
                staleSelection = false;
                renderDetail();
                await loadInbox();
                message('Draft saved. Review the exact text and approve separately to send.');
            }, true));
            draftArea.append(actions);
            var approvalLabel = element('label', undefined, 'bokun-messages__approval');
            var approval = element('input');
            approval.type = 'checkbox';
            approval.disabled = Boolean(data.draft && data.draft.status === 'review');
            approval.dataset.msgApproval = '';
            approvalLabel.append(approval, document.createTextNode(' I checked the recipient, booking facts, missing information, and exact saved reply. I approve sending it.'));
            draftArea.append(approvalLabel);
            var sendButton = button('Approve & send saved reply', async function () {
                requireSavedNotes();
                if (!approval.checked || !data.draft || reply.value !== data.draft.body || staleSelection) { throw new Error('Save your edits and approve the current saved reply first.'); }
                message('Checking Gmail and Bókun again before sending…');
                var result = await api('send', {id: conversation.id, context_hash: context.hash, draft_id: data.draft.id, approved: true});
                selected = await api('conversation', {id: conversation.id}, 'GET');
                staleSelection = false;
                renderDetail();
                await loadInbox();
                message(result.message);
            }, true);
            sendButton.dataset.msgSend = '';
            var approvalHelp = element('p', undefined, 'bokun-messages__muted');
            function updateApproval() {
                var edited = reply.value !== (data.draft ? data.draft.body : '');
                var noteEdited = notes.value !== (conversation.notes || '');
                var bytes = new TextEncoder().encode(reply.value).length;
                dirty.textContent = edited ? 'Unsaved changes · ' + bytes.toLocaleString() + ' / 20,000 bytes' : (data.draft ? 'Saved draft #' + data.draft.id + ' · ' + data.draft.status : 'No saved draft yet');
                sendButton.disabled = !approval.checked || !data.draft || data.draft.status !== 'draft' || edited || noteEdited || staleSelection || data.draft.context_hash !== context.hash || !context.reply_to || bytes > 20000;
                approvalHelp.textContent = edited ? 'Save your edited reply before approving.' : (noteEdited ? 'Save operator context and review the draft again.' : (staleSelection || (data.draft && data.draft.context_hash !== context.hash) ? 'Reload or regenerate the stale draft before approval.' : (data.draft && data.draft.status === 'review' ? 'Analysis only: mark handled after review, or write and save a reply before approving.' : 'Nothing is sent until you check approval and select Send.')));
            }
            reply.addEventListener('input', function () { approval.checked = false; updateApproval(); });
            notes.addEventListener('input', function () { approval.checked = false; updateApproval(); });
            approval.addEventListener('change', updateApproval);
            updateApproval();
            draftArea.append(sendButton, approvalHelp);
            if (data.draft && data.draft.error) { draftArea.append(element('p', data.draft.error, 'bokun-messages__warning')); }
            if (data.draft && ['unknown', 'sending'].includes(data.draft.status)) {
                draftArea.append(button('Reconcile uncertain send', async function () {
                    selected = await api('reconcile', {id: conversation.id});
                    staleSelection = false;
                    renderDetail();
                    message('A Sent copy was found. The draft is recorded as sent.');
                }));
            }
            var versions = data.draft_versions || [];
            if (versions.length > 1) {
                var versionPanel = disclosure('Draft history · latest ' + versions.length + ' versions');
                versions.forEach(function (version) {
                    var entry = disclosure('Draft #' + version.id + ' · ' + version.status + ' · ' + localDate(version.created_at));
                    entry.append(element('p', version.body || 'Analysis only; no reply text saved.', 'bokun-messages__body'));
                    if (version.body && Number(version.id) !== Number(data.draft.id)) {
                        var use = element('button', 'Use this text as an edit');
                        use.type = 'button';
                        use.addEventListener('click', function () {
                            if (reply.value !== (data.draft ? data.draft.body : '') && !window.confirm('Replace your unsaved reply with this earlier text?')) { return; }
                            reply.value = version.body;
                            approval.checked = false;
                            updateApproval();
                            reply.focus();
                        });
                        entry.append(use);
                    }
                    versionPanel.append(entry);
                });
                draftArea.append(versionPanel);
            }
            draftArea.append(button(conversation.state === 'closed' ? 'Reopen conversation' : 'Mark handled without sending', async function () {
                if (!canDiscard()) { return; }
                var action = conversation.state === 'closed' ? 'reopen' : 'close';
                selected = await api(action, {id: conversation.id, context_hash: context.hash});
                staleSelection = false;
                renderDetail();
                await loadInbox();
                message(action === 'reopen' ? 'Conversation reopened for review. No reply was sent.' : 'Marked handled. You can reopen it; no reply was sent.');
            }));
            main.append(draftArea);
            columns.append(main, facts);
            detail.append(columns);
        }

        root.querySelector('[data-msg-sync]').addEventListener('click', function () { run(async function () {
            var targets = account.value ? [account.value] : Object.keys(accountNames);
            if (!targets.length) { throw new Error('Connect a mailbox in Connections first.'); }
            var imported = 0;
            var more = false;
            var errors = [];
            for (var target of targets) {
                message('Synchronizing ' + accountNames[target] + '…');
                try {
                    var result = await api('sync', {account: target});
                    imported += Number(result.imported || 0);
                    more = more || result.more;
                } catch (error) { errors.push(accountNames[target] + ': ' + error.message); }
            }
            await loadInbox();
            message(errors.length ? errors.join(' · ') : 'Imported ' + imported + ' new messages. ' + (more ? 'Backfill continues in the background.' : 'Synchronization is current.'), errors.length > 0);
        }, true); });
        root.querySelector('[data-msg-reload]').addEventListener('click', function () { run(loadInbox); });
        search.addEventListener('input', function () { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(reloadInbox, 300); });
        filter.addEventListener('change', reloadInbox);
        if (sort) { sort.addEventListener('change', reloadInbox); }
        account.addEventListener('change', function () {
            if (selected && account.value && selected.conversation.account !== account.value) {
                if (!canDiscard()) { account.value = selected.conversation.account; return; }
                selected = null;
                detail.replaceChildren(element('p', 'Select a conversation for this business.'));
                message('Select a conversation for this business.');
            }
            root.querySelector('[data-msg-sync]').textContent = account.value ? 'Sync mailbox' : 'Sync both mailboxes';
            reloadInbox();
        });
        root.querySelector('[data-msg-prev]').addEventListener('click', function () { if (!busy) { page = Math.max(1, page - 1); run(loadInbox); } });
        root.querySelector('[data-msg-next]').addEventListener('click', function () { if (!busy) { page += 1; run(loadInbox); } });
        window.addEventListener('beforeunload', function (event) { if (hasDirty()) { event.preventDefault(); event.returnValue = ''; } });
        run(loadInbox);
        window.setInterval(function () { if (!document.hidden && !busy) { run(loadInbox); } }, 60000);
    });
})();
