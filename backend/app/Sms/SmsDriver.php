<?php

namespace App\Sms;

/**
 * 簡訊廠商介面。業務程式只依賴它，測試以 Http::fake() 或替身取代。
 */
interface SmsDriver
{
    /**
     * @throws SmsException 廠商拒絕或無法連線；呼叫端（SendSmsJob）會依退避策略重試
     */
    public function send(string $phone, string $message): void;
}
