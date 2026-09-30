<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class StoreClosedDateRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'closed_date' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:today',
                Rule::unique('store_closed_dates', 'closed_date')->where('store_id', $this->route('store')->id),
            ],
            'reason' => ['nullable', 'string', 'max:100'],
        ];
    }
}
