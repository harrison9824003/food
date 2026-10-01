<?php

namespace App\Booking;

use Illuminate\Support\Facades\DB;

/**
 * 條件式原子更新（預設、正式使用）。
 *
 * 「檢查還有沒有名額」與「扣名額」合併成單一 UPDATE 敘述，由資料庫在持有該列鎖的情況下一次完成：
 *
 *     UPDATE slots SET booked = booked + ? WHERE id = ? AND booked + ? <= capacity
 *
 * - 沒有「讀 → 判斷 → 寫」之間的空窗，所以不可能兩個請求都以為自己搶到最後一個名額。
 * - 是否成功看 affected rows：1 = 搶到；0 = 名額不足（條件不成立，沒有任何列被修改）。
 * - 不需要 SELECT ... FOR UPDATE，也不需要在應用層持有鎖，只在 UPDATE 到交易結束這段期間鎖住該列。
 *
 * 注意：MySQL 預設回報的是「實際被修改的列數」。這裡只要條件成立，booked 一定會改變（guests >= 1），
 * 所以 0 只可能代表條件不成立，不會被誤判。
 */
class AtomicUpdateStrategy implements CapacityStrategy
{
    public function name(): string
    {
        return 'atomic';
    }

    public function take(int $slotId, int $guests): bool
    {
        $affected = DB::update(
            'UPDATE slots SET booked = booked + ?, updated_at = ? WHERE id = ? AND booked + ? <= capacity',
            [$guests, now(), $slotId, $guests],
        );

        return $affected === 1;
    }
}
