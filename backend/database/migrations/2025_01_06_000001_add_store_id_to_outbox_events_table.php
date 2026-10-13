<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            // 後台「同步失敗清單」要依門市限縮（店長只看得到自己門市的事件），所以事件本身要記下門市。
            // 不放外鍵：outbox 是紀錄，不該因為門市被刪除而連帶消失或阻擋刪除。
            $table->unsignedBigInteger('store_id')->nullable()->after('aggregate_id');
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status']);
            $table->dropColumn('store_id');
        });
    }
};
