<?php

namespace App\Services;

use App\Models\Slot;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 依營業時間、公休日與 advance_days，把門市未來的可訂時段展開成 slots 列。
 *
 * sync() 是冪等的，可放心重複執行（每日排程、儲存設定後、手動觸發）：
 *  - 缺的時段用 INSERT IGNORE 補上，靠 UNIQUE(store_id, slot_date, start_time) 防重複
 *  - 已存在的時段一律不改，不會蓋掉管理員手動調整的名額，更不會動到已訂位的 booked
 *  - 不再屬於營業時間的舊時段只有在「沒有任何人訂過」時才會刪除
 */
class SlotGenerator
{
    /**
     * @return array{created: int, deleted: int}
     */
    public function sync(Store $store): array
    {
        if (! $store->is_active) {
            return ['created' => 0, 'deleted' => 0];
        }

        $desired = $this->desiredSlots($store);

        return DB::transaction(function () use ($store, $desired) {
            $existing = Slot::query()
                ->where('store_id', $store->id)
                ->where('slot_date', '>=', today()->toDateString())
                ->get(['id', 'slot_date', 'start_time', 'booked']);

            $existingKeys = [];
            $staleIds = [];
            foreach ($existing as $slot) {
                $key = $this->key($slot->slot_date->toDateString(), $slot->start_time);
                $existingKeys[$key] = true;

                if (! isset($desired[$key]) && $slot->booked === 0) {
                    $staleIds[] = $slot->id;
                }
            }

            $deleted = $this->deleteUnused($staleIds);

            $now = now();
            $rows = [];
            foreach ($desired as $key => $row) {
                if (! isset($existingKeys[$key])) {
                    $rows[] = $row + ['store_id' => $store->id, 'booked' => 0, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            $created = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                $created += Slot::query()->insertOrIgnore($chunk);
            }

            return ['created' => $created, 'deleted' => $deleted];
        });
    }

    /**
     * 刪除沒人訂過的時段。
     *
     * 只看 booked = 0 還不夠：曾經有人訂、後來取消的時段 booked 也是 0，但 reservations 仍以外鍵指向它，
     * 硬刪會違反外鍵（也會弄丟訂位歷史）。所以額外要求「沒有任何訂位列參照它」。
     * 條件放在 DELETE 本身而非先查再刪，避免查到刪除之間剛好有人訂走。
     *
     * @param  list<int>  $ids
     */
    public function deleteUnused(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += Slot::query()
                ->whereIn('id', $chunk)
                ->where('booked', 0)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('reservations')
                    ->whereColumn('reservations.slot_id', 'slots.id'))
                ->delete();
        }

        return $deleted;
    }

    /**
     * @return array<string, array{slot_date: string, start_time: string, capacity: int}>
     */
    private function desiredSlots(Store $store): array
    {
        $from = today();
        $to = today()->addDays($store->advance_days);

        $hours = $store->businessHours()->get()->keyBy('weekday');
        $closed = $store->closedDates()
            ->whereBetween('closed_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('closed_date')
            ->mapWithKeys(fn (Carbon $date) => [$date->toDateString() => true]);

        $desired = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $hour = $hours->get($day->dayOfWeek); // Carbon: 0=週日，與 store_business_hours.weekday 一致

            if ($hour === null || $hour->is_closed || $closed->has($date)) {
                continue;
            }

            $open = $this->minutes($hour->open_time);
            $close = $this->minutes($hour->close_time);

            // 以「開始時間」早於打烊時間為準：最後一個時段可在打烊前開始
            for ($m = $open; $m < $close; $m += $hour->slot_minutes) {
                $time = sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
                $desired[$this->key($date, $time)] = [
                    'slot_date' => $date,
                    'start_time' => $time,
                    'capacity' => $store->slot_capacity_default,
                ];
            }
        }

        return $desired;
    }

    private function key(string $date, string $time): string
    {
        return $date.' '.substr($time, 0, 5);
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
