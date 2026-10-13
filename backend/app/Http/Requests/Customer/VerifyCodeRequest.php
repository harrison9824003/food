<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCodeRequest extends FormRequest
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
            'phone' => ['required', 'string', 'regex:/^09\d{8}$/'],
            'code' => ['required', 'string', 'regex:/^\d{'.config('sms.code_length').'}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => '請輸入正確的手機號碼（09 開頭共 10 碼）',
            'code.regex' => '驗證碼是 '.config('sms.code_length').' 位數字',
        ];
    }
}
