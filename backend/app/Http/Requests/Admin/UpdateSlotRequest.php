<?php

namespace App\Http\Requests\Admin;

class UpdateSlotRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // 0 代表暫停該時段開放，而不是刪除時段
        return [
            'capacity' => ['required', 'integer', 'between:0,1000'],
        ];
    }
}
