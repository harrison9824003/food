<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Payments\PaymentGateway;
use App\Payments\PaymentSettlement;
use App\Payments\SettlementOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * 綠界背景通知（ReturnURL）。訂單狀態以這裡為準，瀏覽器導回只負責顯示。
 *
 * 綠界的約定：處理成功要回應純文字「1|OK」，否則它會在一段時間內重送。
 * 所以「已經處理過」也要回 1|OK（冪等），否則綠界會一直重送。
 */
class EcpayNotifyController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentSettlement $settlement): Response
    {
        $log = Log::channel('payment');
        $payload = $request->post();

        // 通知原文先完整記下來。爭議、對帳、排錯時，這是最可靠的證據
        $log->info('ecpay notify received', ['payload' => $payload, 'ip' => $request->ip()]);

        // 先驗章：簽章不對的請求可能是偽造的「付款成功」，一律拒絕，不碰任何資料
        if (! $gateway->verifyCallback($payload)) {
            $log->warning('ecpay notify rejected: CheckMacValue mismatch', ['payload' => $payload, 'ip' => $request->ip()]);

            return $this->reply('0|CheckMacValue Error');
        }

        $tradeNo = (string) ($payload['MerchantTradeNo'] ?? '');
        // 本機模擬器會帶這個標頭，只用來在 confirmed_via 欄位區分來源，不影響任何判斷
        $via = $request->header('X-Payment-Simulator') ? 'simulator' : 'notify';

        $outcome = ($payload['RtnCode'] ?? null) === '1'
            ? $settlement->paid(
                $tradeNo,
                (int) ($payload['TradeAmt'] ?? 0),
                $payload['TradeNo'] ?? null,
                $payload['PaymentDate'] ?? null,
                $payload,
                $via,
            )
            : $settlement->failed($tradeNo, (string) ($payload['RtnMsg'] ?? 'payment failed'), $payload, $via);

        return $outcome->acknowledged() ? $this->reply('1|OK') : $this->reply('0|'.$this->failureText($outcome));
    }

    private function failureText(SettlementOutcome $outcome): string
    {
        return match ($outcome) {
            SettlementOutcome::NotFound => 'Trade not found',
            SettlementOutcome::AmountMismatch => 'Amount mismatch',
            default => 'Error',
        };
    }

    private function reply(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
