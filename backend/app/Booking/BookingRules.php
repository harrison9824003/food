<?php

namespace App\Booking;

use App\Models\Slot;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 訂位的開放規則。查詢可訂時段與實際下單共用同一份判斷，避免「畫面說可以訂、送出卻被拒」。
 */
class BookingRules
{
    /**
     * @param  bool  $dateIsClosed  該日是否被設為公休日（批次查詢時由呼叫端一次查好，避免每個時段各查一次）
     */
    public function blockReason(Store $store, Slot $slot, int $guests, bool $dateIsClosed, ?Carbon $now = null): ?BookingBlock
    {
        $now ??= now();

        if (! $store->is_active) {
            return new BookingBlock('STORE_INACTIVE', '此門市目前未開放訂位');
        }

        $date = $slot->slot_date->copy()->startOfDay();
        if ($date->lt($now->copy()->startOfDay()) || $date->gt($now->copy()->startOfDay()->addDays($store->advance_days))) {
            return new BookingBlock('OUT_OF_WINDOW', "只能預約今天起 {$store->advance_days} 天內的時段");
        }

        if ($dateIsClosed) {
            return new BookingBlock('DATE_CLOSED', '該日門市公休');
        }

        $start = $this->startsAt($slot);
        if ($now->gte($start->copy()->subMinutes($store->cutoff_minutes))) {
            return new BookingBlock('CUTOFF_PASSED', '已超過此時段的訂位截止時間');
        }

        if ($slot->remaining() < $guests) {
            return new BookingBlock('SLOT_FULL', '該時段名額不足');
        }

        return null;
    }

    public function isDateClosed(int $storeId, string $date): bool
    {
        return DB::table('store_closed_dates')->where('store_id', $storeId)->where('closed_date', $date)->exists();
    }

    /** 時段的實際開始時間（門市所在時區，即應用程式時區）。 */
    public function startsAt(Slot $slot): Carbon
    {
        return Carbon::parse($slot->slot_date->toDateString().' '.$slot->start_time);
    }
}
