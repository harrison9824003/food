<?php

namespace App\Http\Controllers\Dev;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SimulatePaymentRequest;
use App\Models\Payment;
use App\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * 本機付款模擬器。
 *
 * 綠界打不到 localhost，所以本機開發時沒辦法收到真正的背景通知。這支 API 在伺服器端組一份簽好章的通知，
 * 再「真的 POST 到我們自己的 notify 網址」，所以驗章、冪等、狀態轉換、日誌全部走和真實通知一模一樣的路徑。
 *
 * 只在非正式環境、且明確開啟（ECPAY_SIMULATOR=true）時可用；否則等同不存在。
 */
class EcpaySimulatorController extends Controller
{
    public function __invoke(SimulatePaymentRequest $request, PaymentGateway $gateway): JsonResponse
    {
        if (! config('ecpay.simulator_enabled') || app()->isProduction()) {
            throw new ApiException('找不到資源', 'NOT_FOUND', 404);
        }

        $payment = Payment::query()->where('merchant_trade_no', $request->validated('merchant_trade_no'))->first()
            ?? throw new ApiException('找不到此付款', 'PAYMENT_NOT_FOUND', 404);

        $paid = $request->validated('outcome') === 'paid';

        $payload = $gateway->signCallback([
            'MerchantID' => (string) config('ecpay.merchant_id'),
            'MerchantTradeNo' => $payment->merchant_trade_no,
            'StoreID' => '',
            'RtnCode' => $paid ? '1' : '10100058',
            'RtnMsg' => $paid ? '交易成功' : '付款失敗(模擬)',
            // 綠界交易編號每筆付款固定，所以「重送」的通知內容會完全相同，和真實的重送一致
            'TradeNo' => 'SIM'.str_pad((string) $payment->id, 17, '0', STR_PAD_LEFT),
            'TradeAmt' => (string) $payment->amount,
            'PaymentDate' => now()->format('Y/m/d H:i:s'),
            'PaymentType' => 'Credit_CreditCard',
            'PaymentTypeChargeFee' => '0',
            'TradeDate' => $payment->created_at->format('Y/m/d H:i:s'),
            'SimulatePaid' => '1',
        ]);

        $results = [];
        foreach (range(1, (int) $request->validated('times', 1)) as $attempt) {
            $response = Http::asForm()
                ->withHeaders(['X-Payment-Simulator' => '1'])
                ->timeout(15)
                ->post(config('ecpay.simulator_notify_url'), $payload);

            $results[] = ['attempt' => $attempt, 'http_status' => $response->status(), 'response' => $response->body()];
        }

        return response()->json(['results' => $results]);
    }
}
