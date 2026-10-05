<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class UpdateMenuCategoryRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('menuCategory');

        return [
            'name' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('menu_categories', 'name')->where('store_id', $category->store_id)->ignore($category->id),
            ],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
