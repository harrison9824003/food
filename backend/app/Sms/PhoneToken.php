<?php

namespace App\Sms;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * 通過簡訊驗證後換到的憑證：「這個瀏覽器證明過它持有這支手機」。
 *
 * 用 Laravel 的 Crypt（AES-256-GCM，含驗證標籤）包起來：內容無法被偽造或竄改，也不需要另外建資料表。
 * 憑證只有「手機號碼」與「到期時間」，沒有任何訂位資料；到期後就失效，要重新驗證。
 */
class PhoneToken
{
    public static function issue(string $phone): array
    {
        $expires = now()->addMinutes(config('sms.token_ttl_minutes'));

        return [
            'token' => Crypt::encryptString(json_encode(['p' => $phone, 'e' => $expires->timestamp])),
            'expires_at' => $expires,
        ];
    }

    /** @return string|null 手機號碼；憑證無效或已過期回傳 null */
    public static function resolve(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ! isset($data['p'], $data['e']) || $data['e'] < now()->timestamp) {
            return null;
        }

        return (string) $data['p'];
    }
}
