<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class StoreMenuCategoryRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('menu_categories', 'name')->where('store_id', $this->route('store')->id),
            ],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
