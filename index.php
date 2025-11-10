<?php
// ======================
// Telegram PHP Bot — Channel Gate + Referrals (no chat commands)
// Works on Render.com Docker Web Service (webhook-based)
// ======================

/*
Env you can set in Render:
- BOT_TOKEN
- ADMIN_ID  (kept but unused now; safe to leave)
- CH1, CH1_LINK
- CH2, CH2_LINK
- CONTACT_LINK
- WEBHOOK_SECRET
*/

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

$BOT_TOKEN     = getenv('BOT_TOKEN') ?: 'CHANGE_ME';
$API           = "https://api.telegram.org/bot{$BOT_TOKEN}";
$CH1           = getenv('CH1') ?: '@bigbumpersaleoffers';
$CH1_LINK      = getenv('CH1_LINK') ?: 'https://t.me/bigbumpersaleoffers';
$CH2           = getenv('CH2') ?: '@backupchannelbum';
$CH2_LINK      = getenv('CH2_LINK') ?: 'https://t.me/backupchannelbum';
$CONTACT_LINK  = getenv('CONTACT_LINK') ?: 'https://t.me/rk_production_house';
$WEBHOOK_SECRET= getenv('WEBHOOK_SECRET') ?: 'change-this-secret';

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
    curl_close($ch);
    return json_decode($res, true);
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
        file_put_contents($file, json_encode(['users'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        chmod($file, 0664);
    }
    $fp = fopen($file, 'c+');
    if (!$fp) return ['users'=>[]];
    flock($fp, LOCK_EX);
    $size = filesize($file);
    $raw = $size > 0 ? fread($fp, $size) : '';
    $data = json_decode($raw ?: '{"users":{}}', true);
    if (!$data) $data = ['users'=>[]];
    return [$data, $fp];
}

function saveData($fp, $data) {
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
            'invitees'   => [],   // list of unique user IDs who completed gate
            'joined_ok'  => false // passed both channels gate
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
                ['text' => 'Try Again ✅', 'callback_data' => 'retry_gate']
            ]
        ]
    ];
}

function forwardKeyboard($shareText) {
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

    $ok1 = in_array($m1['result']['status'], ['member','administrator','creator','restricted']);
    $ok2 = in_array($m2['result']['status'], ['member','administrator','creator','restricted']);

    return $ok1 && $ok2;
}

function botUsername() {
    $me = tg('getMe');
    return $me['ok'] ? $me['result']['username'] : null;
}

// ------------- Webhook helper routes -------------
if (isset($_GET['health'])) {
    http_response_code(200);
    exit('OK');
}

