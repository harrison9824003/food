<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 給後台看的菜單品項（含已下架、已售完）。
 *
 * @mixin \App\Models\MenuItem
 */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_sold_out' => $this->is_sold_out,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'thumb_url' => $image->thumbUrl(),
                'width' => $image->width,
                'height' => $image->height,
            ])->values()),
        ];
    }
}
