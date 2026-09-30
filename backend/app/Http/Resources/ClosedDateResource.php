<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StoreClosedDate
 */
class ClosedDateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'closed_date' => $this->closed_date->toDateString(),
            'reason' => $this->reason,
        ];
    }
}
