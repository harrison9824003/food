<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class StoreMenuItemRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'between:0,100000'],
            'description' => ['nullable', 'string', 'max:500'],
            // 分類必須屬於同一間門市，否則可以把品項掛到別間門市的分類底下
            'category_id' => ['nullable', 'integer', Rule::exists('menu_categories', 'id')->where('store_id', $this->route('store')->id)],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
            'is_active' => ['sometimes', 'boolean'],
            'is_sold_out' => ['sometimes', 'boolean'],
        ];
    }
}
