<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained();
            $table->string('merchant_trade_no', 20)->unique(); // 我方送給綠界的訂單編號
            // 綠界交易編號：背景通知冪等的依據。尚未付款時為 NULL（UNIQUE 允許多個 NULL），有值則唯一。
            $table->string('ecpay_trade_no', 20)->nullable()->unique();
            $table->unsignedInteger('amount');
            $table->string('status', 20)->default(PaymentStatus::Pending->value);
            $table->json('raw_notify')->nullable(); // 最近一次背景通知原文，供對帳與排錯
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']); // 對帳排程掃描 pending
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
