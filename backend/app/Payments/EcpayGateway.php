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

    /**
     * 綠界「信用卡請退款」API（CreditDetail/DoAction），Action=R（退刷）。
     *
     * 已對綠界測試環境驗證的部分：端點、欄位、簽章被接受（簽章正確 → 回「訂單不存在」；簽章錯誤 → CheckMacValue Error）。
     * ⚠️ 沒驗證過的部分：對「真的刷過卡且已關帳的交易」退款成功的完整路徑——需要一筆真實的測試交易，沒辦法自動化。
     * 另外綠界規定交易要「已關帳」才能 R（未關帳要用 E 取消授權）；這裡沒有處理未關帳的情況，會得到綠界的拒絕訊息並進入失敗清單由人工處理。
     */
    public function refund(Payment $payment, int $amount): RefundResult
    {
        if ($payment->ecpay_trade_no === null) {
            // 沒有綠界交易編號代表我們根本沒收到過付款成功的通知，不可能退款；這是我們自己的資料問題，不是「結果未知」
            return new RefundResult(false, '缺少綠界交易編號，無法退款');
        }

        $params = [
            'MerchantID' => $this->merchantId(),
            'MerchantTradeNo' => $payment->merchant_trade_no,
            'TradeNo' => $payment->ecpay_trade_no,
            'Action' => 'R',
            'TotalAmount' => (string) $amount,
        ];
        $params['CheckMacValue'] = $this->sign($params);

        try {
            // 不自動重試（retry）：退款是「改變金錢」的動作，逾時後重送可能退兩次。要不要再試由呼叫端依狀態決定
            $response = Http::asForm()->timeout((int) config('refund.timeout'))->post($this->endpoint('refund'), $params);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('退款請求沒有得到回應：'.$e->getMessage(), 0, $e);
        }

        if ($response->serverError()) {
            throw new PaymentGatewayException("綠界退款回應異常（HTTP {$response->status()}），結果未知");
        }

        parse_str($response->body(), $data);

        // 拿到了綠界的答覆（HTTP 200 + RtnCode）：1 = 成功，其餘都是明確的拒絕（含「訂單不存在」「已退款」「尚未關帳」…）
        if (! isset($data['RtnCode'])) {
            throw new PaymentGatewayException('綠界退款回應格式無法辨識，結果未知');
        }

        return new RefundResult(($data['RtnCode'] ?? null) === '1', (string) ($data['RtnMsg'] ?? ''));
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
