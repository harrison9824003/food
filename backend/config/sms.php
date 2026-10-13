<?php

return [

    // mock = 不真的發簡訊，寫進資料庫（開發用，正式環境會被拒絕）；every8d = 互動資通
    'driver' => env('SMS_DRIVER', 'mock'),

    // 訂位與查詢是否必須先通過簡訊驗證。正式環境無論設定如何一律要求（見 PhoneAuth），
    // 這個開關只讓本機開發與既有測試可以略過簡訊。
    'require_verification' => (bool) env('SMS_REQUIRE_VERIFICATION', true),

    // 開發用收件匣（GET /api/dev/sms）：讓本機看得到 Mock 簡訊內容。只在非正式環境、且明確開啟時可用。
    'inbox_enabled' => (bool) env('SMS_MOCK_INBOX', false),

    'code_length' => 6,
    'code_ttl_minutes' => 5,
    'max_attempts' => 5,
    'resend_cooldown_seconds' => 60,

    // 驗證成功後換到的憑證有效多久；用來建立訂位、查詢與取消
    'token_ttl_minutes' => 15,

    // 限流：同一手機、同一 IP 每小時最多送幾則。擋「拿我們的系統灌爆別人的手機」與簡訊費用被盜刷
    'send_per_phone_per_hour' => (int) env('SMS_SEND_PER_PHONE_PER_HOUR', 5),
    'send_per_ip_per_hour' => (int) env('SMS_SEND_PER_IP_PER_HOUR', 20),
    'verify_per_ip_per_10_minutes' => (int) env('SMS_VERIFY_PER_IP', 30),

    // 發送失敗的重試間隔（秒）：30 秒、2 分鐘、10 分鐘，之後放棄
    'retry_backoff' => [30, 120, 600],

    'every8d' => [
        'url' => env('EVERY8D_URL', 'https://api.every8d.com/API21/HTTP/sendSMS.ashx'),
        'uid' => env('EVERY8D_UID'),
        'pwd' => env('EVERY8D_PWD'),
        'timeout' => 10,
    ],

];
