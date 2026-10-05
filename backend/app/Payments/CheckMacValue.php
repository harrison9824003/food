<?php

namespace App\Payments;

/**
 * 綠界 CheckMacValue（SHA256）的產生與驗證。
 *
 * 演算法：
 *  1. 排除 CheckMacValue 本身，參數依名稱 A–Z 排序（不分大小寫）
 *  2. 組成 HashKey=...&參數1=值1&參數2=值2...&HashIV=...
 *  3. 整串做 URL encode，轉小寫
 *  4. 把 .NET 的 UrlEncode 不會編碼的字元還原（綠界的伺服器是 .NET，兩邊必須一致）
 *  5. SHA256，轉大寫
 */
final class CheckMacValue
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function generate(array $params, string $hashKey, string $hashIv): string
    {
        unset($params['CheckMacValue']);

        uksort($params, 'strcasecmp');

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $raw = 'HashKey='.$hashKey.'&'.implode('&', $pairs).'&HashIV='.$hashIv;

        $encoded = strtolower(urlencode($raw));

        // PHP 的 urlencode 會編碼這幾個字元，但 .NET 的 HttpUtility.UrlEncode 不會，所以要還原才能對得上綠界
        $encoded = str_replace(
            ['%2d', '%5f', '%2e', '%21', '%2a', '%28', '%29'],
            ['-', '_', '.', '!', '*', '(', ')'],
            $encoded,
        );

        return strtoupper(hash('sha256', $encoded));
    }

    /**
     * @param  array<string, scalar|null>  $params  含 CheckMacValue 的完整參數
     */
    public static function verify(array $params, string $hashKey, string $hashIv): bool
    {
        $received = $params['CheckMacValue'] ?? null;

        if (! is_string($received) || $received === '') {
            return false;
        }

        // hash_equals：固定時間比較，不會因為「前幾個字元相同就比較久」而讓攻擊者逐字猜出簽章
        return hash_equals(self::generate($params, $hashKey, $hashIv), strtoupper($received));
    }
}
