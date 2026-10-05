<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * 為待付款的訂位建立一筆付款，回傳要由瀏覽器 POST 給金流商的表單。
     *
     * 每次呼叫都建立新的付款（新的商店交易編號）：金流商不接受重複的商店交易編號，
     * 顧客付款失敗後重試、或重新整理頁面，都需要一組新的編號。
     * 如果前一筆其實付成功了，後到的那筆會被當成重複付款標記待退款（見 PaymentSettlement）。
     *
     * @return array{action: string, fields: array<string, string>, merchant_trade_no: string}
     *
     * @throws ApiException
     */
    public function checkout(Reservation $reservation): array
    {
        return DB::transaction(function () use ($reservation) {
            // 鎖住訂位再檢查：避免「剛好逾時被釋放」與「建立付款」同時進行
            $locked = Reservation::with('store')->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReservationStatus::PendingPayment || $locked->deposit_amount <= 0) {
                throw new ApiException('此訂位目前不需要付款', 'NOT_PAYABLE', 409);
            }

            if ($locked->hold_expires_at === null || $locked->hold_expires_at->lte(now())) {
                throw new ApiException('保留時間已過，請重新訂位', 'HOLD_EXPIRED', 409);
            }

            $payment = Payment::create([
                'reservation_id' => $locked->id,
                'merchant_trade_no' => $this->generateTradeNo($locked),
                'amount' => $locked->deposit_amount,
                'status' => PaymentStatus::Pending,
            ]);

            $locked->update(['payment_status' => PaymentStatus::Pending]);

            Log::channel('payment')->info('checkout created', [
                'reservation_id' => $locked->id, 'merchant_trade_no' => $payment->merchant_trade_no, 'amount' => $payment->amount,
            ]);

            return $this->gateway->checkoutForm($payment, $locked) + ['merchant_trade_no' => $payment->merchant_trade_no];
        });
    }

    /**
     * 綠界的商店交易編號：英數字、最長 20 字元、不可重複。
     * 以訂位 ID 開頭方便人工對帳時從編號反查，其餘補隨機字元。
     */
    private function generateTradeNo(Reservation $reservation): string
    {
        $prefix = 'F'.$reservation->id.'T';

        return $prefix.strtoupper(Str::random(20 - strlen($prefix)));
    }
}
