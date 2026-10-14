<?php

namespace Tests\Concerns;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Reservation;
use Illuminate\Support\Facades\Http;

/**
 * 退款測試的共用場景：每人訂金 $200 的門市，用餐時間是 2026-10-06 12:00，「現在」是 2026-10-05 10:00（距離用餐 26 小時）。
 * 免費取消的期限是 2026-10-05 12:00（用餐前 24 小時）。
 */
trait CreatesRefunds
{
    use CreatesPayments;

    private const DO_ACTION = 'https://payment-stage.ecpay.com.tw/CreditDetail/DoAction';

    /** @var mixed Http::response() 或接收請求的 closure；用 refundApiReturns() 切換 */
    private mixed $refundApiNext = null;

    protected function setUpRefunds(): void
    {
        $this->setUpPayments();

        // Http::fake() 在同一個測試裡再呼叫不會覆蓋先前的設定，所以只註冊一次，之後用 refundApiReturns() 切換回應
        Http::fake([self::DO_ACTION => function ($request) {
            return is_callable($this->refundApiNext) ? ($this->refundApiNext)($request) : $this->refundApiNext;
        }]);
        $this->refundApiReturns(1, 'OK');
    }

    /** 讓綠界的退款 API 回應 RtnCode（1 = 成功，其餘 = 明確拒絕）。 */
    protected function refundApiReturns(int $rtnCode, string $message): void
    {
        $this->refundApiNext = Http::response(http_build_query(['MerchantID' => '2000132', 'RtnCode' => $rtnCode, 'RtnMsg' => $message]));
    }

    /** 讓綠界的退款 API 沒有回應（5xx、逾時）——結果未知。 */
    protected function refundApiIsUnreachable(): void
    {
        $this->refundApiNext = Http::response('gateway error', 502);
    }

    protected function refundApiCalls(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === self::DO_ACTION)->count();
    }

    /** 已付訂金、已確認的訂位（走完整的付款通知流程）。 */
    protected function paidReservation(array $overrides = []): Reservation
    {
        $reservation = $this->makeReservation($overrides);
        $payment = $this->startPayment($reservation);
        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        return $reservation->fresh();
    }

    protected function paymentOf(Reservation $reservation): Payment
    {
        return $reservation->payments()->latest('id')->firstOrFail();
    }

    protected function refundOf(Reservation $reservation): Refund
    {
        return Refund::where('reservation_id', $reservation->id)->latest('id')->firstOrFail();
    }

    /** 顧客取消（寬鬆模式：直接用請求裡的手機號碼）。 */
    protected function customerCancels(Reservation $reservation, bool $acknowledgeForfeit = false)
    {
        return $this->postJson('/api/reservations/cancel', array_filter([
            'code' => $reservation->code,
            'phone' => $reservation->phone,
            'acknowledge_forfeit' => $acknowledgeForfeit ?: null,
        ]));
    }
}
