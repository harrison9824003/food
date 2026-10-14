<?php

namespace App\Erp;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 把「這張訂位發生了什麼事」寫進 outbox_events。
 *
 * ⚠️ 必須在改動訂位的那個資料庫交易「裡面」呼叫（record 會檢查，不在交易內直接拋例外）。
 * 這就是 Outbox Pattern 的重點：訂位的異動與「要通知 ERP」這件事寫在同一個交易，
 *   - 交易回滾 → 事件也一起消失，不會出現「通知了 ERP，訂位其實沒成立」
 *   - 交易提交 → 事件一定存在，不會出現「訂位成立了，卻忘了通知 ERP」（例如寫完訂位後程式當掉）
 * 如果改成「交易提交之後再呼叫 ERP」，這兩種不一致都會發生。
 *
 * 實際送出由 OutboxDispatcher 另外非同步處理，ERP 慢或掛掉都不影響顧客訂位。
 */
class OutboxRecorder
{
    public const CREATED = 'reservation.created';

    public const STATUS_CHANGED = 'reservation.status_changed';

    /** 退款申請、簽核、完成、失敗。放在同一張訂位的事件序列裡，ERP 才能依序看到「取消 → 退款申請 → 退款完成」。 */
    public const REFUND_CHANGED = 'reservation.refund_changed';

    public function record(Reservation $reservation, string $eventType, array $extra = []): OutboxEvent
    {
        // 不在交易內呼叫，就失去「同進同退」的保證，是程式寫錯了，要在開發時就炸出來
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OutboxRecorder::record() 必須在資料庫交易內呼叫');
        }

        $reservation->loadMissing(['slot', 'store', 'items']);

        return OutboxEvent::create([
            'aggregate_type' => 'reservation',
            'aggregate_id' => $reservation->id,
            'store_id' => $reservation->store_id,
            'event_type' => $eventType,
            'payload' => $this->payload($reservation, $eventType) + $extra,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);
    }

    /**
     * 事件內容是「當下的完整快照」，不是差異：接收端（ERP）不需要回頭問我們任何事，
     * 重送、亂序到達時也各自完整、不依賴別的事件。
     *
     * @return array<string, mixed>
     */
    private function payload(Reservation $reservation, string $eventType): array
    {
        return [
            'event_type' => $eventType,
            'reservation' => [
                'id' => $reservation->id,
                'code' => $reservation->code,
                'status' => $reservation->status->value,
                'payment_status' => $reservation->payment_status->value,
                'store_id' => $reservation->store_id,
                'store_name' => $reservation->store->name,
                'date' => $reservation->slot->slot_date->toDateString(),
                'time' => substr($reservation->slot->start_time, 0, 5),
                'party_size' => $reservation->party_size,
                'customer_name' => $reservation->customer_name,
                'phone' => $reservation->phone,
                'note' => $reservation->note,
                'deposit_amount' => $reservation->deposit_amount,
                'items' => $reservation->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price])->values()->all(),
            ],
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
