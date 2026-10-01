<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\User;

/**
 * 後台訂單權限：
 *  - 總部管理員：所有門市
 *  - 店長、店員：只能看與處理自己門市的訂單（店員負責現場標記已到／未到，所以可以變更狀態）
 */
class ReservationPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function updateStatus(User $actor, Reservation $reservation): bool
    {
        return $actor->role === UserRole::HqAdmin
            || ($actor->store_id !== null && $actor->store_id === $reservation->store_id);
    }
}