// Set webhook: /index.php?setWebhook=1&secret=WEBHOOK_SECRET&url=FULL_HTTPS_URL
if (isset($_GET['setWebhook'])) {
    if (($_GET['secret'] ?? '') !== $WEBHOOK_SECRET) {
        http_response_code(403);
        exit('Forbidden');
    }
    $url = $_GET['url'] ?? '';
    if (!$url) {
        http_response_code(400);
        exit('Missing url');
    }
    $res = tg('setWebhook', ['url' => $url]);
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Delete webhook: /index.php?deleteWebhook=1&secret=WEBHOOK_SECRET
if (isset($_GET['deleteWebhook'])) {
    if (($_GET['secret'] ?? '') !== $WEBHOOK_SECRET) { http_response_code(403); exit('Forbidden'); }
    $res = tg('deleteWebhook');
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// ------------- Handle updates -------------
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    http_response_code(200);
    echo 'NO-UPDATE';
    exit;
}

list($data, $fp) = loadData($DATA_FILE);

$bot_user = tg('getMe');
$bot_username = $bot_user['ok'] ? $bot_user['result']['username'] : 'your_bot';

if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $from = $msg['from'];
    $user_id = $from['id'];
    $text = trim($msg['text'] ?? '');

    ensureUser($data, $user_id);

    // Deep-link referrals: /start <payload>
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', $text, 2);
        $ref_payload = isset($parts[1]) ? trim($parts[1]) : null;

        // Set invited_by only once, and no self-ref
        if ($ref_payload && is_numeric($ref_payload) && $ref_payload != $user_id) {
            if (!$data['users'][$user_id]['invited_by']) {
                $data['users'][$user_id]['invited_by'] = (int)$ref_payload;
                // We credit referrer only after gate pass
            }
        }

        $gateText = "First join both channels to move to the next step.";
        sendMessage($chat_id, $gateText, gateKeyboard());

    } elseif (strtolower($text) === 'share' || $text === '/getshare') {
        // Convenience to get the forwardable message
        $share = "We are giving YouTube Premium to everyone for 1 month so come and grab the offer.\n\nYour inviter ID: <b>{$user_id}</b>";
        sendMessage($chat_id, $share, forwardKeyboard($share));

    } else {
        // Gentle default
        sendMessage($chat_id, "Use /start to begin.");
    }

} elseif (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $cid = $cq['id'];
    $from = $cq['from'];
    $user_id = $from['id'];
    $message = $cq['message'];
    $chat_id = $message['chat']['id'];
    $mid = $message['message_id'];
    $data_cb = $cq['data'] ?? '';

    ensureUser($data, $user_id);

    if ($data_cb === 'retry_gate') {
        // Check membership of both channels
        $joined = false;
        try {
            $joined = checkBothJoined($user_id);
        } catch (Exception $e) {}

        if (!$joined) {
            answerCallback($cid, "Not yet joined both channels. Please join and try again.");
            editMessageText($chat_id, $mid, "First join both channels to move to the next step.", gateKeyboard());
        } else {
            $data['users'][$user_id]['joined_ok'] = true;

            // If this user was invited by someone, credit the referrer now (only once)
            $ref = $data['users'][$user_id]['invited_by'] ?? null;
            if ($ref && $ref != $user_id) {
                ensureUser($data, $ref);
                if (!in_array($user_id, $data['users'][$ref]['invitees'])) {
                    $data['users'][$ref]['invitees'][] = $user_id;
                }
            }

            // After gate pass → show invite step
            $count = countUniqueInvites($data, $user_id);
            $progress = progressBar($count, 5);

            $text = "To get YouTube Premium for 1 month free, you need to invite 5 people.\n\n".
                    "<b>Your Progress:</b>\n{$progress}\n\n".
                    "Tap Invite and forward the offer to your friends.";
            
            $kb = inviteKeyboard($GLOBALS['bot_username'], $user_id);
            editMessageText($chat_id, $mid, $text, $kb);
            answerCallback($cid, "Great! Gate passed ✅");
        }
    }

} elseif (isset($update['inline_query'])) {
    // User pressed "Forward" button (switch_inline_query). We provide the promo content.
    $iq = $update['inline_query'];
    $qid = $iq['id'];
    $query = $iq['query']; // contains the share text we put
    $result = [
        [
            'type' => 'article',
            'id'   => 'promo1',
            'title'=> 'Share this offer',
            'input_message_content' => [
                'message_text' => $query,
                'parse_mode'   => 'HTML',
                'disable_web_page_preview' => true
            ],
            'description' => 'Tap to send this message to your friends.'
        ]
    ];
    tg('answerInlineQuery', [
        'inline_query_id' => $qid,
        'results' => json_encode($result),
        'cache_time' => 1,
        'is_personal' => true
    ]);
}

// After every message/update: if user has met 5 invites, send success gate with admin contact (once)
if (isset($update['message'])) {
    $from = $update['message']['from'];
    $chat_id = $update['message']['chat']['id'];
    $user_id = $from['id'];
    ensureUser($data, $user_id);

    if ($data['users'][$user_id]['joined_ok'] && hasMetThreshold($data, $user_id, 5)) {
        sendMessage($chat_id, "✅ You’ve reached 5 invites! Contact the Admin for premium.", successKeyboard());
    }
}

saveData($fp, $data);

// Notes:
// • Referral is credited only when the referred user passes the channel gate.
// • “Forward” button uses inline mode (switch_inline_query). Enable Inline Mode in @BotFather.
// • For public channels, bot can check membership with getChatMember.
