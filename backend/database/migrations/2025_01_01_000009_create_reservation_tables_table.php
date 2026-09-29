<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('dining_tables');
            // 冗餘存 slot_id，才能用 UNIQUE 在 DB 層保證「同桌同時段只能被一張有效訂位佔用」。
            // 訂位取消／逾時釋放時必須刪除本表對應列，否則會擋住之後的訂位。
            $table->foreignId('slot_id')->constrained();
            $table->timestamps();

            $table->unique(['table_id', 'slot_id']);
            $table->unique(['reservation_id', 'table_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_tables');
    }
};
