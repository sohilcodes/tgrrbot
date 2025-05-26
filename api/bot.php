<?php

$config = include('config.php');
$botToken = $config['bot_token'];
$apiUrl = "https://api.telegram.org/bot{$botToken}/";

function sendRequest($method, $parameters) {
    global $apiUrl;
    $url = $apiUrl . $method;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($parameters),
        ],
    ];
    $context  = stream_context_create($options);
    file_get_contents($url, false, $context);
}

function getChatMember($chat_id, $user_id) {
    global $apiUrl;
    $url = "{$apiUrl}getChatMember?chat_id={$chat_id}&user_id={$user_id}";
    $response = file_get_contents($url);
    return json_decode($response, true);
}

function getBotName() {
    global $apiUrl;
    $response = file_get_contents("{$apiUrl}getMe");
    $data = json_decode($response, true);
    return $data['result']['username'];
}

function saveUser($userId, $firstName, $referrerId = null) {
    global $config;
    $data = json_decode(file_get_contents('data.json'), true);
    if (!isset($data[$userId])) {
        $data[$userId] = [
            'balance' => $config['bonus_amount'],
            'wait_for_ans' => "no"
        ];
        file_put_contents('data.json', json_encode($data));

        sendRequest('sendMessage', [
            'chat_id' => $userId,
            'text' => "<b>🎉 Congrats, You received ₹{$config['bonus_amount']} welcome bonus.</b>",
            'parse_mode' => 'HTML'
        ]);

        if ($referrerId && isset($data[$referrerId])) {
            $data[$referrerId]['balance'] += $config['per_reffer_bonus'];
            file_put_contents('data.json', json_encode($data));
            sendRequest('sendMessage', [
                'chat_id' => $referrerId,
                'text' => "<b>➤ New Referral: {$firstName}\n₹{$config['per_reffer_bonus']} added to your balance</b>",
                'parse_mode' => 'HTML'
            ]);
        }
    }
}

function checkUserJoinedChannels($userId) {
    global $config;
    foreach ($config['channels_on_check'] as $channel) {
        $chatMember = getChatMember($channel, $userId);
        if (!in_array($chatMember['result']['status'], ['member', 'administrator', 'creator'])) {
            return false;
        }
    }
    return true;
}

function processWithdrawal($chatId, $upi) {
    global $config;
    $data = json_decode(file_get_contents('data.json'), true);

    // Validate UPI
    if (!preg_match('/^[\w.-]+@[\w.-]+$/', $upi)) {
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>‼️ Invalid UPI address. Please enter a valid UPI address:</b>",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // Check balance and minimum withdrawal limit
    if (isset($data[$chatId]) && $data[$chatId]['balance'] >= $config['min_withdraw_limit']) {
        $amount = $data[$chatId]['balance'];
        $mid = $config['f2s_payout_mid'];
        $mkey = $config['f2s_payout_mkey'];
        $guid = $config['f2s_payout_guid'];

        // Make the payout request
        $payoutUrl = "https://full2sms.in/api/v2/payout?mid={$mid}&mkey={$mkey}&guid={$guid}&type=upi&amount={$amount}&upi={$upi}";
        $response = json_decode(file_get_contents($payoutUrl), true);

        if ($response['status'] === 'success') {
            // Deduct balance and update data
            $data[$chatId]['balance'] = 0;
            file_put_contents('data.json', json_encode($data));

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>✅ Withdrawal successful. ₹{$amount} has been sent to your UPI address.</b>",
            'parse_mode' => 'HTML'
            ]);
        } else {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>🚫 Withdrawal failed. Please try again later.</b>",
            'parse_mode' => 'HTML'
            ]);
        }
    } else {
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>⚠️You not have enough balance, minimum withdrawal amount is ₹{$config['min_withdraw_limit']}.</b>",
            'parse_mode' => 'HTML'
        ]);
    }

    // Reset wait_for_ans
    $data[$chatId]['wait_for_ans'] = 'no';
    file_put_contents('data.json', json_encode($data));

    // Send the normal keyboard
    sendNormalKeyboard($chatId);
}

