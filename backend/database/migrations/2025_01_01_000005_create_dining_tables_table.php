<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 不叫 tables，避免與 SQL 保留字／Eloquent 慣例混淆
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20); // 桌號
            $table->unsignedTinyInteger('min_seats')->default(1);
            $table->unsignedTinyInteger('max_seats');
            $table->boolean('is_combinable')->default(false); // 可否併桌
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
