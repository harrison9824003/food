<?php

namespace App\Payments;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EcpayGateway implements PaymentGateway
{
    public function checkoutForm(Payment $payment, Reservation $reservation): array
    {
        $fields = [
            'MerchantID' => $this->merchantId(),
            'MerchantTradeNo' => $payment->merchant_trade_no,
            'MerchantTradeDate' => now()->format('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            'TotalAmount' => (string) $payment->amount,
            'TradeDesc' => 'Reservation deposit',
            'ItemName' => mb_substr("{$reservation->store->name} 訂金 {$reservation->party_size} 人", 0, 100),
            // 背景通知：訂單狀態以這個為準
            'ReturnURL' => config('ecpay.notify_url'),
            'ChoosePayment' => 'Credit',
            'EncryptType' => '1',
            // 瀏覽器導回：只負責顯示，不改任何狀態
            'OrderResultURL' => config('ecpay.order_result_url'),
            'ClientBackURL' => config('ecpay.frontend_url').'/payment/return?code='.$reservation->code,
            'NeedExtraPaidInfo' => 'N',
        ];

        $fields['CheckMacValue'] = $this->sign($fields);

        return ['action' => $this->endpoint('checkout'), 'fields' => $fields];
    }

    public function verifyCallback(array $payload): bool
    {
        return CheckMacValue::verify($this->stringify($payload), $this->hashKey(), $this->hashIv());
    }

    public function queryTrade(string $merchantTradeNo): TradeQueryResult
    {
        $params = [
            'MerchantID' => $this->merchantId(),
            'MerchantTradeNo' => $merchantTradeNo,
            'TimeStamp' => (string) now()->timestamp,
        ];
        $params['CheckMacValue'] = $this->sign($params);

        try {
            $response = Http::asForm()->timeout(10)->retry(2, 300, throw: false)->post($this->endpoint('query'), $params);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('無法連線到綠界：'.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new PaymentGatewayException("綠界查詢回應異常（HTTP {$response->status()}）");
        }

        parse_str($response->body(), $data);

        // 回應也要驗章：否則中間有人竄改回應，就能讓我們把沒付款的訂單當成已付款
        if (! CheckMacValue::verify($this->stringify($data), $this->hashKey(), $this->hashIv())) {
            Log::channel('payment')->warning('ecpay query response failed signature check', ['merchant_trade_no' => $merchantTradeNo]);
            throw new PaymentGatewayException('綠界查詢回應的簽章驗證失敗');
        }

        // TradeStatus：0 = 尚未付款，1 = 已付款；其餘（含交易不存在）都不能當成已付款
        $paid = ($data['TradeStatus'] ?? null) === '1';

        return new TradeQueryResult(
            paid: $paid,
            gatewayTradeNo: $data['TradeNo'] ?? null,
            amount: isset($data['TradeAmt']) ? (int) $data['TradeAmt'] : null,
            paidAt: $data['PaymentDate'] ?? null,
            raw: $data,
        );
    }

    public function signCallback(array $payload): array
    {
        $payload['CheckMacValue'] = $this->sign($payload);

        return $payload;
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function sign(array $params): string
    {
        return CheckMacValue::generate($params, $this->hashKey(), $this->hashIv());
    }

    /**
     * 通知與查詢回應的值一律是字串；轉成字串後再算簽章，避免型別造成編碼差異。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringify(array $data): array
    {
        return array_map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : '', $data);
    }

    private function endpoint(string $name): string
    {
        return config('ecpay.endpoints.'.config('ecpay.env').'.'.$name);
    }

    private function merchantId(): string
    {
        return (string) config('ecpay.merchant_id');
    }

    private function hashKey(): string
    {
        return (string) config('ecpay.hash_key');
    }

    private function hashIv(): string
    {
        return (string) config('ecpay.hash_iv');
    }
}
