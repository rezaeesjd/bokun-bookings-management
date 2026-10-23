<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
require __DIR__ . '/bootstrap.php';
$checks = 0;
function verify_case($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $checks++;
    echo 'PASS ' . $label . "\n";
}
function sample_message($id, $reference, $body, $trusted = true, $sent = false, $account = 'florence-adventures') {
    $mailbox = BOKUN_Messages::accounts()[$account]['email'];
    $headers = array(
        array('name' => 'From', 'value' => $sent ? $mailbox : 'Viator <NOT-' . $id . '@expmessaging.tripadvisor.com>'),
        array('name' => 'To', 'value' => $sent ? 'NOT-parent@expmessaging.tripadvisor.com' : $mailbox),
        array('name' => 'Subject', 'value' => 'Conversation with Test Guest about Viator booking BR-' . $reference),
        array('name' => 'Message-ID', 'value' => '<test-' . $id . '@viator.com>'),
        array('name' => 'References', 'value' => '<CNV-test-' . $reference . '@viator.com>'),
        array('name' => 'Authentication-Results', 'value' => 'mx.google.com; dkim=pass header.i=@expmessaging.tripadvisor.com; dmarc=' . ($trusted ? 'pass' : 'fail') . ' header.from=expmessaging.tripadvisor.com'),
    );
    return array('id' => $id, 'threadId' => 'thread-' . $reference, 'labelIds' => array($sent ? 'SENT' : 'INBOX'), 'internalDate' => (string) (time() * 1000), 'payload' => array('mimeType' => 'text/plain', 'headers' => $headers, 'body' => array('data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '='))));
}
function action_request($action, $conversation, $extra = array()) {
    $request = new WP_REST_Request('POST');
    $request->set_body_params(array_merge(array('id' => $conversation['id'], 'context_hash' => BOKUN_Messages::context($conversation)['hash']), $extra));
    return BOKUN_Messages::dispatch($action, $request);
}
$reference = (string) random_int(9100000000, 9199999999);
$customer = sample_message('customer-' . $reference, $reference, "Booking Reference: BR-$reference\nTest Guest wrote:\nWhere do we meet?\n-------------------------------------------\nBooking details\nProduct name: Synthetic walking tour\nTravel date/Pax: October 30 - 2 Adults");
$parsed = BOKUN_Message_Parser::parse($customer, 'amerigovespuccitours@gmail.com');
verify_case($parsed['trusted'] && $parsed['reference'] === $reference && $parsed['body'] === 'Where do we meet?', 'parse authenticated customer message and remove notification boilerplate');
$spoofed = $customer;
$spoofed['payload']['headers'][5]['value'] = 'mx.google.com; dmarc=fail header.from=expmessaging.tripadvisor.com';
verify_case(!BOKUN_Message_Parser::parse($spoofed, 'amerigovespuccitours@gmail.com')['trusted'], 'sender authentication failure cannot become trusted context');
$promotion = $customer;
$promotion['payload']['headers'][2]['value'] = 'Your new review';
verify_case(null === BOKUN_Message_Parser::parse($promotion, 'amerigovespuccitours@gmail.com'), 'irrelevant brand mail is excluded');
$conflict = $customer;
$conflict['payload']['body']['data'] = base64_encode('Booking Reference: BR-9999999999');
verify_case('' === BOKUN_Message_Parser::parse($conflict, 'amerigovespuccitours@gmail.com')['reference'], 'conflicting subject/body references require review');
$attachment = sample_message('attachment-' . $reference, $reference, "On Friday Viator wrote:\nquoted question", true, true);
$attachment['payload']['parts'] = array(array('filename' => 'recipes.pdf', 'mimeType' => 'application/pdf', 'body' => array('size' => 500)));
$attachment_parsed = BOKUN_Message_Parser::parse($attachment, 'amerigovespuccitours@gmail.com');
verify_case('outgoing' === $attachment_parsed['kind'] && '' === $attachment_parsed['body'] && 1 === count($attachment_parsed['attachments']), 'attachment-only operator replies remain conversation events');
try {
    BOKUN_Message_Parser::raw_reply('amerigovespuccitours@gmail.com', 'attacker@example.test', 'Subject', 'Body', '<parent@viator.com>', '', '<own@example.test>');
    verify_case(false, 'reject arbitrary recipient');
} catch (InvalidArgumentException $error) {
    verify_case(true, 'reject arbitrary recipient');
}
try {
    BOKUN_Message_Parser::raw_reply('amerigovespuccitours@gmail.com', 'NOT-test@expmessaging.tripadvisor.com', "Subject\r\nBcc: injected@example.test", 'Body', '<parent@viator.com>', '', '<own@example.test>');
    verify_case(false, 'reject injected mail headers');
} catch (InvalidArgumentException $error) {
    verify_case(true, 'reject injected mail headers');
}
$request = new WP_REST_Request('GET');
wp_set_current_user(0);
verify_case(is_wp_error(BOKUN_Messages::permission($request)), 'guests cannot use message endpoints');
$admin = get_user_by('login', 'messages_admin');
wp_set_current_user($admin->ID);
verify_case(is_wp_error(BOKUN_Messages::permission($request)), 'administrators still require a REST nonce');
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
verify_case(true === BOKUN_Messages::permission($request), 'authenticated administrator with nonce is authorized');
$subscriber = wp_insert_user(array('user_login' => 'subscriber_' . $reference, 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
wp_set_current_user($subscriber);
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
verify_case(is_wp_error(BOKUN_Messages::permission($request)), 'ordinary logged-in users cannot access customer messages');
wp_set_current_user($admin->ID);
verify_case(true === BOKUN_Messages::set_secret('test_cipher', 'synthetic-test-secret'), 'credential encryption succeeds');
verify_case('synthetic-test-secret' === BOKUN_Messages::secret('test_cipher') && !str_contains(get_option('bokun_messages_secret_test_cipher'), 'synthetic-test-secret'), 'stored credential is encrypted and round-trips');
verify_case(1 === BOKUN_Messages::ingest('florence-adventures', $parsed), 'new message persists');
verify_case(0 === BOKUN_Messages::ingest('florence-adventures', $parsed), 'replayed messages are deduplicated');
global $wpdb;
$id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BOKUN_Messages::table('conversations') . ' WHERE account=%s AND reference=%s', 'florence-adventures', $reference));
$conversation = BOKUN_Messages::conversation($id);
verify_case(count(BOKUN_Messages::messages($id)) === 1, 'deduplication preserves one history entry');
verify_case(1 === BOKUN_Messages::ingest('via-florence', $parsed), 'same reference in another business remains separate');
verify_case((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . BOKUN_Messages::table('conversations') . ' WHERE reference=%s', $reference)) === 2, 'mailbox isolation uses business plus reference');
update_option('bokun_api_credentials', array(array('api_key' => 'test-florence', 'secret_key' => 'synthetic'), array('api_key' => 'test-via', 'secret_key' => 'synthetic')));
update_option('bokun_messages_settings', array('florence-adventures_context' => 'api_1', 'via-florence_context' => 'api_2', 'model' => 'gpt-4.1-mini', 'auto_draft' => 0));
$post_id = wp_insert_post(array('post_type' => 'bokun_booking', 'post_status' => 'draft', 'post_title' => 'Synthetic past booking'));
update_post_meta($post_id, '_external_booking_reference', $reference);
update_post_meta($post_id, '_bokun_import_context', 'api_1');
update_post_meta($post_id, '_product_title', 'Synthetic walking tour');
verify_case(BOKUN_Messages::match_booking($conversation) === array($post_id), 'historical draft bookings are matched outside the dashboard window');
$other = $conversation;
$other['account'] = 'via-florence';
verify_case(array() === BOKUN_Messages::match_booking($other), 'another business cannot inherit a matching reference');
$GLOBALS['test_message_state'] = array('reference' => $reference, 'customer' => $customer, 'send_count' => 0, 'uncertain' => false, 'reconciled' => false, 'new_message' => false, 'booking_changed' => false, 'ai_count' => 0);
add_filter('pre_http_request', function ($override, $args, $url) {
    $state = &$GLOBALS['test_message_state'];
    $code = 200;
    $body = array();
    if (str_contains($url, '/activity.json/')) {
        $body = array('id' => 123, 'title' => 'Synthetic walking tour', 'description' => '<p>Historic center walk.</p>', 'included' => 'Guide', 'excluded' => 'Transport', 'timeZone' => 'Europe/Rome', 'rates' => array(array('id' => 10, 'title' => 'Standard')));
    } elseif (str_contains($url, 'api.bokun.io')) {
        $state['booking_count'] = ($state['booking_count'] ?? 0) + 1;
        if (!empty($state['booking_failure'])) { $code = 503; }
        $query = json_decode($args['body'], true);
        if ($query['externalBookingReference'] !== $state['reference']) { throw new RuntimeException('Wrong booking query'); }
        $body = array('items' => array(array('confirmationCode' => 'VIA-SYNTHETIC', 'externalBookingReference' => $state['reference'], 'status' => $state['booking_changed'] ? 'CANCELLED' : 'CONFIRMED', 'productBookings' => array(array('product' => array('id' => 123, 'title' => 'Synthetic walking tour'), 'vendor' => array('title' => 'Florence Adventures'), 'status' => 'CONFIRMED', 'fields' => array('rateTitle' => 'Standard'), 'startDateTime' => '2026-10-30T09:00:00Z', 'notes' => array())))));
    } elseif (str_contains($url, '/threads/')) {
        $body = array('messages' => array($state['customer']));
        if ($state['new_message']) { $body['messages'][] = array('id' => 'new-unseen-message'); }
    } elseif (str_contains($url, '/messages/send')) {
        $state['send_count']++;
        $raw = json_decode($args['body'], true);
        $decoded = base64_decode(strtr($raw['raw'], '-_', '+/'));
        if (!str_contains($decoded, 'In-Reply-To: <test-customer-') || !str_contains($decoded, 'From: amerigovespuccitours@gmail.com')) { throw new RuntimeException('Threading or sender missing'); }
        $code = $state['uncertain'] ? 500 : 200;
        $body = array('id' => 'sent-' . $state['send_count'], 'threadId' => $state['customer']['threadId']);
    } elseif (str_contains($url, '/messages/recovered-sent-id')) {
        $body = $state['recovered_message'];
    } elseif (str_contains($url, '/messages?')) {
        $body = array('messages' => $state['reconciled'] ? array(array('id' => 'recovered-sent-id')) : array());
    } elseif (str_contains($url, 'api.openai.com')) {
        $state['ai_count']++;
        $body = array('status' => 'completed', 'output' => array(array('content' => array(array('type' => 'output_text', 'text' => wp_json_encode(array('reply' => 'Thank you. We will verify the meeting instructions before confirming them.', 'summary' => 'Meeting instructions need verification.', 'reply_needed' => true, 'decision_reason' => 'The customer asked an unanswered question.', 'next_step' => 'Verify the meeting point before approving.', 'facts_used' => array('history message ' . $state['customer']['id']), 'missing_information' => array('Verified meeting point'))))))));
    }
    return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => $code, 'message' => 'Fixture'), 'cookies' => array());
}, 10, 3);
BOKUN_Messages::set_secret('tokens_florence-adventures', wp_json_encode(array('access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_at' => time() + 10000)));
BOKUN_Messages::set_secret('openai_key', 'synthetic-not-a-real-key');
$GLOBALS['test_message_state']['booking_failure'] = true;
$missing_booking = action_request('draft', $conversation);
verify_case(is_wp_error($missing_booking) && 0 === $GLOBALS['test_message_state']['ai_count'], 'unavailable live booking blocks AI generation before any customer data is submitted');
$GLOBALS['test_message_state']['booking_failure'] = false;
verify_case(true === BOKUN_Messages::refresh_booking($conversation), 'historical Bókun lookup uses the mapped credential without date window');
$conversation = BOKUN_Messages::conversation($id);
$context = BOKUN_Messages::context($conversation);
verify_case($context['live_booking']['confirmation'] === 'VIA-SYNTHETIC' && $context['reply_to'] === $parsed['reply_to'], 'booking context and verified reply recipient are assembled');
verify_case('Historic center walk.' === $context['live_booking']['products'][0]['catalog']['description'], 'product context includes sanitized Bókun product details and selected-option references');
$booking_checks = $GLOBALS['test_message_state']['booking_count'];
$draft_result = action_request('draft', $conversation);
verify_case($GLOBALS['test_message_state']['booking_count'] === $booking_checks + 1, 'manual AI generation refreshes Bókun automatically even when a snapshot exists');
verify_case(!is_wp_error($draft_result) && !empty($draft_result['draft']['id']) && $GLOBALS['test_message_state']['send_count'] === 0, 'AI generation saves an editable draft without sending');
$draft = BOKUN_Messages::latest_draft($id);
$not_approved = action_request('send', $conversation, array('draft_id' => $draft['id'], 'approved' => false));
verify_case(is_wp_error($not_approved) && $GLOBALS['test_message_state']['send_count'] === 0, 'sending is blocked without explicit approval');
$GLOBALS['test_message_state']['new_message'] = true;
$new_mail = action_request('send', $conversation, array('draft_id' => $draft['id'], 'approved' => true));
verify_case(is_wp_error($new_mail) && $GLOBALS['test_message_state']['send_count'] === 0, 'a new Gmail message invalidates sending');
$GLOBALS['test_message_state']['new_message'] = false;
$GLOBALS['test_message_state']['booking_changed'] = true;
$changed_booking = action_request('send', $conversation, array('draft_id' => $draft['id'], 'approved' => true));
verify_case(is_wp_error($changed_booking) && $GLOBALS['test_message_state']['send_count'] === 0, 'changed live booking facts invalidate the saved draft');
$GLOBALS['test_message_state']['booking_changed'] = false;
BOKUN_Messages::refresh_booking(BOKUN_Messages::conversation($id));
$conversation = BOKUN_Messages::conversation($id);
$saved = action_request('save', $conversation, array('body' => 'Administrator reviewed reply.'));
verify_case(!is_wp_error($saved) && $saved['draft']['body'] === 'Administrator reviewed reply.', 'administrator can edit and save the reply');
$draft = BOKUN_Messages::latest_draft($id);
$sent = action_request('send', $conversation, array('draft_id' => $draft['id'], 'approved' => true));
verify_case(!is_wp_error($sent) && !empty($sent['sent']) && $GLOBALS['test_message_state']['send_count'] === 1, 'approved reply uses the original Gmail thread and mapped sender');
verify_case('sent' === BOKUN_Messages::latest_draft($id)['status'] && (int) BOKUN_Messages::latest_draft($id)['approved_by'] === (int) $admin->ID, 'approval identity and immutable sent draft are recorded');
$repeat = action_request('send', BOKUN_Messages::conversation($id), array('draft_id' => $draft['id'], 'approved' => true));
verify_case(is_wp_error($repeat) && $GLOBALS['test_message_state']['send_count'] === 1, 'replayed approval cannot send the same draft twice');
$conversation = BOKUN_Messages::conversation($id);
$saved = action_request('save', $conversation, array('body' => 'Second reviewed test reply.'));
$GLOBALS['test_message_state']['uncertain'] = true;
$unknown = action_request('send', $conversation, array('draft_id' => $saved['draft']['id'], 'approved' => true));
verify_case(is_wp_error($unknown) && 'unknown' === BOKUN_Messages::latest_draft($id)['status'], 'ambiguous transport failures retain an uncertain send record');
verify_case(is_wp_error(action_request('save', BOKUN_Messages::conversation($id), array('body' => 'Do not bypass uncertain sends.'))), 'saving another draft cannot bypass uncertain-send protection');
verify_case(is_wp_error(BOKUN_Messages::reconcile(BOKUN_Messages::conversation($id))), 'no Sent copy leaves the uncertain send blocked');
$GLOBALS['test_message_state']['reconciled'] = true;
$pending = BOKUN_Messages::pending_send($id);
$recovered_message = sample_message('recovered-sent-id', $reference, $pending['body'], true, true);
$recovered_message['payload']['headers'][3]['value'] = $pending['send_id'];
$GLOBALS['test_message_state']['recovered_message'] = $recovered_message;
verify_case(!is_wp_error(BOKUN_Messages::reconcile(BOKUN_Messages::conversation($id))) && 'sent' === BOKUN_Messages::latest_draft($id)['status'], 'reconciliation recognizes a Sent copy without sending again');
wp_set_current_user(0);
verify_case(!str_contains(BOKUN_Messages::render(), 'data-bokun-messages') && str_contains(BOKUN_Messages::render(), 'Sign in'), 'public dashboard renders only a sign-in notice for messages');
wp_set_current_user($admin->ID);
verify_case(str_contains(BOKUN_Messages::render(), 'data-bokun-messages'), 'administrator sees the actual dashboard feature');
echo "Completed $checks safety and workflow checks.\n";
