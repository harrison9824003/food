<?php

namespace Tests\Concerns;

use App\Models\Store;
use Illuminate\Support\Carbon;

/**
 * 門市設定相關測試的共用工具。
 * 固定「今天」為 2026-10-05（週一），讓 advance_days、星期幾的計算結果可預期。
 */
trait CreatesStoreSchedule
{
    protected function freezeToday(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
    }

    /** 週日到週六全部營業，並補上 7 天的營業時間設定。 */
    protected function openAllWeek(Store $store, string $open = '11:00', string $close = '13:00', int $step = 30): void
    {
        foreach (range(0, 6) as $weekday) {
            $store->businessHours()->updateOrCreate(['weekday' => $weekday], [
                'is_closed' => false,
                'open_time' => $open,
                'close_time' => $close,
                'slot_minutes' => $step,
            ]);
        }
    }

    /** 送給 PUT business-hours 的 7 天 payload。 */
    protected function hoursPayload(string $open = '11:00', string $close = '13:00', int $step = 30, array $closedWeekdays = []): array
    {
        return ['hours' => collect(range(0, 6))->map(fn (int $weekday) => in_array($weekday, $closedWeekdays, true)
            ? ['weekday' => $weekday, 'is_closed' => true]
            : ['weekday' => $weekday, 'is_closed' => false, 'open_time' => $open, 'close_time' => $close, 'slot_minutes' => $step]
        )->all()];
    }
}
