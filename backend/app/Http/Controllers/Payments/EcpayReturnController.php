<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 顧客在綠界付完款後，瀏覽器被導回這裡（OrderResultURL）。
 *
 * 只負責把人轉去前台的結果頁，「不改任何狀態」：這個請求來自瀏覽器，可以被偽造或根本不會發生（顧客關掉分頁），
 * 付款是否成功一律以背景通知為準。前台結果頁也是向我們的 API 查訂位狀態，而不是相信這裡帶的任何參數。
 */
class EcpayReturnController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $frontend = config('ecpay.frontend_url');

        $tradeNo = (string) $request->input('MerchantTradeNo', '');

        // 訂位代號從我們自己的資料庫查，不使用請求裡帶來的任何網址或代號，避免被拿來做開放式重導向
        $code = $tradeNo === ''
            ? null
            : Payment::query()->where('merchant_trade_no', $tradeNo)->first()?->reservation()->value('code');

        return redirect()->away($code ? "{$frontend}/payment/return?code={$code}" : "{$frontend}/lookup");
    }
}
