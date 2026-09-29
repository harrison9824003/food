<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_closed_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('closed_date');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'closed_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_closed_dates');
    }
};
