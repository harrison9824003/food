<?php

namespace App\Payments;

use App\Booking\ReservationStatusService;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Models\Reservation;
use Illuminate\Support\Facades\Log;

/**
 * 釋放逾時未付款的暫時保留，把名額與桌位還給其他人。
 */
class HoldReleaseService
{
    public function __construct(
        private readonly PaymentReconciler $reconciler,
        private readonly ReservationStatusService $statuses,
    ) {}

    /**
     * @return array{released: int, paid_meanwhile: int, skipped: int}
     */
    public function releaseExpired(int $limit = 200): array
    {
        // 保留時間到了還要再等一段寬限：顧客可能剛好正在綠界頁面輸入卡號
        $cutoff = now()->subMinutes(config('ecpay.grace_minutes'));

        $candidates = Reservation::query()
            ->where('status', ReservationStatus::PendingPayment)
            ->where('hold_expires_at', '<', $cutoff)
            ->orderBy('hold_expires_at')
            ->limit($limit)
            ->get();

        $summary = ['released' => 0, 'paid_meanwhile' => 0, 'skipped' => 0];

        foreach ($candidates as $reservation) {
            // 釋放之前先向綠界確認：通知可能漏接，不能把其實已經付款的人的座位放掉
            $unverified = false;
            foreach ($reservation->payments()->where('status', PaymentStatus::Pending)->get() as $payment) {
                if ($this->reconciler->reconcile($payment) === PaymentReconciler::UNKNOWN) {
                    $unverified = true;
                }
            }

            $reservation->refresh();

            if ($reservation->status !== ReservationStatus::PendingPayment) {
                $summary['paid_meanwhile']++;

                continue;
            }

            // 問不到綠界（可能故障）：先不釋放，下一輪再試。但不能無限等下去，
            // 否則綠界出問題時名額會永遠被占住。超過上限就照樣釋放，晚到的付款由補救流程處理。
            $patience = $reservation->hold_expires_at->copy()->addMinutes(config('ecpay.max_unverified_hold_minutes'));
            if ($unverified && now()->lt($patience)) {
                $summary['skipped']++;

                continue;
            }

            try {
                $this->statuses->transition($reservation, ReservationStatus::Expired);
                $summary['released']++;
                Log::channel('payment')->info('hold expired, reservation released', [
                    'reservation_id' => $reservation->id, 'unverified' => $unverified,
                ]);
            } catch (ApiException $e) {
                // 在我們檢查之後狀態又變了（例如顧客剛好取消），沒關係，下一輪不會再選到它
                Log::info('expire skipped', ['reservation_id' => $reservation->id, 'reason' => $e->errorCode]);
            }
        }

        return $summary;
    }
}
