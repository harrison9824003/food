<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Refund;
use App\Models\User;

/**
 * 退款簽核：
 *  - 總部管理員：所有門市
 *  - 店長：自己門市
 *  - 店員：不可。店員可以在後台取消已付訂金的訂位（那會建立退款「申請」），但放行（簽核）這筆錢的是主管——職責分離
 *
 * 另外「申請人不能簽核自己的申請」由 RefundService 檢查。
 */
class RefundPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role !== UserRole::Staff;
    }

    public function decide(User $actor, Refund $refund): bool
    {
        return match ($actor->role) {
            UserRole::HqAdmin => true,
            UserRole::StoreManager => $actor->store_id !== null && $actor->store_id === $refund->store_id,
            UserRole::Staff => false,
        };
    }
}
