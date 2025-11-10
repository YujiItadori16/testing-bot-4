<?php
// ======================
// Telegram PHP Bot — Channel Gate + Referrals (no extra commands)
// Works on Render Docker (webhook)
// ======================

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

$BOT_TOKEN      = getenv('BOT_TOKEN') ?: 'CHANGE_ME';
$API            = "https://api.telegram.org/bot{$BOT_TOKEN}";
$ADMIN_IDS      = array_map('trim', explode(',', getenv('ADMIN_ID') ?: '')); // not used now
$CH1            = getenv('CH1') ?: '@bigbumpersaleoffers';
$CH1_LINK       = getenv('CH1_LINK') ?: 'https://t.me/bigbumpersaleoffers';
$CH2            = getenv('CH2') ?: '@backupchannelbum';
$CH2_LINK       = getenv('CH2_LINK') ?: 'https://t.me/backupchannelbum';
$CONTACT_LINK   = getenv('CONTACT_LINK') ?: 'https://t.me/rk_production_house';
$WEBHOOK_SECRET = getenv('WEBHOOK_SECRET') ?: 'change-this-secret';

$DATA_FILE = __DIR__ . '/users.json';

// --------- Utilities ----------
function tg($method, $params = []) {
    global $API;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $API.'/'.$method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    if ($res === false) { return ['ok'=>false,'curl_error'=>curl_error($ch)]; }
    curl_close($ch);
    $decoded = json_decode($res, true);
    if (!is_array($decoded)) { return ['ok'=>false,'decode_error'=>'json']; }
    return $decoded;
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => true,
    ];
    if ($reply_markup) $params['reply_markup'] = json_encode($reply_markup);
    return tg('sendMessage', $params);
}

function editMessageText($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => true,
    ];
    if ($reply_markup) $params['reply_markup'] = json_encode($reply_markup);
    return tg('editMessageText', $params);
}

function answerCallback($id, $text = '', $alert = false) {
    return tg('answerCallbackQuery', [
        'callback_query_id' => $id,
        'text' => $text,
        'show_alert' => $alert ? 'true' : 'false'
    ]);
}

function getMember($channel, $user_id) {
    return tg('getChatMember', [
        'chat_id' => $channel,
        'user_id' => $user_id
    ]);
}

function loadData($file) {
    if (!file_exists($file)) {
        @file_put_contents($file, json_encode(['users'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        @chmod($file, 0664);
    }
    $fp = fopen($file, 'c+');
    if (!$fp) return [['users'=>[]], null];
    flock($fp, LOCK_EX);
    $size = filesize($file);
    $raw = $size > 0 ? fread($fp, $size) : '';
    $data = json_decode($raw ?: '{"users":{}}', true);
    if (!is_array($data)) $data = ['users'=>[]];
    return [$data, $fp];
}

function saveData($fp, $data) {
    if (!$fp) return;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function inviteLinkFor($bot_username, $user_id) {
    return "https://t.me/{$bot_username}?start={$user_id}";
}

function ensureUser(&$data, $user_id) {
    if (!isset($data['users'][$user_id])) {
        $data['users'][$user_id] = [
            'invited_by' => null,
            'invitees'   => [],   // unique IDs who completed gate
            'joined_ok'  => false
        ];
    }
}

function countUniqueInvites($data, $user_id) {
    if (!isset($data['users'][$user_id])) return 0;
    return count(array_unique($data['users'][$user_id]['invitees']));
}

function hasMetThreshold($data, $user_id, $threshold = 5) {
    return countUniqueInvites($data, $user_id) >= $threshold;
}

function gateKeyboard() {
    global $CH1_LINK, $CH2_LINK;
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Channel 1', 'url' => $CH1_LINK],
                ['text' => 'Channel 2', 'url' => $CH2_LINK]
            ],
            [
                ['text' => 'Try Again ✅', 'callback_data' => 'retry_gate']
            ]
        ]
    ];
}

function inviteKeyboard($bot_username, $user_id) {
    $inviteLink = inviteLinkFor($bot_username, $user_id);
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Invite', 'url' => $inviteLink]
            ],
            [
                // This sends them the exact promo text with their ID (and a Forward inline button)
                ['text' => 'Forward', 'callback_data' => 'send_share']
            ],
            [
                ['text' => 'Try Again ✅', 'callback_data' => 'retry_gate']
            ]
        ]
    ];
}

function forwardKeyboard($shareText) {
    // Inline-mode sharing (user taps this and picks a chat)
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Forward', 'switch_inline_query' => $shareText]
            ]
        ]
    ];
}

function successKeyboard() {
    global $CONTACT_LINK;
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Contact the Admin for premium', 'url' => $CONTACT_LINK]
            ]
        ]
    ];
}

function checkBothJoined($user_id) {
    global $CH1, $CH2;
    $m1 = getMember($CH1, $user_id);
    $m2 = getMember($CH2, $user_id);

    $ok1 = (isset($m1['ok']) && $m1['ok'] && in_array($m1['result']['status'] ?? '', ['member','administrator','creator','restricted'], true));
    $ok2 = (isset($m2['ok']) && $m2['ok'] && in_array($m2['result']['status'] ?? '', ['member','administrator','creator','restricted'], true));

    return $ok1 && $ok2;
}

