# Administrator-reviewed booking messages

The booking dashboard includes a **Messages** tab. The same inbox is available under **Booking Messages** in WordPress administration, or through `[bokun_messages]`.

Only signed-in administrators with `manage_options` can load message data or use message actions. A guest visiting the dashboard sees a sign-in notice, not conversations. The legacy message composer now also requires authenticated staff.

## Administrator workflow

1. Choose the business mailbox and synchronize. Use the status filter for messages needing attention, answered conversations, or handled requests. Search finds booking references and customer names across the entire inbox, not just the displayed page. Background synchronization also runs every five minutes through WP-Cron.
2. Open a conversation. The inbox shows customer names, booking references, latest-message previews, status badges and local timestamps. Received messages, sent replies, and support notices remain distinct. Long messages, older history and attachment metadata can be expanded without hiding or discarding the full source text. Search covers message bodies and stored booking snapshots as well as references and subjects.
3. Draft generation automatically refreshes the booking by external reference, including historical bookings outside the dashboard date window. **Refresh from Bókun** also retrieves it on demand. Product descriptions, itinerary, inclusions, exclusions, and option references come from the existing Bókun activity client, whose product cache can be up to one hour old. An unavailable or ambiguous booking blocks AI generation rather than silently drafting without it.
4. Review the booking and operator workflow labels. Add verified operator context if needed; saving it invalidates previous drafts.
5. Use the **Reply composer** beside the compact booking context. Analyze and draft with AI, or type a reply. Review the suggested next step, source facts and missing information. Edit manually, or describe a change under **Ask AI to edit this reply** and select **Rewrite with AI**. Shorter, warmer, Italian and English shortcuts populate editing instructions; they do not call AI or send on their own. AI editing uses the current text, including unsaved manual edits, plus refreshed Bókun context and history. Each AI revision is a new unapproved draft. Draft history shows the latest ten versions; using earlier text only edits the composer and requires a new save and approval.
6. Tick the explicit approval checkbox, then select **Approve & send saved reply**. The server refreshes the Bókun booking and checks Gmail for new messages. Changed context blocks sending until reviewed again.
7. Use **Mark handled without sending** when no response is needed; **Reopen conversation** reverses that workflow choice without sending. A sent draft records the approving administrator and exact text. Gmail acceptance is recorded separately from customer delivery.
8. Use **Next in this view** to continue through the current filtered queue. It preserves the same unsaved-edit warning, never transfers approval, and stops explicitly at the end of the results. It does not automatically generate or send anything.

Unsaved reply text, operator notes and AI editing instructions trigger a discard warning before switching conversations or business, reloading the conversation, regenerating, or marking handled. Browser navigation also warns where supported. Saving operator notes or refreshing Bókun preserves current reply text and editing instructions. Foreground requests temporarily disable editing to avoid losing changes while a response is in flight. Unsaved text lives only in the current page, not persistent browser storage. An unexpected tab crash or forced close can still lose it; save important edits.

Editing operator notes clears approval, and those notes must be saved before drafting, rewriting, saving a reply or sending. Background inbox refresh does not replace the open editor; a changed conversation or newer draft disables approval until reviewed. Switching to another business cannot leave the previous business's composer active.

There is no automatic sending mode. Automatic drafting is optional and defaults to off. Enabling it prepares a new draft after relevant incoming messages have been synchronized; an administrator still reviews and sends each reply.

## Setup

Open **Booking Messages → Connections**.

### Gmail

Create or use a Google Cloud **Web application** OAuth client with the Gmail API enabled. Configure the exact redirect URI displayed in Connections. Save the client ID and secret, then use the separate Connect buttons for:

| Business | Gmail mailbox |
| --- | --- |
| Florence Adventures | amerigovespuccitours@gmail.com |
| Via Florence | fiorifirenzesrl@gmail.com |

The connection requests `gmail.readonly` and `gmail.send`. It does not request permission to delete or modify mail. Offline access is needed for background processing. The callback verifies the selected mailbox against the configured account before storing encrypted tokens.

