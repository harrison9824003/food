<?php

namespace Tests\Concerns;

use App\Booking\ReservationService;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\CheckMacValue;
use App\Payments\PaymentGateway;
use App\Payments\PaymentService;

/**
 * 金流測試的共用場景：每人訂金 $200 的門市，訂位後是待付款狀態。
 * 「今天」固定為 2026-10-05 10:00，所以訂位的保留到期時間是 10:10。
 */
trait CreatesPayments
{
    use CreatesBookableStore;

    protected function setUpPayments(): void
    {
        $this->setUpBookable(['deposit_per_person' => 200]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeReservation(array $overrides = []): Reservation
    {
        return app(ReservationService::class)->create($this->bookingPayload($overrides));
    }

    /** 為訂位建立一筆付款（等同顧客按下「前往付款」）。 */
    protected function startPayment(Reservation $reservation): Payment
    {
        app(PaymentService::class)->checkout($reservation);

        return $reservation->payments()->latest('id')->firstOrFail();
    }

    /**
     * 綠界背景通知的內容，已簽章。
     *
     * @param  array<string, string>  $overrides  會在簽章「之前」套用，所以簽章永遠和內容一致
     * @return array<string, scalar|null>
     */
    protected function notifyPayload(Payment $payment, array $overrides = []): array
    {
        return app(PaymentGateway::class)->signCallback(array_merge([
            'MerchantID' => '2000132',
            'MerchantTradeNo' => $payment->merchant_trade_no,
            'StoreID' => '',
            'RtnCode' => '1',
            'RtnMsg' => '交易成功',
            'TradeNo' => '2610021530'.str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT),
            'TradeAmt' => (string) $payment->amount,
            'PaymentDate' => '2026/10/05 10:03:00',
            'PaymentType' => 'Credit_CreditCard',
            'PaymentTypeChargeFee' => '0',
            'TradeDate' => '2026/10/05 10:01:00',
            'SimulatePaid' => '0',
        ], $overrides));
    }

    /** @param  array<string, scalar|null>  $payload */
    protected function sendNotify(array $payload)
    {
        // 綠界是用 application/x-www-form-urlencoded 打過來的，不是 JSON
        return $this->post('/api/payments/ecpay/notify', $payload);
    }

    /**
     * 綠界 QueryTradeInfo 的回應本文（urlencoded，含 CheckMacValue）。
     *
     * @param  array<string, string>  $overrides
     */
    protected function ecpayQueryResponse(Payment $payment, bool $paid, array $overrides = [], bool $sign = true): string
    {
        $data = array_merge([
            'HandlingCharge' => '0',
            'ItemName' => 'deposit',
            'MerchantID' => '2000132',
            'MerchantTradeNo' => $payment->merchant_trade_no,
            'PaymentDate' => $paid ? '2026/10/05 10:03:00' : '',
            'PaymentType' => $paid ? 'Credit_CreditCard' : '',
            'PaymentTypeChargeFee' => '0',
            'TradeAmt' => (string) $payment->amount,
            'TradeDate' => '2026/10/05 10:01:00',
            'TradeNo' => $paid ? '2610051003'.str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT) : '',
            'TradeStatus' => $paid ? '1' : '0',
        ], $overrides);

        if ($sign) {
            $data['CheckMacValue'] = CheckMacValue::generate($data, config('ecpay.hash_key'), config('ecpay.hash_iv'));
        }

        return http_build_query($data);
    }
}
