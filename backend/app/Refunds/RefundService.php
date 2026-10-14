<?php

namespace App\Refunds;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Erp\OutboxRecorder;
use App\Exceptions\ApiException;
use App\Jobs\ProcessRefundJob;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * 退款流程：申請 → 簽核 → 呼叫金流商 → 回寫狀態。
 *
 *   requested ──核准──▶ approved ──金流商答覆成功──▶ completed
 *       │                  │  ▲
 *       │                  │  └──人工重試── failed ◀──金流商拒絕／重試用盡
 *       └──駁回──▶ rejected
 *
 * 鎖定順序一律「訂位 → 付款 → 退款」，和付款通知（先訂位、再付款）、逾時釋放一致，才不會彼此等待造成死鎖。
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OutboxRecorder $outbox,
    ) {}

    /**
     * 申請退款（全額）。必須在交易內呼叫（會同時記錄要通知 ERP 的事件）。
     *
     * 同一筆付款同時只能有一筆有效的退款：已經有就直接回傳它（冪等），
     * 即使兩個請求同時進來，refunds.active_payment_id 的 UNIQUE 也會擋下第二筆。
     *
     * @param  bool  $touchReservation  是否把訂位的付款狀態改成退款中。重複付款（多收的那一筆）時訂位本身仍是「已付款」，不能改
     */
    public function request(
        Reservation $reservation,
        Payment $payment,
        string $reason,
        string $requestedByType,
        ?User $actor = null,
        bool $touchReservation = true,
    ): Refund {
        // 先鎖付款再查有沒有進行中的退款：不鎖的話，兩個請求會同時看到「還沒有」而各建一筆
        $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        $existing = Refund::query()->where('payment_id', $locked->id)->whereNotNull('active_payment_id')->first();
        if ($existing !== null) {
            return $existing;
        }

        $refund = Refund::create([
            'payment_id' => $locked->id,
            'active_payment_id' => $locked->id,
            'reservation_id' => $reservation->id,
            'store_id' => $reservation->store_id,
            'amount' => $locked->amount, // 全額；沒有部分退款
            'reason' => $reason,
            'requested_by_type' => $requestedByType,
            'requested_by_id' => $actor?->id,
            'status' => RefundStatus::Requested,
        ]);

        $payment->status = PaymentStatus::Refunding;
        $payment->save();

        if ($touchReservation) {
            $reservation->update(['payment_status' => PaymentStatus::Refunding]);
        }

        $this->record($reservation, $refund);
        Log::channel('payment')->info('refund requested', [
            'refund_id' => $refund->id, 'reservation_id' => $reservation->id, 'merchant_trade_no' => $payment->merchant_trade_no,
            'amount' => $refund->amount, 'reason' => $reason, 'by' => $requestedByType,
        ]);

        return $refund;
    }

    /**
     * 核准。核准後由 ProcessRefundJob 向金流商退款。
     *
     * @throws ApiException
     */
    public function approve(int $refundId, User $actor): Refund
    {
        return DB::transaction(function () use ($refundId, $actor) {
            [$reservation, , $refund] = $this->lock($refundId);

            // 冪等：連點兩次「核准」，第二次什麼都不做
            if (in_array($refund->status, [RefundStatus::Approved, RefundStatus::Completed], true)) {
                return $refund;
            }

            $this->assertState($refund, RefundStatus::Requested);

            // 職責分離：後台人員申請的退款，不能由同一個人核准（防止一個人自己申請、自己放行）
            if ($refund->requested_by_type === 'staff' && $refund->requested_by_id === $actor->id) {
                throw new ApiException('不可核准自己申請的退款，請由其他主管簽核', 'SELF_APPROVAL', 403);
            }

            $refund->update(['status' => RefundStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'next_attempt_at' => null]);
            $this->record($reservation, $refund);

            Log::channel('payment')->info('refund approved', ['refund_id' => $refund->id, 'by_user_id' => $actor->id]);

            // 交易提交之後才排入佇列：否則 worker 可能在提交前就撿到、讀到還是「待簽核」
            ProcessRefundJob::dispatch($refund->id)->afterCommit();

            return $refund;
        });
    }

    /**
     * 駁回（不退款）。付款維持已付款，訂金歸門市。
     *
     * @throws ApiException
     */
    public function reject(int $refundId, User $actor, string $reason): Refund
    {
        return DB::transaction(function () use ($refundId, $actor, $reason) {
            [$reservation, $payment, $refund] = $this->lock($refundId);

            if ($refund->status === RefundStatus::Rejected) {
                return $refund;
            }

            $this->assertState($refund, RefundStatus::Requested);

            $refund->update([
                'status' => RefundStatus::Rejected,
                'active_payment_id' => null, // 釋出「同時只能一筆有效退款」的名額
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reject_reason' => $reason,
            ]);

            // 決定不退了：付款回到已付款，也不再算「待退款」
            $payment->update(['status' => PaymentStatus::Paid, 'needs_refund' => false]);
            if ($reservation->payment_status === PaymentStatus::Refunding) {
                $reservation->update(['payment_status' => PaymentStatus::Paid]);
            }

            $this->record($reservation, $refund);
            Log::channel('payment')->info('refund rejected', ['refund_id' => $refund->id, 'by_user_id' => $actor->id, 'reason' => $reason]);

            return $refund;
        });
    }

    /**
     * 人工重試失敗的退款。呼叫前應該已經到金流商後台確認過這筆確實沒退成功。
     *
     * @throws ApiException
     */
    public function retry(int $refundId, User $actor): Refund
    {
        return DB::transaction(function () use ($refundId, $actor) {
            [$reservation, , $refund] = $this->lock($refundId);

            if ($refund->status === RefundStatus::Approved) {
                return $refund;
            }

            $this->assertState($refund, RefundStatus::Failed);

            $refund->update(['status' => RefundStatus::Approved, 'attempts' => 0, 'next_attempt_at' => null]);
            $this->record($reservation, $refund);

            Log::channel('payment')->info('refund manually retried', ['refund_id' => $refund->id, 'by_user_id' => $actor->id]);
            ProcessRefundJob::dispatch($refund->id)->afterCommit();

            return $refund;
        });
    }

    /**
     * 向金流商退款。可以放心重複呼叫：只有「已核准且到了嘗試時間」的退款才會真的動作，其餘直接略過
     * （worker 重複領到、排程補撿、人工重試同時發生都是安全的）。
     *
     * 整段在持有鎖的交易裡呼叫金流商（最多等 15 秒）：這個系統退款量很小，換來的是「同一筆退款不可能同時被兩個行程送出去」，
     * 這比省下的鎖定時間重要得多——退款送兩次就是賠兩次錢。
     *
     * @return 'completed'|'failed'|'retry'|'skipped'
     */
    public function execute(int $refundId): string
    {
        return DB::transaction(function () use ($refundId) {
            [$reservation, $payment, $refund] = $this->lock($refundId);

            if ($refund->status !== RefundStatus::Approved) {
                return 'skipped';
            }
            if ($refund->next_attempt_at !== null && $refund->next_attempt_at->isFuture()) {
                return 'skipped';
            }

            $refund->attempts++;

            try {
                $result = $this->simulated($payment)
                    ? new \App\Payments\RefundResult(true, 'simulated refund (no gateway call)')
                    : $this->gateway->refund($payment, $refund->amount);
            } catch (PaymentGatewayException $e) {
                // 沒拿到金流商的答覆：可能已經退了，也可能沒有。不可以當成失敗而貿然重送。
                Log::channel('payment')->warning('refund result unknown', ['refund_id' => $refund->id, 'attempt' => $refund->attempts, 'error' => $e->getMessage()]);

                if ($refund->attempts >= config('refund.max_attempts')) {
                    // 自動重試到此為止：再送下去有重複退款的風險。標記失敗，請人工到金流商後台確認這筆有沒有退成功
                    return $this->markFailed($reservation, $refund, '多次無法取得金流商的回應，結果未知。請先到金流商後台確認這筆是否已退款，再決定要不要重試。');
                }

                $backoff = config('refund.retry_backoff');
                $wait = $backoff[min($refund->attempts, count($backoff)) - 1] ?? end($backoff);
                $refund->update(['attempts' => $refund->attempts, 'next_attempt_at' => now()->addSeconds($wait), 'failure_reason' => mb_substr($e->getMessage(), 0, 250)]);

                return 'retry';
            }

            if (! $result->success) {
                // 金流商明確拒絕（訂單不存在、已退款、尚未關帳…）：重試不會改變結果
                return $this->markFailed($reservation, $refund, "金流商拒絕退款：{$result->message}");
            }

            $refund->update([
                'status' => RefundStatus::Completed,
                'attempts' => $refund->attempts,
                'completed_at' => now(),
                'next_attempt_at' => null,
                'failure_reason' => null,
            ]);
            $payment->update(['status' => PaymentStatus::Refunded, 'needs_refund' => false]);
            if ($reservation->payment_status === PaymentStatus::Refunding) {
                $reservation->update(['payment_status' => PaymentStatus::Refunded]);
            }

            $this->record($reservation, $refund);
            Log::channel('payment')->info('refund completed', ['refund_id' => $refund->id, 'amount' => $refund->amount, 'gateway_message' => $result->message]);

            return 'completed';
        });
    }

    private function markFailed(Reservation $reservation, Refund $refund, string $reason): string
    {
        // 付款維持「退款中」：錢還沒退回去，後台的失敗清單要看得到
        $refund->update([
            'status' => RefundStatus::Failed,
            'attempts' => $refund->attempts,
            'next_attempt_at' => null,
            'failure_reason' => mb_substr($reason, 0, 250),
        ]);

        $this->record($reservation, $refund);
        Log::channel('payment')->error('refund failed', ['refund_id' => $refund->id, 'reason' => $reason]);

        return 'failed';
    }

    /**
     * 用「付款模擬器」產生的付款，綠界根本沒有這筆交易，真的去退只會得到「訂單不存在」。
     * 只在非正式環境、付款模擬器開啟時，這類付款的退款直接當成成功，讓整條流程可以在本機展示。
     */
    private function simulated(Payment $payment): bool
    {
        return config('refund.simulate_for_simulated_payments')
            && config('ecpay.simulator_enabled')
            && ! app()->isProduction()
            && $payment->confirmed_via === 'simulator';
    }

    /**
     * @return array{0: Reservation, 1: Payment, 2: Refund}
     */
    private function lock(int $refundId): array
    {
        $probe = Refund::query()->findOrFail($refundId);

        $reservation = Reservation::query()->whereKey($probe->reservation_id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($probe->payment_id)->lockForUpdate()->firstOrFail();
        $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();

        return [$reservation, $payment, $refund];
    }

    /** @throws ApiException */
    private function assertState(Refund $refund, RefundStatus $expected): void
    {
        if ($refund->status !== $expected) {
            throw new ApiException(
                "這筆退款目前是「{$refund->status->label()}」，不能執行這個動作",
                'INVALID_REFUND_STATE',
                409,
            );
        }
    }

    private function record(Reservation $reservation, Refund $refund): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('退款狀態的變更必須在資料庫交易內');
        }

        $this->outbox->record($reservation, OutboxRecorder::REFUND_CHANGED, [
            'refund' => ['id' => $refund->id, 'status' => $refund->status->value, 'amount' => $refund->amount, 'reason' => $refund->reason],
        ]);
    }
}
