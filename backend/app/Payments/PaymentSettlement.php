<?php

namespace App\Payments;

use App\Booking\ReservationStatusService;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 把「一筆付款的結果」套用到付款紀錄與訂位上。
 *
 * 綠界背景通知、對帳排程、本機模擬器三條路徑都走這裡，所以：
 *  - 規則只寫一份，不會有「通知這樣處理、對帳那樣處理」的落差
 *  - 同一筆付款不論被通知幾次、通知與對帳是否同時到達，結果都一樣（冪等）
 */
class PaymentSettlement
{
    public function __construct(private readonly ReservationStatusService $statuses) {}

    /**
     * 付款成功。
     *
     * @param  array<string, mixed>  $raw  通知或查詢回應的原文，存起來供對帳與爭議時查證
     * @param  string  $via  notify | reconcile | simulator
     */
    public function paid(string $merchantTradeNo, int $amount, ?string $gatewayTradeNo, ?string $paidAt, array $raw, string $via): SettlementOutcome
    {
        $log = Log::channel('payment');

        return DB::transaction(function () use ($merchantTradeNo, $amount, $gatewayTradeNo, $paidAt, $raw, $via, $log) {
            $payment = Payment::query()->where('merchant_trade_no', $merchantTradeNo)->first();

            if ($payment === null) {
                $log->warning('payment result for unknown trade', ['merchant_trade_no' => $merchantTradeNo, 'via' => $via]);

                return SettlementOutcome::NotFound;
            }

            // 一律「先鎖訂位、再鎖付款」。逾時釋放排程是先鎖訂位再碰付款，兩邊順序一致才不會互相等待造成死鎖。
            $reservation = Reservation::query()->whereKey($payment->reservation_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // 冪等：綠界在沒收到 1|OK 時會一直重送，對帳排程也可能同時撿到這筆。
            // 已經是已付款就什麼都不做（仍然回覆 1|OK，否則綠界會繼續重送）。
            if ($payment->status === PaymentStatus::Paid) {
                $log->info('payment already processed, ignoring', ['merchant_trade_no' => $merchantTradeNo, 'via' => $via]);

                return SettlementOutcome::AlreadyProcessed;
            }

            // 金額必須和我們建立這筆付款時記錄的一致，不信任通知裡的金額欄位本身
            if ($payment->amount !== $amount) {
                $log->error('payment amount mismatch', [
                    'merchant_trade_no' => $merchantTradeNo, 'expected' => $payment->amount, 'received' => $amount, 'via' => $via,
                ]);

                return SettlementOutcome::AmountMismatch;
            }

            $payment->fill([
                'status' => PaymentStatus::Paid,
                'ecpay_trade_no' => $gatewayTradeNo,
                'paid_at' => $this->parsePaidAt($paidAt),
                'raw_notify' => $raw,
                'confirmed_via' => $via,
                'failure_reason' => null,
            ]);

            $outcome = $this->applyToReservation($reservation, $payment);
            $payment->save();

            $log->info('payment settled', [
                'merchant_trade_no' => $merchantTradeNo, 'reservation_id' => $reservation->id,
                'outcome' => $outcome->value, 'refund_reason' => $payment->refund_reason, 'via' => $via,
            ]);

            return $outcome;
        });
    }

    /**
     * 付款失敗（持卡人授權失敗、取消付款…）。訂位維持待付款，顧客在保留時間內可以再試。
     *
     * @param  array<string, mixed>  $raw
     */
    public function failed(string $merchantTradeNo, string $reason, array $raw, string $via): SettlementOutcome
    {
        $log = Log::channel('payment');

        return DB::transaction(function () use ($merchantTradeNo, $reason, $raw, $via, $log) {
            $payment = Payment::query()->where('merchant_trade_no', $merchantTradeNo)->first();

            if ($payment === null) {
                $log->warning('failed payment result for unknown trade', ['merchant_trade_no' => $merchantTradeNo, 'via' => $via]);

                return SettlementOutcome::NotFound;
            }

            $reservation = Reservation::query()->whereKey($payment->reservation_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // 已經成功或已記錄失敗：不要用失敗覆蓋成功（通知可能亂序到達）
            if ($payment->status !== PaymentStatus::Pending) {
                $log->info('failed result ignored, payment already settled', ['merchant_trade_no' => $merchantTradeNo, 'status' => $payment->status->value]);

                return SettlementOutcome::AlreadyProcessed;
            }

            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => mb_substr($reason, 0, 250), 'raw_notify' => $raw]);

            // 沒有其他進行中的付款時，訂位的付款狀態標為失敗，前台據此提示重試
            $otherPending = $reservation->payments()->where('status', PaymentStatus::Pending)->exists();
            if (! $otherPending && $reservation->payment_status !== PaymentStatus::Paid) {
                $reservation->update(['payment_status' => PaymentStatus::Failed]);
            }

            $log->info('payment failed', ['merchant_trade_no' => $merchantTradeNo, 'reason' => $reason, 'via' => $via]);

            return SettlementOutcome::Failed;
        });
    }

