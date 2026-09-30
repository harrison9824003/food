<?php

namespace App\Http\Requests\Admin;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;

class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Store::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:stores,name'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'advance_days' => ['sometimes', 'integer', 'between:1,365'],
            'cutoff_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'slot_capacity_default' => ['sometimes', 'integer', 'between:1,1000'],
            'deposit_per_person' => ['sometimes', 'integer', 'between:0,100000'],
        ];
    }
}
