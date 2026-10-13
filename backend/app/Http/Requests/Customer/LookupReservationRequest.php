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
            // 嚴格模式下手機號碼由簡訊驗證憑證決定、這個欄位不採信；寬鬆模式（本機開發與測試）才需要（見 PhoneAuth）
            'phone' => ['sometimes', 'string', 'regex:/^09\d{8}$/'],
        ];
    }
}
