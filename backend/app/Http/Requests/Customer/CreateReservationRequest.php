<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateReservationRequest extends FormRequest
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
            'slot_id' => ['required', 'integer'],
            'party_size' => ['required', 'integer', 'min:1', 'max:'.config('booking.max_party_size')],
            'customer_name' => ['required', 'string', 'max:50'],
            // 台灣手機號碼：09 開頭共 10 碼
            'phone' => ['required', 'string', 'regex:/^09\d{8}$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            // 預點餐點（選填、不計費）。品項是否屬於該門市且上架中，由 ReservationService 核對
            'items' => ['sometimes', 'array', 'max:30'],
            'items.*.menu_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['phone.regex' => '請輸入正確的手機號碼（09 開頭共 10 碼）'];
    }
}