    /** 放棄一筆長時間沒有結果的付款，停止對帳。 */
    public function abandon(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $reservation = Reservation::query()->whereKey($payment->reservation_id)->lockForUpdate()->first();
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PaymentStatus::Pending) {
                return;
            }

            $locked->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'abandoned']);

            if ($reservation !== null
                && $reservation->payment_status === PaymentStatus::Pending
                && ! $reservation->payments()->where('status', PaymentStatus::Pending)->exists()) {
                $reservation->update(['payment_status' => PaymentStatus::Failed]);
            }

            Log::channel('payment')->info('payment abandoned', ['merchant_trade_no' => $locked->merchant_trade_no]);
        });
    }

    /**
     * 依訂位目前的狀態決定這筆已收到的錢怎麼處理。
     * 呼叫端已在交易內，並已鎖住訂位與付款。
     */
    private function applyToReservation(Reservation $reservation, Payment $payment): SettlementOutcome
    {
        // 這張訂位已經有另一筆付款成功了（例如顧客開了兩個分頁各付一次）：這筆是多收的
        if ($reservation->payment_status === PaymentStatus::Paid) {
            return $this->needsRefund($payment, 'DUPLICATE_PAYMENT');
        }

        switch ($reservation->status) {
            case ReservationStatus::PendingPayment:
                $this->statuses->confirmPayment($reservation);

                return SettlementOutcome::Confirmed;

            case ReservationStatus::Expired:
                // 先前逾時釋放了，這時才收到付款。能補回就補回（一樣用條件式原子更新，不會超賣）
                if ($this->statuses->reinstate($reservation)) {
                    return SettlementOutcome::Reinstated;
                }
                $reservation->update(['payment_status' => PaymentStatus::Paid]);

                return $this->needsRefund($payment, 'LATE_PAYMENT_SLOT_TAKEN');

            case ReservationStatus::Cancelled:
                $reservation->update(['payment_status' => PaymentStatus::Paid]);

                return $this->needsRefund($payment, 'PAID_AFTER_CANCEL');

            default:
                return $this->needsRefund($payment, 'UNEXPECTED_STATE');
        }
    }

    /** 綠界的付款時間格式是 2026/10/02 15:30:00；解析不了就用現在，不要因此讓整筆處理失敗。 */
    private function parsePaidAt(?string $paidAt): Carbon
    {
        if ($paidAt === null || $paidAt === '') {
            return now();
        }

        try {
            return Carbon::parse($paidAt);
        } catch (\Throwable) {
            return now();
        }
    }

    private function needsRefund(Payment $payment, string $reason): SettlementOutcome
    {
        $payment->fill(['needs_refund' => true, 'refund_reason' => $reason]);

        Log::channel('payment')->warning('payment received but reservation cannot be fulfilled, refund needed', [
            'merchant_trade_no' => $payment->merchant_trade_no, 'reason' => $reason,
        ]);

        return SettlementOutcome::NeedsRefund;
    }
}
