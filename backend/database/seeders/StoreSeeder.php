<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        Store::updateOrCreate(['name' => '信義店'], [
            'address' => '台北市信義區松高路 1 號',
            'phone' => '0227000001',
            'deposit_per_person' => 200,
        ]);

        Store::updateOrCreate(['name' => '台中店'], [
            'address' => '台中市西區公益路 100 號',
            'phone' => '0422000002',
            'deposit_per_person' => 0,
        ]);
    }
}
