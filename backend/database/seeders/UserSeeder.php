<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 示範帳號（僅供本機／Demo 使用，密碼皆為 password）。
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $xinyi = Store::where('name', '信義店')->firstOrFail();

        $accounts = [
            ['hq@example.com', '總部管理員', UserRole::HqAdmin, null],
            ['manager@example.com', '信義店店長', UserRole::StoreManager, $xinyi->id],
            ['staff@example.com', '信義店店員', UserRole::Staff, $xinyi->id],
        ];

        foreach ($accounts as [$email, $name, $role, $storeId]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'store_id' => $storeId,
                'is_active' => true,
            ]);
        }
    }
}