function sendNormalKeyboard($chatId) {
    $keyboard = [
        [['text' => '💰 Balance'], ['text' => '🔗 Referral']],
        [['text' => '💸 Withdraw']]
    ];

    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "<b>🏘 Main Menu</b>",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true])
    ]);
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $firstName = $message['from']['first_name'];
    $text = $message['text'];
    $data = json_decode(file_get_contents('data.json'), true);

    if (strpos($text, '/start') === 0) {
        $referrerId = null;
        if (strpos($text, 'ref_') !== false) {
            $referrerId = str_replace('/start ref_', '', $text);
        }
        saveUser($chatId, $firstName, $referrerId);

        $keyboard = [];
        foreach (array_chunk($config['channels'], 3) as $chunk) {
            $row = [];
            foreach ($chunk as $channel) {
                $row[] = ['text' => '↗️ Join', 'url' => "https://t.me/" . ltrim($channel, '@')];
            }
            $keyboard[] = $row;
        }
        $keyboard[] = [['text' => '✅ Joined', 'callback_data' => '/joined']];

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>✳️ Welcome to our bot!\n\n👨‍💻 Reffer & earn rupees and withdraw it to your UPI instantly from this bot.\n\n⬇️ Before that, you need to join our all channels which are given bellow.</b>",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        
        if (isset($data[$chatId]) && $data[$chatId]['wait_for_ans'] === 'yes') {
        $data[$chatId]['wait_for_ans'] = 'no';
        file_put_contents('data.json', json_encode($data));
    }
    } elseif ($text === '💰 Balance') {
        if (isset($data[$chatId])) {
            $balance = $data[$chatId]['balance'];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>💵 Your Balance is: ₹{$balance}</b>",
                'parse_mode' => 'HTML'
            ]);
        }
        
        if (isset($data[$chatId]) && $data[$chatId]['wait_for_ans'] === 'yes') {
        $data[$chatId]['wait_for_ans'] = 'no';
        file_put_contents('data.json', json_encode($data));
    }
    } elseif ($text === '🔗 Referral') {
        $botName = getBotName();
        $referralLink = "https://t.me/{$botName}?start=ref_{$chatId}";
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>🔗 Your refferral link is:\n{$referralLink}\n\n🔰 On each referral you will receive ₹{$config['per_reffer_bonus']} referral bonus</b>",
            'parse_mode' => 'HTML'
        ]);
        
        if (isset($data[$chatId]) && $data[$chatId]['wait_for_ans'] === 'yes') {
        $data[$chatId]['wait_for_ans'] = 'no';
        file_put_contents('data.json', json_encode($data));
    }
    } elseif ($text === '💸 Withdraw') {
        $data[$chatId]['wait_for_ans'] = 'yes';
        file_put_contents('data.json', json_encode($data));

        $keyboard = [
            [['text' => '🚫 Cancel']]
        ];

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>🆙 Send your UPI address:</b>",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true])
        ]);
    } elseif ($text === '🚫 Cancel') {
        $data[$chatId]['wait_for_ans'] = 'no';
        file_put_contents('data.json', json_encode($data));

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>❌ Process Cancelled</b>",
            'parse_mode' => 'HTML'
        ]);

        sendNormalKeyboard($chatId);
    } elseif (isset($data[$chatId]) && $data[$chatId]['wait_for_ans'] === 'yes') {
        processWithdrawal($chatId, $text);
    }
} elseif (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $chatId = $callbackQuery['from']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $data = $callbackQuery['data'];
    $fdata = json_decode(file_get_contents('data.json'), true);

    if ($data === '/joined') {
    
    if (isset($fdata[$chatId]) && $fdata[$chatId]['wait_for_ans'] === 'yes') {
        $fdata[$chatId]['wait_for_ans'] = 'no';
        file_put_contents('data.json', json_encode($fdata));
    }
    
        if (checkUserJoinedChannels($chatId)) {
            $keyboard = [
                [['text' => '💰 Balance'], ['text' => '🔗 Referral']],
                [['text' => '💸 Withdraw']]
            ];
            
            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>💁‍♂ Welcome! Reffer & Earn Rupees.</b>",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true])
            ]);
            
              
              
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackQuery['id'],
                'text' => "You haven't joined all required channels. Please join them and try again.",
                'show_alert' => true
            ]);
            
              
        }
    }
}
?>
