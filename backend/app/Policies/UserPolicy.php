<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * 帳號管理權限：
 *  - 總部管理員：可管理任何角色、任何門市的帳號
 *  - 店長：只能管理「自己門市的店員」
 *  - 店員：不可管理帳號
 *
 * 刻意不用 Gate::before 讓總部無條件放行，每條規則都明確寫在這裡，方便測試與說明。
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role !== UserRole::Staff;
    }

    /**
     * $target 為尚未儲存的新帳號（已填入要建立的 role / store_id）。
     */
    public function create(User $actor, User $target): bool
    {
        return $this->manages($actor, $target->role, $target->store_id);
    }

    /**
     * 呼叫前 $target 已 fill 新值但尚未 save，因此「修改前」與「修改後」的狀態都要檢查：
     * 只檢查修改後，店長可把別間店的店員改到自己店來偷走；
     * 只檢查修改前，店長可把自己店的店員升成店長（越權）。
     */
    public function update(User $actor, User $target): bool
    {
        $originalRole = $target->getOriginal('role');
        $originalStoreId = $target->getOriginal('store_id');

        if (! $this->manages($actor, $originalRole, $originalStoreId)) {
            return false;
        }

        if (! $this->manages($actor, $target->role, $target->store_id)) {
            return false;
        }

        // 防止自己把自己鎖死（停用自己、改自己角色），否則可能整個系統沒人能管理
        if ($actor->is($target) && (! $target->is_active || $target->role !== $originalRole)) {
            return false;
        }

        return true;
    }

    private function manages(User $actor, UserRole $role, ?int $storeId): bool
    {
        return match ($actor->role) {
            UserRole::HqAdmin => true,
            UserRole::StoreManager => $role === UserRole::Staff
                && $storeId !== null
                && $storeId === $actor->store_id,
            UserRole::Staff => false,
        };
    }
}