function botUsernameSafe() {
    $me = tg('getMe');
    if (isset($me['ok']) && $me['ok'] && isset($me['result']['username'])) {
        return $me['result']['username'];
    }
    return 'your_bot';
}

// -------- Webhook helper routes --------
if (isset($_GET['health'])) { http_response_code(200); exit('OK'); }

// setWebhook/deleteWebhook guards
if (isset($_GET['setWebhook']) || isset($_GET['deleteWebhook'])) {
    $provided = $_GET['secret'] ?? '';
    if ($provided !== $WEBHOOK_SECRET) { http_response_code(403); exit('Forbidden'); }
    if (isset($_GET['setWebhook'])) {
        $url = $_GET['url'] ?? '';
        if (!$url) { http_response_code(400); exit('Missing url'); }
        $res = tg('setWebhook', ['url' => $url]);
        header('Content-Type: application/json'); echo json_encode($res); exit;
    } else {
        $res = tg('deleteWebhook');
        header('Content-Type: application/json'); echo json_encode($res); exit;
    }
}

// -------- Handle updates --------
$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(200); echo 'NO-UPDATE'; exit; }

$update = json_decode($raw, true);
if (!is_array($update)) { http_response_code(200); echo 'BAD-JSON'; exit; }

list($data, $fp) = loadData($DATA_FILE);

$bot_username = botUsernameSafe();

$threshold = 5;

// ----- Messages -----
if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'] ?? null;
    $from = $msg['from'] ?? [];
    $user_id = $from['id'] ?? null;
    $text = trim($msg['text'] ?? '');

    if (!$chat_id || !$user_id) { saveData($fp, $data); exit; }

    ensureUser($data, $user_id);

    // /start with optional ref id
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', $text, 2);
        $ref_payload = isset($parts[1]) ? trim($parts[1]) : null;

        if ($ref_payload && is_numeric($ref_payload) && $ref_payload != $user_id) {
            if (!$data['users'][$user_id]['invited_by']) {
                $data['users'][$user_id]['invited_by'] = (int)$ref_payload;
            }
        }

        $gateText = "First join both channels to move to the next step.";
        sendMessage($chat_id, $gateText, gateKeyboard());

    } else {
        // keep it minimal
        sendMessage($chat_id, "Use /start to begin.");
    }
}

// ----- Callback buttons -----
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $cid = $cq['id'];
    $from = $cq['from'] ?? [];
    $user_id = $from['id'] ?? null;
    $message = $cq['message'] ?? [];
    $chat_id = $message['chat']['id'] ?? null;
    $mid = $message['message_id'] ?? null;
    $data_cb = $cq['data'] ?? '';

    if (!$chat_id || !$user_id || !$mid) { answerCallback($cid); saveData($fp, $data); exit; }

    ensureUser($data, $user_id);

    if ($data_cb === 'retry_gate') {
        $joined = false;
        try { $joined = checkBothJoined($user_id); } catch (Throwable $e) { $joined = false; }

        if (!$joined) {
            answerCallback($cid, "Not yet joined both channels. Please join and try again.");
            editMessageText($chat_id, $mid, "First join both channels to move to the next step.", gateKeyboard());
        } else {
            $data['users'][$user_id]['joined_ok'] = true;

            // credit referrer once
            $ref = $data['users'][$user_id]['invited_by'] ?? null;
            if ($ref && $ref != $user_id) {
                ensureUser($data, $ref);
                if (!in_array($user_id, $data['users'][$ref]['invitees'], true)) {
                    $data['users'][$ref]['invitees'][] = $user_id;
                }
            }

            $text = "To get YouTube premium of 1 month for free. First invite 5 people.";
            $kb = inviteKeyboard($GLOBALS['bot_username'] ?? $bot_username, $user_id);
            editMessageText($chat_id, $mid, $text, $kb);
            answerCallback($cid, "Great! Gate passed ✅");
        }

    } elseif ($data_cb === 'send_share') {
        $share = "We are giving YouTube Premium to everyone for 1 month so come and grab the offer.\n\nYour inviter ID: <b>{$user_id}</b>";
        sendMessage($chat_id, $share, forwardKeyboard($share));
        answerCallback($cid, "Share text ready. Tap Forward, pick a chat, and send!");

    }
}

// ----- Success check -----
if (isset($update['message'])) {
    $from = $update['message']['from'] ?? [];
    $chat_id = $update['message']['chat']['id'] ?? null;
    $user_id = $from['id'] ?? null;
    if ($chat_id && $user_id) {
        ensureUser($data, $user_id);
        if ($data['users'][$user_id]['joined_ok'] && hasMetThreshold($data, $user_id, $threshold)) {
            sendMessage($chat_id, "✅ You’ve reached 5 invites! Contact the Admin for premium.", successKeyboard());
        }
    }
}

saveData($fp, $data);
