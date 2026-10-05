<?php

namespace App\Http\Resources;

use App\Enums\ReservationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 給顧客看的訂位資料。
 *
 * @mixin \App\Models\Reservation
 */
class ReservationResource extends JsonResource
{
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
