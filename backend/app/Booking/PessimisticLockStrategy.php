<?php

namespace App\Booking;

use Illuminate\Support\Facades\DB;

/**
 * 悲觀鎖版本（與 AtomicUpdateStrategy 比較用）。
 *
 * 先用 SELECT ... FOR UPDATE 把該時段的列鎖住，再在應用層判斷、最後更新。
 * 因為鎖在「讀」的時候就取得，別的交易讀同一列會被擋住，直到我們的交易結束，所以「讀到的值」到「寫入」
 * 之間不會有人插進來改，結果同樣正確。
 *
 * 與原子更新的差別：多一次往返（SELECT + UPDATE），且鎖從 SELECT 開始就持有到交易結束，
 * 持鎖時間比較長；搶同一列的請求越多，排隊越久。優點是判斷邏輯可以寫在 PHP 裡、能做更複雜的檢查。
 */
class PessimisticLockStrategy implements CapacityStrategy
{
    public function name(): string
    {
        return 'pessimistic';
    }

    public function take(int $slotId, int $guests): bool
    {
        // FOR UPDATE 是「目前讀」（讀最新已提交資料並上鎖），不受交易快照影響
        $slot = DB::selectOne('SELECT booked, capacity FROM slots WHERE id = ? FOR UPDATE', [$slotId]);

        if ($slot === null || $slot->booked + $guests > $slot->capacity) {
            return false;
        }

        DB::update('UPDATE slots SET booked = booked + ?, updated_at = ? WHERE id = ?', [$guests, now(), $slotId]);

        return true;
    }
}
