<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
require __DIR__ . '/sync.php';
wp_set_current_user(get_user_by('login', 'messages_admin')->ID);
$conversation = BOKUN_Messages::conversation($sync_id);
$previous = BOKUN_Messages::latest_draft($sync_id);
$before_messages = count(BOKUN_Messages::messages($sync_id));
$before_ai = $GLOBALS['sync_fixture']['ai_count'];
$invalid = action_request('revise', $conversation, array('base_draft_id' => $previous['id'], 'body' => 'Unsaved operator reply.', 'instructions' => ''));
verify_case(is_wp_error($invalid) && $before_ai === $GLOBALS['sync_fixture']['ai_count'], 'AI editing rejects empty instructions without contacting the AI service');
$invalid = action_request('revise', $conversation, array('base_draft_id' => $previous['id'], 'body' => '', 'instructions' => 'Make it shorter.'));
verify_case(is_wp_error($invalid), 'AI editing requires the actual reply text');
$invalid = action_request('revise', $conversation, array('base_draft_id' => $previous['id'], 'body' => 'Reply text.', 'instructions' => str_repeat('x', 2001)));
verify_case(is_wp_error($invalid) && $before_ai === $GLOBALS['sync_fixture']['ai_count'], 'oversized editing instructions are rejected before service calls');
$invalid = action_request('revise', $conversation, array('base_draft_id' => 0, 'body' => 'Old screen reply.', 'instructions' => 'Make it shorter.'));
verify_case(is_wp_error($invalid) && 'draft_changed' === $invalid->get_error_code(), 'AI editing cannot silently use a superseded draft');
$revised = action_request('revise', $conversation, array('base_draft_id' => $previous['id'], 'body' => 'Unsaved operator reply.', 'instructions' => 'Make it shorter and warmer.'));
verify_case(!is_wp_error($revised) && (int) $revised['draft']['id'] > (int) $previous['id'], 'AI editing creates a new immutable draft version');
$request_input = $GLOBALS['sync_fixture']['last_ai_request']['input'];
$revision_context = json_decode($request_input[1]['content'], true);
verify_case('Unsaved operator reply.' === $revision_context['operator_revision']['current_reply'] && 'Make it shorter and warmer.' === $revision_context['operator_revision']['instructions'], 'AI editing receives current unsaved text and the exact operator request');
verify_case(!empty($revision_context['live_booking']['products']) && count($revision_context['history']) >= 3, 'AI editing retains live booking products and conversation history');
$analysis = json_decode($revised['draft']['analysis'], true);
verify_case((int) $analysis['source_draft_id'] === (int) $previous['id'] && $analysis['revision_request'] === 'Make it shorter and warmer.', 'revision audit records the source draft and editing request');
verify_case('draft' === $revised['draft']['status'] && 0 === (int) $revised['draft']['approved_by'] && count(BOKUN_Messages::messages($sync_id)) === $before_messages, 'AI revision stays unapproved and never creates an outgoing message');
$immutable = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . BOKUN_Messages::table('drafts') . ' WHERE id=%d', $previous['id']), ARRAY_A);
verify_case($immutable['body'] === $previous['body'], 'AI editing leaves the previous saved body unchanged');
$denied_send = action_request('send', BOKUN_Messages::conversation($sync_id), array('draft_id' => $revised['draft']['id'], 'approved' => false));
verify_case(is_wp_error($denied_send) && 'approval_required' === $denied_send->get_error_code(), 'an AI-edited draft still requires explicit administrator approval');
$manual = action_request('save', BOKUN_Messages::conversation($sync_id), array('body' => 'Manually edited after AI revision.'));
verify_case(!is_wp_error($manual) && $manual['draft']['body'] === 'Manually edited after AI revision.', 'manual editing remains available after an AI revision');
wp_set_current_user(0);
$guest_revision = rest_do_request(new WP_REST_Request('POST', '/bokun/v1/messages/revise'));
verify_case(403 === $guest_revision->get_status(), 'the new AI-editing REST endpoint denies guests');
wp_set_current_user(get_user_by('login', 'messages_admin')->ID);
$search_reference = (string) random_int(9400000000, 9499999000);
$search_customer = 'Older Target Guest ' . $search_reference;
for ($offset = 0; $offset < 55; $offset++) {
    $inbox_reference = (string) ((int) $search_reference + $offset);
    $fixture_message = sample_message('search-' . $inbox_reference, $inbox_reference, "Booking Reference: BR-$inbox_reference\nGuest wrote:\nTest inbox request.");
    $fixture_message['internalDate'] = (string) ((time() + $offset + 20) * 1000);
    if (0 === $offset) {
        $fixture_message['internalDate'] = '978307200000';
        $fixture_message['payload']['headers'][2]['value'] = 'Conversation with ' . $search_customer . ' about Viator booking BR-' . $inbox_reference;
    }
    BOKUN_Messages::ingest('florence-adventures', BOKUN_Message_Parser::parse($fixture_message, 'amerigovespuccitours@gmail.com'));
}
function inbox_query($parameters) {
    $request = new WP_REST_Request('GET');
    $request->set_query_params($parameters);
    return BOKUN_Messages::dispatch('inbox', $request);
}
$first_page = inbox_query(array('account' => 'florence-adventures', 'state' => 'all'));
verify_case(50 === count($first_page['conversations']) && !in_array($search_reference, array_column($first_page['conversations'], 'reference'), true), 'the older target conversation lies beyond the first inbox page');
$searched = inbox_query(array('account' => 'florence-adventures', 'search' => $search_customer, 'state' => 'all'));
verify_case(1 === $searched['total'] && $search_reference === $searched['conversations'][0]['reference'], 'inbox-wide customer search finds conversations beyond the displayed page');
$target = BOKUN_Messages::conversation($searched['conversations'][0]['id']);
action_request('close', $target);
$attention = inbox_query(array('account' => 'florence-adventures', 'search' => $search_reference, 'state' => 'attention'));
$closed = inbox_query(array('account' => 'florence-adventures', 'search' => $search_reference, 'state' => 'closed'));
verify_case(0 === $attention['total'] && 1 === $closed['total'] && 1 === $closed['counts']['closed'], 'status filters and inbox counts reflect handled conversations');
$other_business = inbox_query(array('account' => 'via-florence', 'search' => $search_reference, 'state' => 'all'));
verify_case(0 === $other_business['total'] && 0 === array_sum($other_business['counts']), 'search and counts stay scoped to the selected business');
$injected = inbox_query(array('search' => "' OR 1=1 --", 'state' => 'all'));
verify_case(0 === $injected['total'], 'inbox search treats SQL-like input as literal text');
echo "Completed $checks combined dashboard, revision, workflow, and synchronization checks.\n";
