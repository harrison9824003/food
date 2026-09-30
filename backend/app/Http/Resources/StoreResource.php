<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Store
 */
class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'advance_days' => $this->advance_days,
            'cutoff_minutes' => $this->cutoff_minutes,
            'slot_capacity_default' => $this->slot_capacity_default,
            'deposit_per_person' => $this->deposit_per_person,
        ];
    }
}
