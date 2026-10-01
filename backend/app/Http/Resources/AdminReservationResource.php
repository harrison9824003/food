<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 給後台看的訂位資料（含聯絡方式、桌位）。
 *
 * @mixin \App\Models\Reservation
 */
class AdminReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'allowed_statuses' => collect($this->status->allowedTransitions())
                ->map(fn ($s) => $s->value)
                ->intersect(['arrived', 'no_show', 'cancelled'])
                ->values(),
            'payment_status' => $this->payment_status->value,
            'deposit_amount' => $this->deposit_amount,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'party_size' => $this->party_size,
            'note' => $this->note,
            'date' => $this->slot->slot_date->toDateString(),
            'time' => substr($this->slot->start_time, 0, 5),
            'store' => ['id' => $this->store->id, 'name' => $this->store->name],
            'tables' => $this->tables->pluck('code')->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
