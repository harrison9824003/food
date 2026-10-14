<?php

return [

    // 用餐時間前幾小時（含）以前取消，全額退訂金；之後取消不退、訂金沒收。
    // 後台人員代為取消（店方的原因，例如公休、客訴）一律全額退，不受這個限制。
    'full_refund_hours' => (int) env('REFUND_FULL_HOURS', 24),

    // 呼叫綠界時「無法確認結果」（逾時、5xx）的最多嘗試次數；用盡後標記失敗，需人工到綠界後台確認，不再自動重試
    'max_attempts' => (int) env('REFUND_MAX_ATTEMPTS', 3),

    // 無法確認結果時，第 n 次之後等多久再試（秒）
    'retry_backoff' => [60, 300, 900],

    // 退款的 HTTP 逾時秒數
    'timeout' => 15,

    // 本機模擬：用「付款模擬器」產生的付款，綠界根本沒有這筆交易，真的去退只會得到「訂單不存在」。
    // 開啟時，這類付款的退款直接當成成功（只在非正式環境、且付款模擬器開啟時有效），讓整條流程可以在本機展示。
    'simulate_for_simulated_payments' => (bool) env('REFUND_SIMULATE', true),

];
