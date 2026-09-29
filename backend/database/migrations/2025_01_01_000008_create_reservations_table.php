<?php

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // 對顧客顯示的訂位代號
            $table->foreignId('store_id')->constrained();
            $table->foreignId('slot_id')->constrained();

            $table->string('customer_name', 50);
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('party_size');
            $table->text('note')->nullable();

            $table->string('status', 20)->default(ReservationStatus::PendingPayment->value);
            $table->string('payment_status', 20)->default(PaymentStatus::Unpaid->value);
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->timestamp('hold_expires_at')->nullable(); // 暫時保留到期時間（建立後 10 分鐘）
            $table->timestamp('phone_verified_at')->nullable();

            $table->string('request_id', 36)->nullable(); // 建立此訂位的請求，供日誌追查
            $table->timestamps();

            // Scheduler 每分鐘掃描逾時保留：WHERE status = 'pending_payment' AND hold_expires_at < now()
            $table->index(['status', 'hold_expires_at']);
            $table->index('phone'); // 「我的訂位」查詢
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
