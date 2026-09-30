<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class StoreDiningTableRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('dining_tables', 'code')->where('store_id', $this->route('store')->id),
            ],
            'min_seats' => ['required', 'integer', 'between:1,50'],
            'max_seats' => ['required', 'integer', 'between:1,50', 'gte:min_seats'],
            'is_combinable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
