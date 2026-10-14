<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Refunds\CancellationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 給顧客看的訂位資料。
 *
 * @mixin \App\Models\Reservation
 */
class ReservationResource extends JsonResource
{
    /** @return array{refundable: bool, deposit: int, free_cancellation_until: string}|null */
    private function cancellationPreview(): ?array
    {
        if ($this->status !== ReservationStatus::Confirmed || $this->payment_status !== PaymentStatus::Paid) {
            return null;
        }

        $decision = CancellationPolicy::decide($this->resource);

        return [
            'refundable' => $decision['refundable'],
            'deposit' => $this->deposit_amount,
            'free_cancellation_until' => $decision['free_cancellation_until']->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'deposit_amount' => $this->deposit_amount,
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            // 倒數計時用伺服器的時間，不用顧客手機的時鐘（可能快慢好幾分鐘）
            'server_time' => now()->toIso8601String(),
            'can_pay' => $this->status === ReservationStatus::PendingPayment
                && $this->deposit_amount > 0
                && $this->hold_expires_at?->isFuture() === true,
            // 已付訂金、還沒用餐的訂位：取消會怎樣？讓顧客在按下取消「之前」就知道要退還是沒收
            'cancellation' => $this->cancellationPreview(),
            // 這張訂位最近一筆退款的進度
            'refund' => $this->whenLoaded('refunds', fn () => ($r = $this->refunds->last()) ? [
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'amount' => $r->amount,
            ] : null),
            'customer_name' => $this->customer_name,
            'party_size' => $this->party_size,
            'note' => $this->note,
            'date' => $this->slot->slot_date->toDateString(),
            'time' => substr($this->slot->start_time, 0, 5),
            'store' => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'address' => $this->store->address,
                'phone' => $this->store->phone,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])->values()),
        ];
    }
}
