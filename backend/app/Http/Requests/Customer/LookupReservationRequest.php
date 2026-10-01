<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 查詢／取消訂位：以「訂位代號 + 手機號碼」核對身分。
 * 用 POST body 傳遞而不是網址，避免手機號碼出現在 nginx access log 與瀏覽器歷史。
 * 里程碑 6 加入簡訊驗證碼後，會改成以驗證碼證明持有該手機。
 */
class LookupReservationRequest extends FormRequest
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
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
            'phone' => ['required', 'string', 'regex:/^09\d{8}$/'],
        ];
    }
}
