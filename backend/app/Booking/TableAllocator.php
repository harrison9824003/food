<?php

namespace App\Booking;

use App\Exceptions\ApiException;
use App\Models\DiningTable;
use App\Models\Slot;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 替一筆訂位挑桌子。
 *
 * 挑法：
 *  1. 有單桌坐得下（min_seats <= 人數 <= max_seats）就選「最小的那張」，不浪費大桌。
 *  2. 沒有單桌就併桌：只用標記「可併桌」的桌子，由大到小湊到座位數足夠，再把用不到的小桌拿掉。
 */
class TableAllocator
{
    private const MAX_ATTEMPTS = 3;

    /**
     * 挑桌並上鎖，必須在交易內呼叫。
     *
     * 為什麼「先挑、再鎖、再驗證」：挑桌用的是一般讀取，讀完到鎖定之間，別的交易可能剛好把同一張桌子訂走。
     * 鎖定後用「目前讀」（FOR UPDATE）重新確認這些桌子在該時段仍然空著，不空就重挑。
     *
     * 鎖定桌子時一律依 table_id 由小到大。併桌會同時鎖多張桌子，如果兩個交易各自以不同順序鎖同一批桌子
     * （A 先鎖 1 再鎖 2、B 先鎖 2 再鎖 1），就會形成循環等待造成死鎖；大家都由小到大，就不會有循環。
     *
     * @return list<int> 由小到大排序的桌子 ID
     *
     * @throws ApiException 沒有合適的桌子
     */
    public function allocate(Slot $slot, int $guests): array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $plan = $this->plan($this->freeTables($slot->store_id, $slot->id), $guests);

            if ($plan === null) {
                break;
            }

            $ids = collect($plan)->pluck('id')->sort()->values()->all();

            DiningTable::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);

            $alreadyTaken = DB::table('reservation_tables')
                ->where('slot_id', $slot->id)
                ->whereIn('table_id', $ids)
                ->lockForUpdate()
                ->exists();

            if (! $alreadyTaken) {
                return $ids;
            }
        }

        throw new ApiException('該時段已沒有適合的桌位', 'NO_TABLE_AVAILABLE', 409);
    }

    /**
     * 一次判斷多個時段能不能安排座位（供查詢可訂時段用，不上鎖）。
     *
     * @param  Collection<int, Slot>  $slots
     * @return array<int, bool> slot_id => 是否坐得下
     */
    public function canSeatMany(Store $store, Collection $slots, int $guests): array
    {
        if ($slots->isEmpty()) {
            return [];
        }

        $tables = DiningTable::query()->where('store_id', $store->id)->where('is_active', true)->orderBy('id')->get();

        $occupied = DB::table('reservation_tables')
            ->whereIn('slot_id', $slots->pluck('id'))
            ->get(['slot_id', 'table_id'])
            ->groupBy('slot_id')
            ->map(fn (Collection $rows) => $rows->pluck('table_id')->all());

        $result = [];
        foreach ($slots as $slot) {
            $taken = $occupied->get($slot->id, []);
            $result[$slot->id] = $this->plan($tables->reject(fn (DiningTable $t) => in_array($t->id, $taken, true)), $guests) !== null;
        }

        return $result;
    }

    /**
     * 該門市在該時段尚未被佔用的啟用桌位。
     *
     * @return Collection<int, DiningTable>
     */
    public function freeTables(int $storeId, int $slotId): Collection
    {
        return DiningTable::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereNotIn('id', fn ($q) => $q->select('table_id')->from('reservation_tables')->where('slot_id', $slotId))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, DiningTable>  $free
     * @return list<DiningTable>|null 找不到合適組合回傳 null
     */
    public function plan(Collection $free, int $guests): ?array
    {
        $single = $free
            ->filter(fn (DiningTable $t) => $t->min_seats <= $guests && $guests <= $t->max_seats)
            ->sortBy([['max_seats', 'asc'], ['id', 'asc']])
            ->first();

        if ($single !== null) {
            return [$single];
        }

        $chosen = [];
        $seats = 0;
        $combinable = $free->where('is_combinable', true)->sortBy([['max_seats', 'desc'], ['id', 'asc']]);

        foreach ($combinable as $table) {
            if ($seats >= $guests) {
                break;
            }
            $chosen[] = $table;
            $seats += $table->max_seats;
        }

        if ($seats < $guests) {
            return null;
        }

        // 由小到大檢查，拿掉拿掉之後座位仍然足夠的桌子，避免多併不必要的桌
        usort($chosen, fn (DiningTable $a, DiningTable $b) => [$a->max_seats, $a->id] <=> [$b->max_seats, $b->id]);
        foreach ($chosen as $i => $table) {
            if ($seats - $table->max_seats >= $guests) {
                $seats -= $table->max_seats;
                unset($chosen[$i]);
            }
        }

        return array_values($chosen);
    }
}
