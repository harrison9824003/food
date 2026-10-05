<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 價格已經快照了，品名也要：門市之後把「牛排」改名，已成立的訂位仍應顯示當時預點的名稱。
     */
    public function up(): void
    {
        Schema::table('reservation_items', function (Blueprint $table) {
            $table->string('name', 100)->default('')->after('menu_item_id');
        });

        DB::table('reservation_items')
            ->join('menu_items', 'menu_items.id', '=', 'reservation_items.menu_item_id')
            ->update(['reservation_items.name' => DB::raw('menu_items.name')]);
    }

    public function down(): void
    {
        Schema::table('reservation_items', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
