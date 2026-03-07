<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function imap_mailbox_string(array $settings): string
{
    $host = $settings['imap_host'] ?: 'imap.qq.com';
    $port = (int) ($settings['imap_port'] ?: 993);
    $encryption = trim((string) ($settings['imap_encryption'] ?: 'ssl'));
    $mailbox = trim((string) ($settings['imap_mailbox'] ?: 'INBOX'));
    $flags = $encryption !== '' ? '/imap/' . $encryption : '/imap';
    return sprintf('{%s:%d%s}%s', $host, $port, $flags, $mailbox);
}

function fetch_filtered_messages(string $targetEmail): array
{
    $settings = get_settings();
    $imapEmail = trim((string) $settings['imap_email']);
    $imapPassword = (string) $settings['imap_password'];

    if ($imapEmail === '' || $imapPassword === '') {
        return ['messages' => [], 'error' => 'IMAP 邮箱未配置'];
    }
    if (!extension_loaded('imap') || !function_exists('imap_open')) {
        return ['messages' => [], 'error' => 'PHP IMAP 扩展未启用'];
    }

    $targetEmail = strtolower(trim($targetEmail));
    imap_timeout(IMAP_OPENTIMEOUT, 10);
    imap_timeout(IMAP_READTIMEOUT, 10);
    imap_timeout(IMAP_WRITETIMEOUT, 10);

    // Clear previous IMAP runtime errors before opening a new connection.
    imap_errors();
    imap_alerts();
    $mailbox = @imap_open(imap_mailbox_string($settings), $imapEmail, $imapPassword, 0, 1);
    if (!$mailbox) {
        $error = imap_last_error() ?: '未知错误';
        return ['messages' => [], 'error' => 'IMAP 连接失败：' . $error];
    }

    // Clear warnings generated during open stage before next IMAP calls.
    imap_errors();
    imap_alerts();

    $recentHours = max(1, (int) ($settings['recent_hours'] ?? 24));
    $maxResults = max(1, min(30, (int) ($settings['max_results'] ?? 10)));
    $sinceDate = date('d-M-Y', strtotime('-' . $recentHours . ' hours'));
    $uids = @imap_search($mailbox, 'SINCE "' . $sinceDate . '" TO "' . addcslashes($targetEmail, '"') . '"', SE_UID);
    $searchErrors = imap_errors() ?: [];
    if ($uids === false && $searchErrors) {
        @imap_close($mailbox);
        $error = (string) end($searchErrors);
        return ['messages' => [], 'error' => 'IMAP 查询失败：' . $error];
    }
    if (!$uids || !is_array($uids)) {
        @imap_close($mailbox);
        return ['messages' => []];
    }

    rsort($uids);
    $uids = array_slice(array_unique($uids), 0, 50);
    $overviews = @imap_fetch_overview($mailbox, implode(',', $uids), FT_UID) ?: [];
    $overviewMap = [];
    foreach ($overviews as $overview) {
        $overviewMap[$overview->uid] = $overview;
    }

    $senderDomains = to_lines((string) ($settings['sender_domains'] ?? ''));
    $includes = to_lines((string) ($settings['subject_includes'] ?? ''));
    $excludes = to_lines((string) ($settings['subject_excludes'] ?? ''));
    $threshold = time() - ($recentHours * 3600);
    $messages = [];

    foreach ($uids as $uid) {
        if (!isset($overviewMap[$uid])) {
            continue;
        }

        $overview = $overviewMap[$uid];
        $subject = isset($overview->subject) ? imap_utf8((string) $overview->subject) : '(无标题)';
        $toAddresses = extract_email_addresses(isset($overview->to) ? imap_utf8((string) $overview->to) : '');
        if (!in_array($targetEmail, $toAddresses, true)) {
            continue;
        }

        $fromAddresses = extract_email_addresses(isset($overview->from) ? imap_utf8((string) $overview->from) : '');
        if ($senderDomains && !sender_matches($fromAddresses, $senderDomains)) {
            continue;
        }

        $subjectLc = mb_strtolower($subject, 'UTF-8');
        if ($includes && !subject_matches_any($subjectLc, $includes)) {
            continue;
        }
        if (!$includes && $excludes && subject_matches_any($subjectLc, $excludes)) {
            continue;
        }

        $mailTime = strtotime((string) ($overview->date ?? ''));
        if ($mailTime !== false && $mailTime < $threshold) {
            continue;
        }

        $date = $mailTime !== false ? date('Y-m-d H:i:s', $mailTime) : (string) ($overview->date ?? '');
        $messages[] = [
            'uid' => (string) $uid,
            'subject' => $subject,
            'from' => $fromAddresses ? implode('; ', $fromAddresses) : '(未知)',
            'date' => $date,
            'body_html' => fetch_body_html($mailbox, (int) $uid),
        ];

        if (count($messages) >= $maxResults) {
            break;
        }
    }

    @imap_close($mailbox);
    imap_errors();
    imap_alerts();
    return ['messages' => $messages];
}

function extract_email_addresses(string $value): array
{
    if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $value, $matches)) {
        return [];
    }
    $emails = [];
    foreach ($matches[0] as $match) {
        $email = strtolower(trim($match));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }
    return array_keys($emails);
}

function sender_matches(array $senders, array $domains): bool
{
    foreach ($senders as $sender) {
        foreach ($domains as $domain) {
            $domain = ltrim($domain, '@');
            if ($domain !== '' && str_ends_with($sender, '@' . $domain)) {
                return true;
            }
        }
    }
    return false;
}

function subject_matches_any(string $subject, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_stripos($subject, $needle, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

function fetch_body_html($mailbox, int $uid): string
{
    $structure = @imap_fetchstructure($mailbox, $uid, FT_UID);
    $html = '';
    $text = '';

    if ($structure && !empty($structure->parts)) {
        foreach ($structure->parts as $index => $part) {
            extract_body_part($mailbox, $uid, $part, (string) ($index + 1), $html, $text);
            if ($html !== '') {
                break;
            }
        }
    } else {
        $body = @imap_body($mailbox, $uid, FT_UID | FT_PEEK);
        $body = decode_imap_part($body, $structure->encoding ?? 0);
        if (strtoupper((string) ($structure->subtype ?? '')) === 'HTML') {
            $html = $body;
        } else {
            $text = $body;
        }
    }

    if ($html === '') {
        $safe = nl2br(preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', h($text)) ?: h($text));
        $html = $safe;
    }

    return $html;
}

function extract_body_part($mailbox, int $uid, object $part, string $partNumber, string &$html, string &$text): void
{
    if (($part->type ?? null) === TYPEMULTIPART && !empty($part->parts)) {
        foreach ($part->parts as $index => $subPart) {
            extract_body_part($mailbox, $uid, $subPart, $partNumber . '.' . ($index + 1), $html, $text);
            if ($html !== '') {
                return;
            }
        }
        return;
    }

    $body = @imap_fetchbody($mailbox, $uid, $partNumber, FT_UID | FT_PEEK);
    $body = decode_imap_part($body, $part->encoding ?? 0);
    $subtype = strtoupper((string) ($part->subtype ?? ''));

    if ($subtype === 'HTML') {
        $html .= $body;
    } elseif ($subtype === 'PLAIN' && $html === '') {
        $text .= $body;
    }
}

function decode_imap_part(string $data, int $encoding): string
{
    return match ($encoding) {
        ENCBASE64 => (string) base64_decode($data, true),
        ENCQUOTEDPRINTABLE => quoted_printable_decode($data),
        default => $data,
    };
}
