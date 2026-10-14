<?php

namespace App\Refunds;

use App\Booking\ReservationStatusService;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 取消訂位，並依政策決定訂金要不要退。
 *
 * 取消本身（歸還名額與桌位）與「建立退款申請」寫在同一個交易：
 * 不會出現「訂位取消了、座位放掉了，卻沒有退款申請」，也不會「建立了退款申請，訂位卻沒取消成功」。
 */
class CancellationService
{
    public function __construct(
        private readonly ReservationStatusService $statuses,
        private readonly RefundService $refunds,
    ) {}

    /**
     * 顧客自己取消。
     *
     * 用餐前期限內取消 → 全額退訂金（建立退款申請，仍要主管簽核）。
     * 超過期限 → 不退、訂金沒收。這是顧客吃虧的操作，所以必須明確確認（$acknowledgeForfeit），
     * 否則回 409 FORFEIT_CONFIRMATION_REQUIRED，由前台跳出確認視窗；不能因為手滑或舊畫面就默默沒收。
     *
     * @throws ApiException
     */
    public function cancelByCustomer(Reservation $reservation, bool $acknowledgeForfeit): Reservation
    {
        return DB::transaction(function () use ($reservation, $acknowledgeForfeit) {
            $locked = $this->lock($reservation);

            // 冪等：已經取消了就直接回傳（連點、重送都不會重複建立退款）
            if ($locked->status === ReservationStatus::Cancelled) {
                return $locked;
            }

            // 狀態根本不允許取消（已到、未到…）時，交給狀態機丟出正確的錯誤，而不是先要顧客確認沒收訂金
            if (! in_array(ReservationStatus::Cancelled, $locked->status->allowedTransitions(), true)) {
                return $this->statuses->transition($locked, ReservationStatus::Cancelled);
            }

            $payment = $this->paidPayment($locked);
            $refundable = true;

            if ($payment !== null) {
                $refundable = CancellationPolicy::decide($locked)['refundable'];

                if (! $refundable && ! $acknowledgeForfeit) {
                    $decision = CancellationPolicy::decide($locked);

                    throw new ApiException(
                        "已超過免費取消期限（{$decision['free_cancellation_until']->format('Y-m-d H:i')}），取消後訂金 \${$payment->amount} 不予退還",
                        'FORFEIT_CONFIRMATION_REQUIRED',
                        409,
                        ['deposit' => $payment->amount, 'free_cancellation_until' => $decision['free_cancellation_until']->toIso8601String()],
                    );
                }
            }

            $this->statuses->transition($locked, ReservationStatus::Cancelled, refundHandled: true);

            if ($payment !== null && $refundable) {
                $this->refunds->request($locked, $payment, Refund::CUSTOMER_CANCEL, 'customer');
            } elseif ($payment !== null) {
                Log::channel('payment')->info('deposit forfeited by late cancellation', [
                    'reservation_id' => $locked->id, 'merchant_trade_no' => $payment->merchant_trade_no, 'amount' => $payment->amount,
                ]);
            }

            return $locked->refresh();
        });
    }

    /**
     * 後台人員代為取消。是店方的原因（公休、客訴…），不論距離用餐多久都全額退，不看政策。
     * 退款申請記下是誰申請的，簽核時必須由另一位主管核准。
     *
     * @throws ApiException
     */
    public function cancelByStore(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor) {
            $locked = $this->lock($reservation);

            if ($locked->status === ReservationStatus::Cancelled) {
                return $locked;
            }

            $payment = $this->paidPayment($locked);

            $this->statuses->transition($locked, ReservationStatus::Cancelled, refundHandled: true);

            if ($payment !== null) {
                $this->refunds->request($locked, $payment, Refund::STORE_CANCEL, 'staff', $actor);
            }

            return $locked->refresh();
        });
    }

    /** 這張訂位已收到、且還沒進入退款流程的那筆訂金。 */
    private function paidPayment(Reservation $reservation): ?Payment
    {
        if ($reservation->payment_status !== PaymentStatus::Paid) {
            return null;
        }

        return $reservation->payments()
            ->where('status', PaymentStatus::Paid)
            // 被標記待退款的（多收、晚到）已經有自己的退款申請
            ->where('needs_refund', false)
            ->orderBy('id')
            ->first();
    }

    private function lock(Reservation $reservation): Reservation
    {
        // 先鎖訂位再碰付款，順序與付款通知、逾時釋放一致
        return Reservation::query()->with('slot')->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
    }
}
