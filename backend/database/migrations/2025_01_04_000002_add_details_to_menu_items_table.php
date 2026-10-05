<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            // 分類被刪除時品項不跟著消失，回到「未分類」
            $table->foreignId('category_id')->nullable()->after('store_id')->constrained('menu_categories')->nullOnDelete();
            $table->string('description', 500)->nullable()->after('name');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('price');
            // 暫時售完：仍顯示在菜單上但不可預點。和 is_active（下架，完全不顯示）是兩回事
            $table->boolean('is_sold_out')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['description', 'sort_order', 'is_sold_out']);
        });
    }
};
