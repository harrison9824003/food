<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);

            // 開放規則
            $table->unsignedSmallInteger('advance_days')->default(30);          // 提前幾天開放訂位
            $table->unsignedSmallInteger('cutoff_minutes')->default(60);        // 距開始時間幾分鐘前截止
            $table->unsignedSmallInteger('slot_capacity_default')->default(20); // 每時段預設上限
            $table->unsignedInteger('deposit_per_person')->default(0);          // 每人訂金（0 = 免訂金）

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
