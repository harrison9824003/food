<?php

namespace App\Booking;

use Illuminate\Support\Facades\DB;

/**
 * ⚠️ 無防護版本，只用來在壓測中示範「沒防護會超賣」。正式環境會被 CapacityStrategyResolver 拒絕。
 *
 * 典型的 check-then-act 競態：
 *
 *   交易 A：SELECT booked → 18        交易 B：SELECT booked → 18
 *   交易 A：18 + 2 <= 20，可以        交易 B：18 + 2 <= 20，可以（讀到的是舊值）
 *   交易 A：UPDATE booked = 20        交易 B：UPDATE booked = 20  ← 用舊值算出來的絕對值，蓋掉 A 的結果
 *
 * 兩筆訂位（共 4 人）都成功，但 booked 只記了 20：實際訂位人數超過名額，而計數器還看起來「剛好滿」。
 *
 * 這是 lost update，所以資料表上的 CHECK (booked <= capacity) 也攔不住——booked 本身沒有超過 capacity，
 * 超賣的是「訂位人數總和」。驗證超賣請看 SUM(reservations.party_size)，不要只看 slots.booked。
 */
class UnsafeStrategy implements CapacityStrategy
{
    public function name(): string
    {
        return 'unsafe';
    }

    public function take(int $slotId, int $guests): bool
    {
        // 一般的不加鎖讀取
        $slot = DB::selectOne('SELECT booked, capacity FROM slots WHERE id = ?', [$slotId]);

        if ($slot === null || $slot->booked + $guests > $slot->capacity) {
            return false;
        }

        // 用「讀到的舊值」算出絕對值寫回，而不是 booked = booked + ?
        DB::update('UPDATE slots SET booked = ?, updated_at = ? WHERE id = ?', [$slot->booked + $guests, now(), $slotId]);

        return true;
    }
}
