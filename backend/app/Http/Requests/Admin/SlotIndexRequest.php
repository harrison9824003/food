<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SlotIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('store'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
