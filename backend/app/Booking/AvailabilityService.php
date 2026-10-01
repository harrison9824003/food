<?php

namespace App\Booking;

use App\Models\Slot;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class AvailabilityService
{
    public function __construct(
        private readonly BookingRules $rules,
        private readonly TableAllocator $tables,
    ) {}

    /**
     * 某天各時段的剩餘量與是否可訂。
     *
     * @return list<array{id: int, start_time: string, remaining: int, bookable: bool, reason: string|null}>
     */
    public function forDate(Store $store, string $date, int $guests): array
    {
        $slots = Slot::query()->where('store_id', $store->id)->where('slot_date', $date)->orderBy('start_time')->get();

        $closed = $this->rules->isDateClosed($store->id, $date);
        $canSeat = $this->tables->canSeatMany($store, $slots, $guests);

        return $slots->map(function (Slot $slot) use ($store, $guests, $closed, $canSeat) {
            // 名額還夠、時間也對，但剩下的桌子湊不出來（例如都是 2 人桌而來了 6 個人）也不能訂
            $block = $this->rules->blockReason($store, $slot, $guests, $closed)
                ?? (($canSeat[$slot->id] ?? false) ? null : new BookingBlock('NO_TABLE_AVAILABLE', '沒有適合的桌位'));

            return [
                'id' => $slot->id,
                'start_time' => substr($slot->start_time, 0, 5),
                'remaining' => $slot->remaining(),
                'bookable' => $block === null,
                'reason' => $block?->code,
            ];
        })->all();
    }

    /**
     * 開放期間內每天是否營業，供日期選擇器使用（只看營業時間與公休日，不看名額）。
     *
     * @return array{from: string, to: string, dates: list<array{date: string, open: bool}>}
     */
    public function calendar(Store $store): array
    {
        $from = today();
        $to = today()->addDays($store->advance_days);

        $hours = $store->businessHours()->get()->keyBy('weekday');
        $closed = DB::table('store_closed_dates')
            ->where('store_id', $store->id)
            ->whereBetween('closed_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('closed_date')
            ->flip();

        $dates = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $hour = $hours->get($day->dayOfWeek);
            $dates[] = [
                'date' => $day->toDateString(),
                'open' => $hour !== null && ! $hour->is_closed && ! $closed->has($day->toDateString()),
            ];
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'dates' => $dates];
    }
}
