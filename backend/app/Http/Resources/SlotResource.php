<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Slot
 */
class SlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slot_date' => $this->slot_date->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'capacity' => $this->capacity,
            'booked' => $this->booked,
            'remaining' => $this->remaining(),
        ];
    }
}
