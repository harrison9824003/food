<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0=週日 ... 6=週六
            $table->time('open_time');
            $table->time('close_time');
            $table->unsignedSmallInteger('slot_minutes')->default(30); // 時段間隔
            $table->boolean('is_closed')->default(false);               // 每週固定公休
            $table->timestamps();

            $table->unique(['store_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_business_hours');
    }
};
