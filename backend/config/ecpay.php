<?php

return [

    'merchant_id' => env('ECPAY_MERCHANT_ID', '2000132'),
    'hash_key' => env('ECPAY_HASH_KEY', '5294y06JbISpM5x9'),
    'hash_iv' => env('ECPAY_HASH_IV', 'v77hoKGq4kWxNNIS'),

    // test = 綠界測試環境（stage）；production = 正式環境
    'env' => env('ECPAY_ENV', 'test'),

    'endpoints' => [
        'test' => [
            'checkout' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
            'query' => 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5',
            'refund' => 'https://payment-stage.ecpay.com.tw/CreditDetail/DoAction',
        ],
        'production' => [
            'checkout' => 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5',
            'query' => 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5',
            'refund' => 'https://payment.ecpay.com.tw/CreditDetail/DoAction',
        ],
    ],

    // 綠界「背景通知」打回來的網址（ReturnURL）。必須是綠界連得到的公開網址：
    // 本機開發用 ngrok / cloudflared 取得公開網址後，設定 ECPAY_NOTIFY_URL，見 docs/payment.md。
    'notify_url' => env('ECPAY_NOTIFY_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/payments/ecpay/notify'),

    // 顧客付完款後，瀏覽器被綠界導回的網址（OrderResultURL）；只負責轉去前台顯示，不改任何狀態。
    'order_result_url' => env('ECPAY_ORDER_RESULT_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api/payments/ecpay/return'),

    // 前台網址，付款完成後導回 {frontend_url}/payment/return
    'frontend_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

    // 本機付款模擬器：在伺服器端組一份簽好章的通知，真的 POST 到我們自己的 notify 網址。
    // 只在非正式環境、且明確開啟時才可用。
    'simulator_enabled' => (bool) env('ECPAY_SIMULATOR', false),
    // 模擬器從容器內部打自己，不必經過公開網址
    'simulator_notify_url' => env('ECPAY_SIMULATOR_NOTIFY_URL', 'http://nginx/api/payments/ecpay/notify'),

    // 訂位逾時（hold_expires_at）之後再多給的寬限分鐘數：顧客可能剛好正在綠界頁面付款
    'grace_minutes' => (int) env('PAYMENT_GRACE_MINUTES', 2),

    // 付款建立超過這麼多分鐘還沒收到通知，對帳排程才會主動向綠界查詢
    'reconcile_after_minutes' => (int) env('PAYMENT_RECONCILE_AFTER_MINUTES', 3),

    // 付款建立超過這麼多分鐘仍未付款，視為放棄（標記失敗，不再對帳）
    'abandon_after_minutes' => (int) env('PAYMENT_ABANDON_AFTER_MINUTES', 120),

    // 無法向綠界確認付款狀態（綠界故障）時，訂位最多額外保留多少分鐘；
    // 超過就照樣釋放，避免綠界出問題時名額永遠被占住（晚到的付款由補救流程處理）
    'max_unverified_hold_minutes' => (int) env('PAYMENT_MAX_UNVERIFIED_HOLD_MINUTES', 30),

];
