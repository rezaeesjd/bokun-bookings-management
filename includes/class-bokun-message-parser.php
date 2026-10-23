<?php

if (!defined('ABSPATH')) {
    exit;
}

class BOKUN_Message_Parser {
    public static function headers($payload) {
        $headers = array();
        foreach ($payload['headers'] ?? array() as $header) {
            $key = strtolower($header['name'] ?? '');
            $headers[$key] = isset($headers[$key]) ? $headers[$key] . '\n' . $header['value'] : ($header['value'] ?? '');
        }
        return $headers;
    }

    public static function address($value) {
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches)) {
            $value = $matches[1];
        }
        $value = strtolower(trim($value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    public static function references($text) {
        preg_match_all('/\bBR[-\s]?(\d{6,14})\b|Booking\s+Reference\s*:\s*(\d{6,14})\b/i', $text, $matches, PREG_SET_ORDER);
        $references = array();
        foreach ($matches as $match) {
            $references[] = $match[1] ?: ($match[2] ?? '');
        }
        return array_values(array_unique(array_filter($references)));
    }

    public static function is_relay($address) {
        return (bool) preg_match('/^[^@]+@expmessaging\.tripadvisor\.com$/i', $address);
    }

    public static function authenticated($headers, $domain) {
        $results = $headers['authentication-results'] ?? '';
        $domain = preg_quote($domain, '/');
        return (bool) preg_match('/(?:^|\\n)mx\.google\.com;.*?dmarc=pass\b[^;]*header\.from=' . $domain . '(?:\s|;|$)/is', $results);
    }

    public static function candidate($message, $mailbox) {
        $headers = self::headers($message['payload'] ?? array());
        $from = self::address($headers['from'] ?? '');
        $subject = $headers['subject'] ?? '';
        $sent = in_array('SENT', $message['labelIds'] ?? array(), true);
        if (preg_match('/payment advice|newsletter|new review|pricing still missing|product release|insurance policy|digital services tax/i', $subject)) {
            return '';
        }
        if ($sent && $from === strtolower($mailbox) && self::is_relay(self::address($headers['to'] ?? ''))) {
            return 'outgoing';
        }
        if (self::is_relay($from) && preg_match('/Conversation with .+ about Viator booking BR-\d+/i', $subject)) {
            return 'customer';
        }
        if (preg_match('/@(?:[a-z0-9-]+\.)?(?:viator|tripadvisor)\.com$/i', $from)
            && (self::references($subject) || preg_match('/amendment request|refund processed|experiences cancellation|booking.*refund|refund.*booking/i', $subject))) {
            return 'support';
        }
        return '';
    }

    public static function parts($part, &$plain, &$html, &$attachments) {
        if (!empty($part['filename'])) {
            $attachments[] = array('name' => $part['filename'], 'type' => $part['mimeType'] ?? '', 'bytes' => (int) ($part['body']['size'] ?? 0));
        } elseif (!empty($part['body']['data'])) {
            $encoded = strtr($part['body']['data'], '-_', '+/');
            $decoded = base64_decode($encoded, true);
            if (false !== $decoded) {
                if ('text/plain' === ($part['mimeType'] ?? '')) {
                    $plain[] = $decoded;
                } elseif ('text/html' === ($part['mimeType'] ?? '')) {
                    $html[] = $decoded;
                }
            }
        }
        foreach ($part['parts'] ?? array() as $child) {
            self::parts($child, $plain, $html, $attachments);
        }
    }

    public static function text($value) {
        $value = preg_replace('/<(?:script|style)\b[^>]*>.*?<\/(?:script|style)>/is', '', $value);
        $value = preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|li)>/i', "\n", $value);
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function parse($message, $mailbox) {
        $kind = self::candidate($message, $mailbox);
        if (!$kind) {
            return null;
        }
        $headers = self::headers($message['payload'] ?? array());
        $plain = $html = $attachments = array();
        self::parts($message['payload'] ?? array(), $plain, $html, $attachments);
        $body = self::text(implode("\n", $plain ?: $html));
        $subject = self::text($headers['subject'] ?? '');
        $references = array_values(array_unique(array_merge(self::references($subject), self::references($body))));
        $from = self::address($headers['from'] ?? '');
        $domain = substr(strrchr($from, '@') ?: '', 1);
        $trusted = 'outgoing' === $kind || self::authenticated($headers, $domain);
        $ambiguous = count($references) !== 1;
        $context = array();
        foreach (array('Product name', 'Product Option', 'Travel date/Pax') as $label) {
            if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/mi', $body, $match)) {
                $context[$label] = trim($match[1]);
            }
        }
        if ('customer' === $kind) {
            $body = preg_split('/\n\s*(?:-{5,}|View conversation in Management Center)/', $body, 2)[0];
            $body = preg_replace('/^.*?\bwrote:\s*/s', '', $body, 1);
        } else {
            $body = preg_split('/(?:^|\n)On .{1,300}wrote:\s*\n|\n-{3,}\s*Original Message\s*-*/i', $body, 2)[0];
        }
        $reply_to = self::address($headers['reply-to'] ?? '') ?: $from;
        $oversized = strlen($body) > 60000;
        $body = function_exists('mb_strcut') ? mb_strcut(trim($body), 0, 60000, 'UTF-8') : substr(trim($body), 0, 60000);
        if (function_exists('iconv')) {
            $body = iconv('UTF-8', 'UTF-8//IGNORE', $body);
        }
        return array(
            'provider_id' => (string) ($message['id'] ?? ''),
            'thread_id' => (string) ($message['threadId'] ?? ''),
            'rfc_id' => preg_replace('/[\r\n]/', '', $headers['message-id'] ?? ''),
            'reference' => $ambiguous ? '' : $references[0],
            'subject' => $subject,
            'body' => $body,
            'kind' => $kind,
            'trusted' => $trusted,
            'reply_to' => self::is_relay($reply_to) ? $reply_to : '',
            'references' => preg_replace('/[\r\n]/', ' ', $headers['references'] ?? ''),
            'sent_at' => gmdate('Y-m-d H:i:s', (int) floor(((float) ($message['internalDate'] ?? 0)) / 1000)),
            'attachments' => $attachments,
            'email_context' => $context,
            'review_reason' => !$trusted ? 'Sender authentication needs review.' : ($ambiguous ? 'Missing or conflicting booking references.' : ($oversized ? 'Message text exceeds the display limit. Review the original email.' : '')),
        );
    }

    public static function raw_reply($mailbox, $recipient, $subject, $body, $parent, $references, $message_id) {
        if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL) || !self::is_relay($recipient)) {
            throw new InvalidArgumentException('A verified mailbox and Viator relay recipient are required.');
        }
        foreach (array($mailbox, $recipient, $subject, $parent, $references, $message_id) as $header) {
            if (preg_match('/[\r\n]/', $header)) {
                throw new InvalidArgumentException('Invalid mail header.');
            }
        }
        if (!preg_match('/^<[^<>\s]+@[^<>\s]+>$/', $parent) || strlen($subject) > 500) {
            throw new InvalidArgumentException('The original reply headers need manual review.');
        }
        $subject = preg_replace('/^Re:\s*/i', '', $subject);
        return implode("\r\n", array(
            'From: ' . $mailbox,
            'To: ' . $recipient,
            'Subject: =?UTF-8?B?' . base64_encode('Re: ' . $subject) . '?=',
            'Message-ID: ' . $message_id,
            'In-Reply-To: ' . $parent,
            'References: ' . wordwrap(trim($references . ' ' . $parent), 76, "\r\n ", false),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($body), 76, "\r\n"),
        ));
    }
}
