<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Store;
use App\Models\User;

/**
 * 門市與門市設定（營業時間、公休日、桌位、時段名額）的權限：
 *  - 總部管理員：所有門市
 *  - 店長：自己門市的設定（名稱、訂金、停用除外）
 *  - 店員：自己門市，唯讀
 *
 * 與 UserPolicy 一樣不使用 Gate::before，每條規則明確寫出。
 */
class StorePolicy
{
    /** 進入門市列表；實際看得到哪些門市由 Controller 依角色限縮。 */
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Store $store): bool
    {
        return $actor->role === UserRole::HqAdmin || $this->isOwnStore($actor, $store);
    }

    public function create(User $actor): bool
    {
        return $actor->role === UserRole::HqAdmin;
    }

    /** 修改門市的地址、電話與開放規則；以及營業時間、公休日、桌位、時段名額。 */
    public function manageSettings(User $actor, Store $store): bool
    {
        return match ($actor->role) {
            UserRole::HqAdmin => true,
            UserRole::StoreManager => $this->isOwnStore($actor, $store),
            UserRole::Staff => false,
        };
    }

    /** 修改 Store::HQ_ONLY_FIELDS（名稱、訂金、停用）。 */
    public function updateCore(User $actor, Store $store): bool
    {
        return $actor->role === UserRole::HqAdmin;
    }

    private function isOwnStore(User $actor, Store $store): bool
    {
        return $actor->store_id !== null && $actor->store_id === $store->id;
    }
}
