<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class SimulatePaymentRequest extends FormRequest
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
            'merchant_trade_no' => ['required', 'string', 'max:20'],
            'outcome' => ['required', 'in:paid,failed'],
            // 連續送幾次相同的通知，用來示範冪等
            'times' => ['sometimes', 'integer', 'between:1,3'],
        ];
    }
}
