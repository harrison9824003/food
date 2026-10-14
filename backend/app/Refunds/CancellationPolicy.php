<?php

namespace App\Refunds;

use App\Models\Reservation;
use Illuminate\Support\Carbon;

/**
 * 取消與退款政策：用餐時間前 N 小時（含）以前取消全額退訂金，之後取消不退。
 */
final class CancellationPolicy
{
    /**
     * @return array{refundable: bool, free_cancellation_until: Carbon}
     */
    public static function decide(Reservation $reservation, ?Carbon $now = null): array
    {
        $now ??= now();
        $reservation->loadMissing('slot');

        $start = Carbon::parse($reservation->slot->slot_date->toDateString().' '.$reservation->slot->start_time);
        $deadline = $start->copy()->subHours(config('refund.full_refund_hours'));

        // 剛好在期限那一刻取消仍算「之前」：顧客依畫面上顯示的「可免費取消至 …」操作，不該因為差一秒被判不退
        return ['refundable' => $now->lte($deadline), 'free_cancellation_until' => $deadline];
    }
}