An already signed-in Chrome Gmail tab does not provide backend authorization. Google OAuth consent must be completed for each mailbox. Testing-mode Gmail grants expire after seven days. For continued operation, use the appropriate production publishing status and renew both authorizations. This is an internal-use, administrator-only application, not a public signup service. Google's technical **Internal** audience excludes consumer Gmail accounts outside the parent Workspace organization; **External** can support these two accounts while the application itself enforces its fixed mailbox allowlist. Publishing does not mean Google has verified the app, and unverified-app warnings and caps can remain. See [Google's audience documentation](https://support.google.com/cloud/answer/15549945?hl=en).

Initial synchronization checks the last 90 days, including Sent and archived mail, and imports the earlier messages returned inside matching threads. Each pass handles a bounded page; the durable cursor resumes backfill and overlaps subsequent runs to recover delayed notifications. Spam and Trash are excluded. Set up a real scheduled WP-Cron invocation for reliable operation on a low-traffic website.

### Bókun account mapping

Select the correct existing Bókun API credential context for each mailbox. Verify ownership before saving. Do not infer account identity from a product city: a Via Florence product can depart from Rome.

Local matching includes historical draft/private bookings. New imports record their credential context. Legacy records without this field can be matched by the explicit vendor aliases configured in `BOKUN_Messages::accounts()`. The live external-reference lookup uses only the selected API credential and does not run the main import, change booking statuses, or prune older records.

Changing an account mapping clears its live booking snapshots and invalidates existing drafts. Multiple or absent exact matches require administrator review.

### OpenAI

Set `BOKUN_MESSAGES_OPENAI_API_KEY` in protected `wp-config.php`, provide `OPENAI_API_KEY` in the server environment, or enter the key in Connections. Environment and constant values take precedence over the encrypted option.

The local development `.env.local` file is not automatically loaded by WordPress and must never be included in the install ZIP or a public web directory. Configure the production key separately. The default drafting model is `gpt-4.1-mini`; the model is editable in Connections.

The implementation uses the Responses API with a strict JSON schema, no action tools, and `store: false`. Only the assembled booking context and verified relevant message content are submitted for drafting. Disabling API storage does not itself establish a zero-data-retention agreement.

Drafting explicitly identifies the latest verified customer message and latest conversation event while retaining the complete eligible history. Earlier booking questions and quoted special requirements remain background, not proof of confirmed arrangements. Refund/cancellation conflicts require review rather than a new pre-tour confirmation; relevant schedules must come from the live booking and product time zone. These safeguards guide drafting, but every answer still requires administrator review.

Version 1.2.0 adds a reply-decision reason and actionable internal next step. An unanswered customer event cannot receive a no-reply recommendation merely because the tour is cancelled or a later support notice exists. Refund issuance reported by Viator is attributed to that source, not treated as proof the money reached the customer. No bank-processing timeline or operator capability is assumed. Travel-safety questions do not authorize current-condition guarantees; a confirmed cancelled booking remains relevant, with current official guidance requiring a separate operator check. Local workflow labels and live Bókun product statuses are explicitly separated; conflicts are visible. Repeated participant-category rows are summed for display while the raw Bókun fields remain intact.

The changed drafting policy participates in the context hash. Existing saved drafts are retained but become stale and need regeneration or a reviewed manual save before approval. AI quality remains nondeterministic: structured output, conservative decision checks and small regression evaluations are not proof that every reply is factually correct.

Version 1.2.2 distinguishes short, unambiguous acknowledgements from unresolved requests. A pure acknowledgement can retain an AI no-reply recommendation for administrator review; it is never automatically marked handled. If no reply text is appropriate, the saved analysis has non-sendable `review` status rather than a fake customer-facing placeholder. The administrator can mark handled or write and save a separately approved manual reply. An earlier unanswered request survives later thanks and support notices. Added questions, requests, quoted instructions or ambiguous text do not qualify for this acknowledgement exception.

AI revisions also include the operator's current reply and editing request. Editing instructions can change tone, length, or language, but cannot establish that an unverified refund, booking change, or other operational action occurred. See the [OpenAI Structured Outputs guide](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses) for the response-format contract.

## Filtering and context limits

- Viator relay conversations and relevant replies are identified by the expected domain, conversation structure, references, and Gmail message labels.
- Incoming messages require Gmail's authentication results indicating a DMARC pass for the sender domain before they can enter AI context. Failed verification and conflicting references stay visible for review and are excluded from AI input.
- Booking-specific support messages are a separate context stream. Refund requests are not proof that a refund occurred. Generic brand marketing, finance statements, review reminders, and unrelated shared-inbox mail do not trigger drafting.
- Conversations are scoped by business plus booking reference. Original message identifiers deduplicate copies within that scope. The notification sender address can change between messages.
- Attachments are preserved as metadata, including attachment-only operator replies. Attachment contents are not downloaded or analyzed in this version.
- Replies sent from other mailboxes or directly in Viator might be absent. This coverage warning remains visible; validate representative threads before treating history as complete.
- AI drafting rejects conversations over 100 verified messages or 180,000 encoded bytes rather than silently dropping earlier context. Individual displayed message text is capped at 60,000 bytes.
- Support-only threads, unknown references, missing booking matches, and invalid reply headers require manual investigation. Sending uses the latest verified customer relay address and cannot be redirected to an address supplied in customer text.

## Approval and failure behavior

Each saved draft is a separate database record. Approval applies to its exact body and context hash. New messages, operator notes, account mapping changes, or changed Bókun facts invalidate stale context. Concurrent operations use database-backed locks, and one saved draft can be claimed for sending only once.

An interrupted or ambiguous send becomes `sending` or `unknown`. No new reply can bypass it. **Reconcile uncertain send** searches Gmail Sent by the recorded Message-ID, verifies the outgoing copy, and records it without sending again. If no copy is found, the draft remains blocked for manual Gmail investigation; automatic retry could duplicate a customer message.

Mail connection and AI failures are shown in synchronization status. Credentials are never returned to the browser. OAuth state is single-use, user-bound, and expires after ten minutes. Stored credentials are encrypted with AES-256-GCM using the WordPress authentication salt; rotating that salt requires reconnecting accounts or re-entering keys.

## Installation and deployment

Back up the existing plugin and database, install the reviewed build, and verify the new Messages tab while signed in as an administrator. The three message tables are created/upgraded on initialization. Deactivation stops the message polling schedule; retained conversation history is not deleted.

This repository includes a GitHub auto-updater. For a manually installed build that has not been published to its tracked branch, keep its auto-sync disabled until the same feature code is published there, or a subsequent update can replace the manual build. Once centralized, the configured `main` branch is the deployment source: pushes are production changes when auto-sync is enabled. Keep secrets and site-specific settings in WordPress, never Git. The updater downloads immutable commit archives; its installed-commit receipt records only a successfully installed package, not an offered or failed update. Version 1.2.3 also restricts authorization headers to exact HTTPS GitHub hosts and the configured repository.

The Bookings view of the dashboard is intentionally public (operated without a WordPress account), but every staff-only surface inside or beside it — analytics, business results, history, import controls and the import status note, and Messages — requires authenticated trusted staff (`manage_options` or `edit_others_posts`; Messages remains administrator-only). Direct shortcode calls and related legacy mutations/imports enforce that boundary server-side regardless of the public view. Dashboard and booking-data pages receive noindex/nofollow and no-cache protection, so the public booking data is kept out of search indexes and shared caches. Existing single-booking links retain their previous sharing/access behavior: robots exclusion is not authentication, and the public Bookings view exposes stored customer booking details to anyone with the link by design. Previously cached copies and prior search indexing require separate cache removal or search-provider follow-up where applicable. These changes do not establish that the host is free of unrelated injected content.

## Consolidation of the October 22 changes

The reviewed `origin/main` at `296e7ff` introduced a separate IMAP/Anthropic AI Inbox. That engine is superseded by this consolidated implementation. The existing Gmail OAuth connections, encrypted credentials, business mappings, complete eligible thread history, OpenAI requested-only drafting, immutable drafts and exact-message approval remain the canonical support workflow. No Anthropic key or IMAP password is required. Repository regression suites and GitHub Actions cover support, import and updater safeguards; see `tests/README.md`. Development-only tests are excluded from deployment archives.

Useful booking-sync changes are integrated separately. All full booking imports share a database advisory lock, incomplete pages are errors rather than successful partial imports, and a webhook-triggered run can widen the normal date window. WordPress must use a MySQL/MariaDB connection supporting advisory locks for full imports; unsupported locking fails closed. The message-specific live reference lookup is unchanged and does not require a full import.

### Optional booking webhook

The receiver is **disabled by default**, independently of Gmail polling. An administrator can explicitly create a private webhook URL under the main booking Settings page, then register a POST sender with Bókun separately. The plugin does not create that external registration. Setup and rotation require an action-specific WordPress nonce; simply viewing settings or making an anonymous request never creates a credential.

Use `X-Bokun-Webhook-Token` where sender configuration supports it; the private URL query token is the fallback and may appear in infrastructure access logs. The implementation does not guess an undocumented HMAC scheme. Only a token-authenticated `GET` with `ping=1` is read-only health checking; booking events require POST. The payload is a trigger, not a trusted booking update: the importer fetches the authoritative records through configured Bókun API accounts.

Events are coalesced into a durable pending generation. Successful complete imports acknowledge only their own generation; newer events survive and queue another run. Failed, concurrent, interrupted or incomplete imports retain pending work and use bounded retry delays plus a five-minute recovery schedule. Enabled receivers still need a reliable server WP-Cron invocation. The webhook window is at most 730 days in either direction, not all-time coverage. Last-event and import-result evidence is displayed in Settings. Disable or plugin deactivation clears its scheduled work without deleting saved secrets, pending history or message conversations.
