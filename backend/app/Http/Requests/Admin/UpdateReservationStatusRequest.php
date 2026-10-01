<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->route('reservation'));
    }

    /**
     * 後台只能手動標記這三種；「待付款」「已確認」由系統流程決定，「已逾時」由排程處理。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                ReservationStatus::Arrived->value,
                ReservationStatus::NoShow->value,
                ReservationStatus::Cancelled->value,
            ])],
        ];
    }
}
