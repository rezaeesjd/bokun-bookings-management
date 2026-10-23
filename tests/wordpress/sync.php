<?php
if ('cli' !== PHP_SAPI) {
    exit;
}
require __DIR__ . '/feature.php';
remove_all_filters('pre_http_request');
$sync_reference = (string) random_int(9200000000, 9299999999);
$sync_customer = sample_message('sync-customer-' . $sync_reference, $sync_reference, "Booking Reference: BR-$sync_reference\nTest Guest wrote:\nPlease confirm the itinerary.");
$sync_promotion = sample_message('sync-promotion-' . $sync_reference, $sync_reference, 'Unrelated marketing.');
$sync_promotion['payload']['headers'][2]['value'] = 'Viator Autumn Product Release';
$sync_support = sample_message('sync-support-' . $sync_reference, $sync_reference, "Booking Reference: $sync_reference\nThe booking needs operator review.");
$sync_support['payload']['headers'][0]['value'] = 'Customer Care <customercare@viator.com>';
$sync_support['payload']['headers'][2]['value'] = 'URGENT: Amendment Request for BR-';
$sync_support['payload']['headers'][5]['value'] = 'mx.google.com; dmarc=pass header.from=viator.com';
$GLOBALS['sync_fixture'] = array('customer' => $sync_customer, 'promotion' => $sync_promotion, 'support' => $sync_support, 'metadata_failure' => true, 'full_threads' => array(), 'ai_count' => 0);
add_filter('pre_http_request', function ($override, $args, $url) {
    $fixture = &$GLOBALS['sync_fixture'];
    $code = 200;
    $body = array();
    if (str_contains($url, '/activity.json/')) {
        $body = array('id' => 123, 'title' => 'Synthetic walking tour', 'description' => 'Verified synthetic itinerary.', 'timeZone' => 'Europe/Rome');
    } elseif (str_contains($url, 'api.bokun.io')) {
        $query = json_decode($args['body'], true);
        $body = array('items' => array(array('confirmationCode' => 'AUTO-SYNTHETIC', 'externalBookingReference' => $query['externalBookingReference'], 'status' => 'CONFIRMED', 'productBookings' => array(array('product' => array('id' => 123, 'title' => 'Synthetic walking tour'), 'fields' => array('rateTitle' => 'Standard'))))));
    } elseif (str_contains($url, '/messages?')) {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        if (($query['pageToken'] ?? '') === 'second-page') {
            $body = array('messages' => array(array('id' => $fixture['support']['id'])));
        } else {
            $body = array('messages' => array(array('id' => $fixture['promotion']['id']), array('id' => $fixture['customer']['id'])), 'nextPageToken' => 'second-page');
        }
    } elseif (str_contains($url, '/messages/')) {
        foreach (array('customer', 'promotion', 'support') as $kind) {
            if (str_contains($url, rawurlencode($fixture[$kind]['id']))) {
                $body = $fixture[$kind];
                if ('customer' === $kind && $fixture['metadata_failure']) { $code = 503; }
            }
        }
    } elseif (str_contains($url, '/threads/')) {
        $fixture['full_threads'][] = $fixture['customer']['threadId'];
        $body = array('messages' => array($fixture['customer'], $fixture['support']));
    } elseif (str_contains($url, 'api.openai.com')) {
        $fixture['ai_count']++;
        $fixture['last_ai_request'] = json_decode($args['body'], true);
        $body = array('status' => 'completed', 'output' => array(array('content' => array(array('type' => 'output_text', 'text' => wp_json_encode(array('reply' => 'We will verify the itinerary before confirming it.', 'summary' => 'Itinerary verification required.', 'reply_needed' => true, 'decision_reason' => 'The customer is waiting for an answer.', 'next_step' => 'Check the booked itinerary.', 'facts_used' => array(), 'missing_information' => array('Verified itinerary'))))))));
    }
    return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => $code, 'message' => 'Fixture'), 'cookies' => array());
}, 10, 3);
delete_option('bokun_messages_cursor_florence-adventures');
$failed_sync = BOKUN_Message_Mail::sync('florence-adventures');
verify_case(is_wp_error($failed_sync) && !get_option('bokun_messages_cursor_florence-adventures'), 'failed synchronization does not advance the mailbox cursor');
$GLOBALS['sync_fixture']['metadata_failure'] = false;
$first_page = BOKUN_Message_Mail::sync('florence-adventures');
verify_case(!is_wp_error($first_page) && $first_page['more'] && 'second-page' === get_option('bokun_messages_cursor_florence-adventures')['page'], 'initial backfill preserves pagination for the next run');
verify_case(count($GLOBALS['sync_fixture']['full_threads']) === 1, 'unrelated metadata candidates never trigger full-thread retrieval');
$sync_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . BOKUN_Messages::table('conversations') . ' WHERE reference=%s AND account=%s', $sync_reference, 'florence-adventures'));
verify_case('needs_reply' === BOKUN_Messages::conversation($sync_id)['state'], 'support context does not hide an unanswered customer request');
verify_case(2 === count(BOKUN_Messages::messages($sync_id)), 'customer and booking-specific support messages share the booking context');
$second_page = BOKUN_Message_Mail::sync('florence-adventures');
verify_case(!is_wp_error($second_page) && !$second_page['more'] && !empty(get_option('bokun_messages_cursor_florence-adventures')['completed']), 'finished pagination advances the durable overlap cursor');
verify_case(2 === count(BOKUN_Messages::messages($sync_id)), 'repeated thread retrieval across pages does not duplicate history');
$config = BOKUN_Messages::settings();
$config['auto_draft'] = 1;
update_option('bokun_messages_settings', $config);
BOKUN_Messages::poll();
BOKUN_Messages::poll();
verify_case((bool) BOKUN_Messages::latest_draft($sync_id), 'background processing prepares a draft after backfill completes');
verify_case('AUTO-SYNTHETIC' === BOKUN_Messages::context(BOKUN_Messages::conversation($sync_id))['live_booking']['confirmation'], 'automatic drafting retrieves the booking rather than drafting from email alone');
$draft_before = BOKUN_Messages::latest_draft($sync_id);
$followup = sample_message('sync-followup-' . $sync_reference, $sync_reference, "Booking Reference: BR-$sync_reference\nTest Guest wrote:\nPlease also confirm the language.");
$followup['internalDate'] = (string) ((time() + 10) * 1000);
BOKUN_Messages::ingest('florence-adventures', BOKUN_Message_Parser::parse($followup, 'amerigovespuccitours@gmail.com'));
BOKUN_Messages::poll();
BOKUN_Messages::poll();
verify_case((int) BOKUN_Messages::latest_draft($sync_id)['id'] > (int) $draft_before['id'], 'a new customer message generates a new draft instead of reusing stale text');
wp_set_current_user(get_user_by('login', 'messages_admin')->ID);
$shortcode = new BOKUN_Shortcode();
verify_case(str_contains($shortcode->render_booking_dashboard(), 'data-tab-target="messages"'), 'Messages remains inside the dashboard when no upcoming bookings exist');
$rest_request = new WP_REST_Request('GET', '/bokun/v1/messages/inbox');
$rest_request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$rest_result = rest_do_request($rest_request);
verify_case(200 === $rest_result->get_status() && isset($rest_result->get_data()['conversations'][0]['latest_draft_id']), 'the real WordPress REST inbox route returns drafts and conversation revisions');
wp_set_current_user(0);
$guest_rest = rest_do_request(new WP_REST_Request('GET', '/bokun/v1/messages/inbox'));
verify_case(403 === $guest_rest->get_status(), 'the real REST route denies unauthenticated requests');
wp_set_current_user(get_user_by('login', 'messages_admin')->ID);
$oversized_message = sample_message('unicode-' . $sync_reference, $sync_reference, "Booking Reference: BR-$sync_reference\nGuest wrote:\n" . str_repeat('é€', 14000));
$oversized_parsed = BOKUN_Message_Parser::parse($oversized_message, 'amerigovespuccitours@gmail.com');
verify_case(false !== json_encode($oversized_parsed) && strlen($oversized_parsed['body']) <= 60000 && str_contains($oversized_parsed['review_reason'], 'display limit'), 'long Unicode messages remain valid and are flagged rather than silently used by AI');
$context = BOKUN_Messages::context(BOKUN_Messages::conversation($sync_id));
$context['history'] = array_fill(0, 101, array('text' => 'Test message'));
verify_case(is_wp_error(BOKUN_Message_AI::generate(BOKUN_Messages::conversation($sync_id), $context)), 'oversized conversation histories do not silently lose earlier context');
$config['auto_draft'] = 0;
update_option('bokun_messages_settings', $config);
echo "Completed $checks combined workflow and synchronization checks.\n";
