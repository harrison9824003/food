<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // 後台依門市限縮；不放外鍵（紀錄不該因為門市被刪除而連帶消失）
            $table->unsignedBigInteger('store_id')->nullable()->after('reservation_id');
            // 為什麼退款：CUSTOMER_CANCEL、STORE_CANCEL，或付款端標記的 LATE_PAYMENT_SLOT_TAKEN、DUPLICATE_PAYMENT…
            $table->string('reason', 40)->after('amount');
            // 誰申請的：customer（顧客自己取消）、system（付款流程偵測到多收）、staff（後台人員）。
            // 簽核時要確認「簽核的人不是申請的人」，所以後台人員申請時要記下是誰
            $table->string('requested_by_type', 12)->after('reason');
            $table->unsignedBigInteger('requested_by_id')->nullable()->after('requested_by_type');
            $table->string('reject_reason')->nullable()->after('approved_at');
            $table->unsignedTinyInteger('attempts')->default(0)->after('failure_reason');
            $table->timestamp('next_attempt_at')->nullable()->after('attempts');
            $table->timestamp('completed_at')->nullable()->after('next_attempt_at');

            // 同一筆付款同時只能有一筆「有效」的退款（申請中、已核准、已完成、失敗待處理）。
            // 有效時存付款 ID、被駁回時設為 NULL；UNIQUE 允許多個 NULL。
            // 這是防止重複退款的最後一道防線：應用層的檢查萬一有競態，資料庫也會拒絕第二筆。
            $table->unsignedBigInteger('active_payment_id')->nullable()->after('payment_id');
            $table->unique('active_payment_id');
            $table->index(['store_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropIndex(['store_id', 'status']);
            $table->dropUnique(['active_payment_id']);
            $table->dropColumn([
                'store_id', 'reason', 'requested_by_type', 'requested_by_id', 'reject_reason',
                'attempts', 'next_attempt_at', 'completed_at', 'active_payment_id',
            ]);
        });
    }
};
