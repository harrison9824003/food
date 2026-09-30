<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Slot;
use App\Models\Store;
use App\Models\StoreClosedDate;
use Illuminate\Support\Facades\DB;

class ClosedDateService
{
    public function __construct(private readonly SlotGenerator $slots) {}

    /**
     * 設定公休日。該日若已有人訂位則拒絕，由管理員先處理（取消或改期）那些訂位。
     *
     * @throws ApiException
     */
    public function close(Store $store, string $date, ?string $reason): StoreClosedDate
    {
        return DB::transaction(function () use ($store, $date, $reason) {
            // 先鎖住該日所有時段列再檢查。訂位是靠條件式 UPDATE 佔名額，會對時段列加鎖；
            // 這裡持有同一批列的鎖，「檢查有沒有人訂」到「寫入公休日」之間就不會有訂位插進來，
            // 否則可能出現「剛設成公休、卻同時多了一筆訂位」。
            $slots = Slot::query()
                ->where('store_id', $store->id)
                ->where('slot_date', $date)
                ->lockForUpdate()
                ->get(['id', 'booked']);

            $bookedGuests = $slots->sum('booked');
            if ($bookedGuests > 0) {
                throw new ApiException(
                    "該日已有 {$bookedGuests} 位客人訂位，請先處理這些訂位再設為公休",
                    'CLOSED_DATE_HAS_RESERVATIONS',
                    409,
                );
            }

            $closed = $store->closedDates()->create(['closed_date' => $date, 'reason' => $reason]);

            // 沒人訂的時段直接移除，不再對外開放
            $this->slots->deleteUnused($slots->pluck('id')->all());

            return $closed;
        });
    }

    /** 取消公休日，並把該日時段補回來。 */
    public function reopen(Store $store, StoreClosedDate $closedDate): void
    {
        $closedDate->delete();
        $this->slots->sync($store);
    }
}
