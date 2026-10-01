<?php

namespace App\Booking;

/**
 * 「佔時段名額」的做法。必須在呼叫端已開啟的資料庫交易內執行。
 *
 * 同一個介面有三種實作，差別只在「怎麼判斷還有沒有名額」，
 * 壓測時逐一比較它們的正確性（有沒有超賣）與效能。
 */
interface CapacityStrategy
{
    public function name(): string;

    /**
     * 嘗試為 $slotId 佔用 $guests 個名額。
     *
     * @return bool true 表示佔用成功；false 表示名額不足
     */
    public function take(int $slotId, int $guests): bool;
}
