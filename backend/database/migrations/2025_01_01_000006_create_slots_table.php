<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('slot_date');
            $table->time('start_time');
            $table->unsignedSmallInteger('capacity');           // 該時段可訂人數上限
            $table->unsignedSmallInteger('booked')->default(0); // 已訂人數（由條件式原子 UPDATE 維護）
            $table->timestamps();

            $table->unique(['store_id', 'slot_date', 'start_time']);
        });

        // 防超賣最後防線：即使應用層有 bug，DB 也拒絕 booked > capacity。
        // booked 為 UNSIGNED，同時擋掉扣成負數。
        DB::statement('ALTER TABLE slots ADD CONSTRAINT chk_slots_booked_le_capacity CHECK (booked <= capacity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
