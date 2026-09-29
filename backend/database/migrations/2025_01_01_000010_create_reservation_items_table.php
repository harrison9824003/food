<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('unit_price'); // 下單當下價格快照，之後改價不影響舊訂單
            $table->timestamps();

            $table->unique(['reservation_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_items');
    }
};
