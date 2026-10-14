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
     * 退款（信用卡：對已付款的交易退回 $amount 元）。
     *
     * @return RefundResult 金流商的明確答覆（成功或拒絕）
     *
     * @throws PaymentGatewayException 沒拿到答覆（逾時、連線中斷、5xx）。結果未知：可能已經退了，也可能沒有
     */
    public function refund(Payment $payment, int $amount): RefundResult;

    /**
     * 替一份通知內容簽章（本機付款模擬器用）。
     *
     * @param  array<string, scalar|null>  $payload
     * @return array<string, scalar|null>
     */
    public function signCallback(array $payload): array;
}
