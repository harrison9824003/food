<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'price' => ['sometimes', 'integer', 'between:0,100000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('menu_categories', 'id')->where('store_id', $this->route('store')->id)],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
            'is_active' => ['sometimes', 'boolean'],
            'is_sold_out' => ['sometimes', 'boolean'],
        ];
    }
}
