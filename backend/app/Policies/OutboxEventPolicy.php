<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\OutboxEvent;
use App\Models\User;

/**
 * ERP 同步（失敗清單、手動重送）：
 *  - 總部管理員：所有門市
 *  - 店長：自己門市
 *  - 店員：不可（排錯與重送是管理動作；現場人員不需要也不該動）
 */
class OutboxEventPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role !== UserRole::Staff;
    }

    public function retry(User $actor, OutboxEvent $event): bool
    {
        return match ($actor->role) {
            UserRole::HqAdmin => true,
            UserRole::StoreManager => $actor->store_id !== null && $actor->store_id === $event->store_id,
            UserRole::Staff => false,
        };
    }
}
