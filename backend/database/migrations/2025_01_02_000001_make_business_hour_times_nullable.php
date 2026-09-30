<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 公休日不需要營業時間；原本 NOT NULL 會逼我們塞假時間（如 00:00），
     * 之後很容易被誤當成真的營業時段，改為可為 NULL。
     */
    public function up(): void
    {
        Schema::table('store_business_hours', function (Blueprint $table) {
            $table->time('open_time')->nullable()->change();
            $table->time('close_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('store_business_hours', function (Blueprint $table) {
            $table->time('open_time')->nullable(false)->change();
            $table->time('close_time')->nullable(false)->change();
        });
    }
};
