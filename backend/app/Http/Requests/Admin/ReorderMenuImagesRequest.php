<?php

namespace App\Http\Requests\Admin;

class ReorderMenuImagesRequest extends ManagesStoreSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:'.config('menu.max_images')],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
