<?php

namespace App\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 互動資通 Every8d 的 HTTP 簡訊 API。
 *
 * ⚠️ 未對真實服務驗證：我沒有 Every8d 帳號，這裡依官方文件的格式實作，
 * 並以 Http::fake() 測試「我們送出的請求格式」與「各種回應的處理」，沒有實際發出過簡訊。
 * 回應格式（成功）："餘額,發送則數,費用,未發送則數,批次代碼"；失敗以 "-" 開頭加錯誤碼。
 */
class Every8dDriver implements SmsDriver
{
    public function send(string $phone, string $message): void
    {
        $config = config('sms.every8d');

        if (! $config['uid'] || ! $config['pwd']) {
            throw new SmsException('Every8d 帳號尚未設定（EVERY8D_UID / EVERY8D_PWD）');
        }

        try {
            $response = Http::asForm()->timeout($config['timeout'])->post($config['url'], [
                'UID' => $config['uid'],
                'PWD' => $config['pwd'],
                'SB' => '',
                'MSG' => $message,
                'DEST' => $phone,
                'ST' => '',
            ]);
        } catch (ConnectionException $e) {
            throw new SmsException('無法連線到簡訊廠商：'.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new SmsException("簡訊廠商回應異常（HTTP {$response->status()}）");
        }

        $body = trim($response->body());

        // 失敗：-300 (帳號密碼錯誤)、-100 (餘額不足) …；錯誤碼不含任何個資，可以記錄
        if ($body === '' || str_starts_with($body, '-')) {
            throw new SmsException("簡訊廠商拒絕發送：{$body}");
        }

        $parts = explode(',', $body);
        // 第 4 欄是「未發送則數」，大於 0 代表這則沒有送出去
        if (isset($parts[3]) && (float) $parts[3] > 0) {
            throw new SmsException("簡訊廠商回報未發送：{$body}");
        }
    }
}
