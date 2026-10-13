<?php

namespace App\Sms;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * 顧客身分：「這個請求的人持有哪支手機」。
 *
 * 嚴格模式（正式環境一律如此）：一定要帶簡訊驗證憑證（標頭 X-Phone-Token），手機號碼由憑證決定，
 * 請求內容裡的手機號碼不採信。
 * 寬鬆模式（只限非正式環境且 SMS_REQUIRE_VERIFICATION=false）：直接採用請求裡的手機號碼，給本機開發與測試略過簡訊。
 */
class PhoneAuth
{
    public const HEADER = 'X-Phone-Token';

    public function strict(): bool
    {
        return app()->isProduction() || config('sms.require_verification');
    }

    /**
     * @param  string|null  $claimedPhone  請求內容裡的手機號碼
     * @param  bool  $mustMatch  嚴格模式下，請求內容的手機號碼必須與憑證一致（建立訂位：填的就是要驗證的那支）。
     *                           查詢、取消、付款則完全不採信請求裡的號碼，一律以憑證為準。
     *
     * @throws ApiException
     */
    public function phone(Request $request, ?string $claimedPhone = null, bool $mustMatch = false): string
    {
        if (! $this->strict()) {
            return $claimedPhone ?? throw new ApiException('請提供手機號碼', 'PHONE_REQUIRED', 422);
        }

        $phone = PhoneToken::resolve($request->header(self::HEADER));

        if ($phone === null) {
            throw new ApiException('請先完成手機驗證', 'PHONE_NOT_VERIFIED', 401);
        }

        // 憑證證明的是 A 手機，卻想用 B 手機的名義訂位
        if ($mustMatch && $claimedPhone !== null && $claimedPhone !== $phone) {
            throw new ApiException('驗證的手機號碼與填寫的不一致', 'PHONE_MISMATCH', 403);
        }

        return $phone;
    }

    /** 這個請求是否通過了簡訊驗證（寫入 phone_verified_at 用）。 */
    public function verified(Request $request): bool
    {
        return PhoneToken::resolve($request->header(self::HEADER)) !== null;
    }
}
