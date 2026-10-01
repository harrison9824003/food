<?php

namespace App\Booking;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 訂位狀態變更（顧客取消、後台標記已到／未到／取消、未來的逾時釋放）的唯一入口。
 * 集中在這裡，才能確保「狀態變了，名額與桌位一定同步歸還」。
 */
class ReservationStatusService
{
    /**
     * @throws ApiException
     */
    public function transition(Reservation $reservation, ReservationStatus $to): Reservation
    {
        return DB::transaction(function () use ($reservation, $to) {
            // 鎖住這筆訂位再判斷：避免同時「顧客取消」與「店員標記已到」，或連點兩次取消，
            // 兩個請求都讀到舊狀態、各自通過檢查、結果名額被歸還兩次。
            $current = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            // 冪等：已經是目標狀態就直接回傳，重複請求（重送、連點）不會重複歸還名額
            if ($current->status === $to) {
                return $current;
            }

            if (! in_array($to, $current->status->allowedTransitions(), true)) {
                throw new ApiException(
                    "訂位目前為「{$current->status->label()}」，無法變更為「{$to->label()}」",
                    'INVALID_STATUS_TRANSITION',
                    409,
                );
            }

            // 已付訂金的訂位取消要走退款流程（里程碑 6），在那之前不允許直接取消，避免收了錢卻沒退
            if ($to === ReservationStatus::Cancelled && $current->payment_status === PaymentStatus::Paid) {
                throw new ApiException('此訂位已付訂金，取消需走退款流程', 'REFUND_REQUIRED', 409);
            }

            if ($current->status->holdsCapacity() && ! $to->holdsCapacity()) {
                $this->release($current);
            }

            $current->update(['status' => $to]);

            return $current;
        });
    }

    /** 歸還名額與桌位。 */
    private function release(Reservation $reservation): void
    {
        // 條件 booked >= ?：booked 是 UNSIGNED，扣成負數會直接報錯；
        // 若帳對不上（affected = 0）寧可記錄下來讓人查，也不要讓取消失敗。
        $affected = DB::update(
            'UPDATE slots SET booked = booked - ?, updated_at = ? WHERE id = ? AND booked >= ?',
            [$reservation->party_size, now(), $reservation->slot_id, $reservation->party_size],
        );

        if ($affected !== 1) {
            Log::error('slot counter out of sync while releasing capacity', [
                'reservation_id' => $reservation->id,
                'slot_id' => $reservation->slot_id,
                'party_size' => $reservation->party_size,
            ]);
        }

        // 必須刪除，否則 UNIQUE(table_id, slot_id) 會讓這些桌子永遠無法再被訂
        DB::table('reservation_tables')->where('reservation_id', $reservation->id)->delete();
    }
}
