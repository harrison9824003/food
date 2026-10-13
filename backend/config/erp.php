<?php

return [

    'url' => rtrim(env('MOCK_ERP_URL', 'http://mock-erp:3000'), '/'),

    // 單次請求的逾時秒數。worker 是同步等回應的，太長會拖慢整批
    'timeout' => (int) env('MOCK_ERP_TIMEOUT', 5),

    // 一次撈幾筆事件
    'batch_size' => 50,

    // 失敗幾次後放棄、進入失敗清單（含第一次）
    'max_attempts' => (int) env('ERP_MAX_ATTEMPTS', 6),

    // 指數退避的基準秒數：第 n 次失敗後等 base × 2^(n-1) 秒（60、120、240、480、960…），再加 ±20% 隨機抖動
    'backoff_base_seconds' => (int) env('ERP_BACKOFF_BASE', 60),

    // 事件被 worker 領走後，多久沒有結果就視為 worker 當掉、讓別人重新領取
    'lease_seconds' => 120,

];
