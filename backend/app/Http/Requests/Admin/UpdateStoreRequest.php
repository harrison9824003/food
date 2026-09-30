<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class UpdateStoreRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('stores', 'name')->ignore($this->route('store'))],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'advance_days' => ['sometimes', 'integer', 'between:1,365'],
            'cutoff_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'slot_capacity_default' => ['sometimes', 'integer', 'between:1,1000'],
            'deposit_per_person' => ['sometimes', 'integer', 'between:0,100000'],
        ];
    }
}
