<?php

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * 對帳補償：背景通知可能因為網路、我方當機、被擋而沒有到達，所以排程會主動向綠界查詢。
 * 查到已付款就走和背景通知「同一個」PaymentSettlement，所以通知晚到或重送都不會重複處理。
 */
class PaymentReconciler
{
    public const PAID = 'paid';

    public const UNPAID = 'unpaid';

    public const ABANDONED = 'abandoned';

    /** 查不到可信的結果（綠界故障、回應被竄改…）：不知道有沒有付款，不能當成沒付款 */
    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentSettlement $settlement,
    ) {}

    public function reconcile(Payment $payment): string
    {
        try {
            $result = $this->gateway->queryTrade($payment->merchant_trade_no);
        } catch (PaymentGatewayException $e) {
            Log::channel('payment')->warning('reconcile query failed', [
                'merchant_trade_no' => $payment->merchant_trade_no, 'error' => $e->getMessage(),
            ]);

            return self::UNKNOWN;
        }

        if ($result->paid) {
            // 沒有金額就無法核對，寧可標記未知也不要在不確定的情況下把訂位確認
            if ($result->amount === null) {
                return self::UNKNOWN;
            }

            $outcome = $this->settlement->paid(
                $payment->merchant_trade_no, $result->amount, $result->gatewayTradeNo, $result->paidAt, $result->raw, 'reconcile',
            );

            // 金額對不上等情況沒有被處理，不能回報成「已補單」，否則會讓人以為問題解決了
            return $outcome->acknowledged() ? self::PAID : self::UNKNOWN;
        }

        if ($payment->created_at->lte(now()->subMinutes(config('ecpay.abandon_after_minutes')))) {
            $this->settlement->abandon($payment);

            return self::ABANDONED;
        }

        return self::UNPAID;
    }

    /**
     * 對帳所有「建立了一陣子卻還沒有結果」的付款。
     *
     * @return array{checked: int, paid: int, abandoned: int, unknown: int}
     */
    public function reconcilePending(int $limit = 100): array
    {
        $payments = Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes(config('ecpay.reconcile_after_minutes')))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = ['checked' => 0, 'paid' => 0, 'abandoned' => 0, 'unknown' => 0];

        foreach ($payments as $payment) {
            $summary['checked']++;

            match ($this->reconcile($payment)) {
                self::PAID => $summary['paid']++,
                self::ABANDONED => $summary['abandoned']++,
                self::UNKNOWN => $summary['unknown']++,
                default => null,
            };
        }

        return $summary;
    }
}
