<?php

namespace App\Payments;

use App\Models\Payment;
use App\Models\Reservation;

/**
 * 金流商介面。業務程式只依賴這個介面，測試時以 Http::fake() 或替身實作取代，不必真的連線。
 */
interface PaymentGateway
{
    /**
     * 組出要由瀏覽器 POST 給金流商的表單。
     *
     * @return array{action: string, fields: array<string, string>}
     */
    public function checkoutForm(Payment $payment, Reservation $reservation): array;

    /**
     * 驗證金流商背景通知的簽章。
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): bool;

    /**
     * 主動向金流商查詢交易結果（對帳補償用）。
     *
     * @throws PaymentGatewayException 查不到可信的結果
     */
    public function queryTrade(string $merchantTradeNo): TradeQueryResult;

    /**
     * 替一份通知內容簽章（本機付款模擬器用）。
     *
     * @param  array<string, scalar|null>  $payload
     * @return array<string, scalar|null>
     */
    public function signCallback(array $payload): array;
}
