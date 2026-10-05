<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 給顧客看的菜單品項：不含排序、上下架等內部欄位。
 *
 * @mixin \App\Models\MenuItem
 */
class MenuItemPublicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'is_sold_out' => $this->is_sold_out,
            'images' => $this->images->map(fn ($image) => [
                'url' => $image->url(),
                'thumb_url' => $image->thumbUrl(),
            ])->values(),
        ];
    }
}
