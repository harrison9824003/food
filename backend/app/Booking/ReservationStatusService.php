<?php

namespace App\Booking;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Erp\OutboxRecorder;
use App\Exceptions\ApiException;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 訂位狀態變更（顧客取消、後台標記已到／未到／取消、付款成功、逾時釋放）的唯一入口。
 * 集中在這裡，才能確保「狀態變了，名額與桌位一定同步歸還」。
 */
class ReservationStatusService
{
    public function __construct(
        private readonly TableAllocator $tables,
        private readonly OutboxRecorder $outbox,
    ) {}

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

            $from = $current->status;
            $current->update(['status' => $to]);

            // 狀態變更與通知 ERP 同進同退（Outbox Pattern）
            $this->outbox->record($current, OutboxRecorder::STATUS_CHANGED, ['from' => $from->value, 'to' => $to->value]);

            return $current;
        });
    }

    /**
     * 收到付款成功：待付款 → 已確認。名額與桌位在建立訂位時就已經保留，這裡不必再動。
     * 呼叫端（PaymentSettlement）須已在交易內並鎖住這筆訂位。
     */
    public function confirmPayment(Reservation $reservation): void
    {
        $from = $reservation->status;

        $reservation->update([
            'status' => ReservationStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'hold_expires_at' => null,
        ]);

        $this->outbox->record($reservation, OutboxRecorder::STATUS_CHANGED, ['from' => $from->value, 'to' => ReservationStatus::Confirmed->value]);
    }

    /**
     * 訂位已因逾時釋放，之後才收到付款成功：嘗試把名額與桌位重新佔回來。
     *
     * 做法與一般訂位相同（條件式原子 UPDATE 佔名額、再分配桌位），所以一樣不會超賣：
     * 如果這段時間名額或桌位已被別人訂走，就補不回來，回傳 false，由呼叫端標記待退款。
     * 呼叫端須已在交易內並鎖住這筆訂位。
     */
    public function reinstate(Reservation $reservation): bool
    {
        $reservation->loadMissing('slot');

        try {
            // 巢狀交易（savepoint）：佔名額成功但分配桌位失敗時，只回滾這一小段，不影響外層已寫入的付款紀錄
            DB::transaction(function () use ($reservation) {
                if (! (new AtomicUpdateStrategy)->take($reservation->slot_id, $reservation->party_size)) {
                    throw new ApiException('該時段名額已滿', 'SLOT_FULL', 409);
                }

                $tableIds = $this->tables->allocate($reservation->slot, $reservation->party_size);

                $reservation->tables()->attach(
                    collect($tableIds)->mapWithKeys(fn (int $id) => [$id => ['slot_id' => $reservation->slot_id]])->all()
                );
            });
        } catch (ApiException) {
            return false;
        }

        $this->confirmPayment($reservation);

        return true;
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
