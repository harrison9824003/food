<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['sometimes', 'integer'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::enum(ReservationStatus::class)],
            'q' => ['sometimes', 'string', 'max:50'],
            'needs_refund' => ['sometimes', 'boolean'],
        ];
    }
}
