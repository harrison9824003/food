<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DiningTable
 */
class DiningTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'code' => $this->code,
            'min_seats' => $this->min_seats,
            'max_seats' => $this->max_seats,
            'is_combinable' => $this->is_combinable,
            'is_active' => $this->is_active,
        ];
    }
}
