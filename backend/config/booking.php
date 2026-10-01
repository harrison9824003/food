<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 防超賣策略
    |--------------------------------------------------------------------------
    |
    | atomic      條件式原子 UPDATE（預設、正式使用）
    | pessimistic SELECT ... FOR UPDATE 後再更新（壓測比較用）
    | unsafe      先 SELECT 再 UPDATE、不加鎖（壓測示範「沒防護會超賣」；正式環境一律拒絕）
    |
    */
    'strategy' => env('BOOKING_STRATEGY', 'atomic'),

    // 是否接受請求標頭 X-Booking-Strategy 覆蓋策略。只給壓測用，且正式環境無論如何都會忽略。
    'allow_strategy_header' => (bool) env('BOOKING_ALLOW_STRATEGY_HEADER', false),

    // 建立訂位每個 IP 每分鐘上限；壓測時從單一 IP 打大量請求，需要調高
    'create_rate_limit' => (int) env('BOOKING_CREATE_RATE_LIMIT', 10),

    // 查詢類公開 API（門市、日曆、可訂時段）每個 IP 每分鐘上限
    'public_read_rate_limit' => (int) env('BOOKING_PUBLIC_READ_RATE_LIMIT', 120),

    // 單筆訂位人數上限
    'max_party_size' => 12,

    // 需要訂金的訂位，暫時保留名額的分鐘數（里程碑 5 的逾時釋放使用）
    'hold_minutes' => 10,

    // 遇到死鎖（SQLSTATE 40001 / 1213）時的最多嘗試次數（含第一次）
    'deadlock_attempts' => 3,

];
